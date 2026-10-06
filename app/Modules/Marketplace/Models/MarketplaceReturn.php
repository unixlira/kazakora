<?php

namespace App\Modules\Marketplace\Models;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Uma devolução ou reclamação de plataforma, do jeito que a equipe precisa
 * controlar (pedido do usuário 2026-10-06). Ver ReturnsSyncService.
 */
class MarketplaceReturn extends Model
{
    public const KIND_DEVOLUCAO = 'devolucao';

    public const KIND_RECLAMACAO = 'reclamacao';

    // Situação normalizada — a mesma régua pra todas as plataformas.
    public const AGUARDANDO_RESPOSTA = 'aguardando_resposta';

    public const EM_MEDIACAO = 'em_mediacao';

    public const AGUARDANDO_ENVIO = 'aguardando_envio';

    public const EM_TRANSITO = 'em_transito';

    public const ENTREGUE = 'entregue';

    public const CONFERIDA = 'conferida';

    public const ENCERRADA = 'encerrada';

    public const CANCELADA = 'cancelada';

    public const SITUACOES = [
        self::AGUARDANDO_RESPOSTA => 'Aguardando nossa resposta',
        self::EM_MEDIACAO => 'Em mediação',
        self::AGUARDANDO_ENVIO => 'Aguardando o comprador enviar',
        self::EM_TRANSITO => 'Produto a caminho',
        self::ENTREGUE => 'Entregue — falta conferir',
        self::CONFERIDA => 'Conferida',
        self::ENCERRADA => 'Encerrada',
        self::CANCELADA => 'Cancelada pelo comprador',
    ];

    /** Situações em que ainda há algo a fazer ou acompanhar. */
    public const EM_ABERTO = [self::AGUARDANDO_RESPOSTA, self::EM_MEDIACAO, self::AGUARDANDO_ENVIO, self::EM_TRANSITO, self::ENTREGUE];

    // Veredito de quem conferiu o produto que voltou.
    public const VEREDITOS = [
        'ok' => 'Voltou certo',
        'mau_uso' => 'Mau uso',
        'faltando_pecas' => 'Faltando peças',
        'danificado' => 'Danificado',
        'produto_errado' => 'Produto diferente do enviado',
        'nao_recebido' => 'Não recebemos o produto',
    ];

    protected $fillable = [
        'channel', 'external_id', 'order_id', 'external_order_id', 'kind', 'reason_code', 'reason_label',
        'situacao', 'platform_status', 'return_status', 'money_status', 'refund_at', 'refund_amount',
        'tracking_number', 'respond_due_at', 'receive_due_at', 'opened_at', 'delivered_at', 'closed_at',
        'resolution', 'received_at', 'received_by', 'verdict', 'verdict_note', 'verdict_at', 'verdict_by',
        'manual', 'alerts_notified', 'raw_payload', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'refund_amount' => 'decimal:2',
            'respond_due_at' => 'datetime',
            'receive_due_at' => 'datetime',
            'opened_at' => 'datetime',
            'delivered_at' => 'datetime',
            'closed_at' => 'datetime',
            'received_at' => 'datetime',
            'verdict_at' => 'datetime',
            'manual' => 'boolean',
            'alerts_notified' => 'array',
            'raw_payload' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MarketplaceReturnEvent::class)->orderByDesc('happened_at')->orderByDesc('id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function verdictBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verdict_by');
    }

    /**
     * Pendências que pedem ação — é isso que acende o número no menu.
     *
     * @return list<array{chave: string, nivel: string, texto: string}>
     */
    public function alertas(?Carbon $agora = null): array
    {
        $agora ??= now();
        $alertas = [];

        if ($this->situacao === self::AGUARDANDO_RESPOSTA && $this->respond_due_at) {
            if ($this->respond_due_at->lte($agora)) {
                $alertas[] = ['chave' => 'prazo_vencido', 'nivel' => 'erro', 'texto' => 'Prazo de resposta vencido'];
            } elseif ($this->respond_due_at->lte($agora->copy()->addDay())) {
                $alertas[] = ['chave' => 'prazo_24h', 'nivel' => 'aviso', 'texto' => 'Prazo de resposta vence em menos de 24h'];
            }
        } elseif ($this->situacao === self::AGUARDANDO_RESPOSTA) {
            $alertas[] = ['chave' => 'sem_resposta', 'nivel' => 'aviso', 'texto' => 'A plataforma espera uma resposta nossa'];
        }

        if ($this->situacao === self::EM_MEDIACAO) {
            $alertas[] = ['chave' => 'mediacao', 'nivel' => 'aviso', 'texto' => 'Em mediação na plataforma'];
        }

        if ($this->situacao === self::ENTREGUE && ! $this->verdict) {
            $vence = $this->receive_due_at && $this->receive_due_at->lte($agora->copy()->addDay());
            $alertas[] = ['chave' => 'conferir', 'nivel' => $vence ? 'erro' : 'aviso', 'texto' => $vence ? 'Entregue — prazo pra conferir acabando' : 'Entregue — falta conferir o produto'];
        }

        // O caso que já deu prejuízo: a plataforma encerrou (ou estornou o
        // comprador) e o produto nunca chegou de volta aqui.
        if ($this->kind === self::KIND_DEVOLUCAO && $this->encerradaSemProduto()) {
            $alertas[] = ['chave' => 'sem_produto', 'nivel' => 'erro', 'texto' => 'Encerrada sem o produto voltar'];
        }

        return $alertas;
    }

    public function encerradaSemProduto(): bool
    {
        if ($this->received_at || ($this->verdict && $this->verdict !== 'nao_recebido') || $this->situacao === self::CANCELADA) {
            return false;
        }

        if ($this->verdict === 'nao_recebido') {
            return true;
        }

        // Só conta como devolução de produto quando houve envio de volta
        // (rastreio ou status de retorno); reembolso sem devolução física é
        // outra coisa.
        $teveEnvio = $this->tracking_number || $this->return_status;

        return $teveEnvio && $this->situacao === self::ENCERRADA && $this->return_status !== 'delivered';
    }
}
