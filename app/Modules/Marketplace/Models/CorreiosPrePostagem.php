<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Checkout\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorreiosPrePostagem extends Model
{
    protected $table = 'correios_pre_postagens';

    public const STATUS_GERADA = 'gerada';

    public const STATUS_ERRO = 'erro';

    /** Cancelada nos Correios (ver CorreiosCancelamento) — não é mais etiqueta nem custo. */
    public const STATUS_CANCELADA = 'cancelada';

    /*
     * Situação nos Correios, lida do rastreio (correios:acompanhar-pre-postagens,
     * 2026-11-16). Independe do `status`: uma etiqueta "gerada" pode estar
     * pré-postada, postada, entregue, expirada ou devolvida.
     */
    public const SITUACAO_PRE_POSTADA = 'pre_postada';

    public const SITUACAO_POSTADA = 'postada';

    public const SITUACAO_ENTREGUE = 'entregue';

    public const SITUACAO_EXPIRADA = 'expirada';

    public const SITUACAO_DEVOLVIDA = 'devolvida';

    /** Rótulo e cor do selo (tom do StatusBadge). */
    public const SITUACOES = [
        self::SITUACAO_PRE_POSTADA => ['label' => 'Pré-postada', 'tone' => 'gray'],
        self::SITUACAO_POSTADA => ['label' => 'Postada', 'tone' => 'blue'],
        self::SITUACAO_ENTREGUE => ['label' => 'Entregue', 'tone' => 'green'],
        self::SITUACAO_EXPIRADA => ['label' => 'Expirada', 'tone' => 'red'],
        self::SITUACAO_DEVOLVIDA => ['label' => 'Suspensa/Devolvida', 'tone' => 'yellow'],
    ];

    protected $fillable = [
        'order_id',
        'created_by',
        'origin',
        'external_order_id',
        'customer_name',
        'customer_document',
        'customer_phone',
        'customer_email',
        'zip',
        'street',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        'service_code',
        'service_label',
        'postage_price',
        'weight_grams',
        'dimension_format',
        'dimension_height',
        'dimension_width',
        'dimension_length',
        'dimension_diameter',
        'content_items',
        'status',
        'correios_id',
        'codigo_objeto',
        'qr_payload',
        'raw_response',
        'error_message',
        'bling_informado_em',
        'amazon_confirmado_em',
        'devolucao',
        'rastreio_situacao',
        'rastreio_ultimo_evento',
        'rastreio_eventos',
        'postada_em',
        'entregue_em',
        'expirada_em',
        'rastreio_consultado_em',
    ];

    protected function casts(): array
    {
        return [
            'content_items' => 'array',
            'raw_response' => 'array',
            'bling_informado_em' => 'datetime',
            'amazon_confirmado_em' => 'datetime',
            'devolucao' => 'boolean',
            'rastreio_eventos' => 'array',
            'postada_em' => 'datetime',
            'entregue_em' => 'datetime',
            'expirada_em' => 'datetime',
            'rastreio_consultado_em' => 'datetime',
        ];
    }

    /**
     * Situação nos Correios para o selo. Só faz sentido em etiqueta gerada;
     * antes da primeira consulta do rastreio ela é "pré-postada".
     */
    public function situacaoNosCorreios(): ?string
    {
        if ($this->status !== self::STATUS_GERADA) {
            return null;
        }

        return $this->rastreio_situacao ?: self::SITUACAO_PRE_POSTADA;
    }

    /**
     * Filtro rápido "Expiradas / não postadas": etiqueta gerada que expirou
     * ou que os Correios ainda não viram passar pela agência.
     */
    public function scopeExpiradaOuNaoPostada(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('status'), self::STATUS_GERADA)
            ->where(fn (Builder $q) => $q
                ->whereNotNull($q->qualifyColumn('expirada_em'))
                ->orWhere(fn (Builder $nao) => $nao
                    ->whereNull($nao->qualifyColumn('postada_em'))
                    ->whereNull($nao->qualifyColumn('entregue_em'))
                    ->where(fn (Builder $s) => $s
                        ->whereNull($s->qualifyColumn('rastreio_situacao'))
                        ->orWhere($s->qualifyColumn('rastreio_situacao'), self::SITUACAO_PRE_POSTADA))));
    }

    /**
     * Só as de ENVIO do pedido (loja → cliente). A de devolução (cliente →
     * loja, pedido do Lira 2026-10-29) não é etiqueta, rastreio, frete pago
     * nem "pedido enviado".
     */
    public function scopeDeEnvio(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('devolucao'), false);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
