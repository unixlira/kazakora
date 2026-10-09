<?php

namespace App\Modules\Fiscal\Models;

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_SENT = 'sent';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DENIED = 'denied';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_ERROR = 'error';

    // Canal de origem já emite a própria NF-e (confirmado ao vivo pro
    // Mercado Livre, 2026-08-02 — chave de acesso real, CNPJ e CPF do
    // pedido batendo, emitida pelo "Faturador" deles) — Kazakora
    // deliberadamente não tenta emitir de novo pra não duplicar nota
    // fiscal da mesma venda. Ver InvoiceService::issue().
    public const STATUS_EXTERNAL = 'external';

    public const AMBIENTE_PRODUCAO = 'producao';

    public const AMBIENTE_HOMOLOGACAO = 'homologacao';

    // 'pedido' = fluxo normal, sempre tem order_id (automático ao pagar, ou
    // emissão manual pra um Order existente). 'sefaz' = trazida pela
    // sincronização de Distribuição DFe (ver NFeDistribuicaoService), sem
    // Order local correspondente — destinatário fica em destinatario_nome/
    // destinatario_documento em vez de vir de order.shipping_name/user.
    public const ORIGEM_PEDIDO = 'pedido';

    public const ORIGEM_SEFAZ = 'sefaz';

    protected $fillable = [
        'order_id',
        'origem',
        'destinatario_nome',
        'destinatario_documento',
        'nsu',
        'status',
        'ambiente',
        'serie',
        'numero',
        'valor_total',
        'chave_acesso',
        'protocolo_autorizacao',
        'autorizada_em',
        'motivo_rejeicao',
        'xml_path',
        'danfe_path',
        'protocolo_cancelamento',
        'motivo_cancelamento',
        'cancelada_em',
        'cancelamento_extemporaneo',
        'xml_cancelamento_path',
    ];

    public const JANELA_NORMAL = 'normal';

    public const JANELA_EXTEMPORANEA = 'extemporanea';

    public const JANELA_EXPIRADA = 'expirada';

    protected function casts(): array
    {
        return [
            'serie' => 'integer',
            'numero' => 'integer',
            'valor_total' => 'decimal:2',
            'autorizada_em' => 'datetime',
            'cancelada_em' => 'datetime',
            'cancelamento_extemporaneo' => 'boolean',
        ];
    }

    /**
     * Em que prazo de cancelamento a nota está agora (null se não está
     * autorizada). Ver config/nfe.php: até 24h normal, até 480h com multa,
     * depois só devolução.
     */
    public function janelaDeCancelamento(): ?string
    {
        if ($this->status !== self::STATUS_AUTHORIZED) {
            return null;
        }

        $horas = $this->autorizada_em ? $this->autorizada_em->diffInHours(now()) : 0;

        return match (true) {
            $horas < config('nfe.cancelamento_horas', 24) => self::JANELA_NORMAL,
            $horas < config('nfe.cancelamento_extemporaneo_horas', 480) => self::JANELA_EXTEMPORANEA,
            default => self::JANELA_EXPIRADA,
        };
    }

    /** Até quando dá pra cancelar sem multa e com multa. */
    public function prazosDeCancelamento(): array
    {
        return [
            'normal_ate' => $this->autorizada_em?->copy()->addHours((int) config('nfe.cancelamento_horas', 24)),
            'extemporaneo_ate' => $this->autorizada_em?->copy()->addHours((int) config('nfe.cancelamento_extemporaneo_horas', 480)),
        ];
    }

    /** Multa estimada do cancelamento fora do prazo: 1% do valor, mínimo 6 UFESPs. */
    public function multaCancelamentoExtemporaneo(): float
    {
        return round(max((float) $this->valor_total * 0.01, 6 * (float) config('nfe.ufesp')), 2);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
