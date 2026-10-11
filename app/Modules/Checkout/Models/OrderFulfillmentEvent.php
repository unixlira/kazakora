<?php

namespace App\Modules\Checkout\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class OrderFulfillmentEvent extends Model
{
    public const STEP_WEBHOOK_RECEIVED = 'webhook_received';

    public const STEP_STOCK_UPDATED = 'stock_updated';

    public const STEP_INVOICE_ISSUED = 'invoice_issued';

    public const STEP_INVOICE_SUBMITTED = 'invoice_submitted';

    public const STEP_SHIPPING_CONFIRMED = 'shipping_confirmed';

    public const STEP_LABEL_GENERATED = 'label_generated';

    public const STEP_LABEL_PRINTED = 'label_printed';

    // Pedido explícito 2026-08-13: botão "Em preparação" -> "Embalado" no
    // KoraSync (ver Order::packed_at / DashboardAgentController::packOrder).
    public const STEP_ORDER_PACKED = 'order_packed';

    /** Bipado no KoraFlex: etiquetado, fechado e na área de coleta. */
    public const STEP_READY_FOR_PICKUP = 'ready_for_pickup';

    /** Entregue em mãos ao entregador do Flex — a hora que a caixa saiu daqui. */
    public const STEP_HANDED_TO_CARRIER = 'handed_to_carrier';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'order_id',
        'step',
        'status',
        'message',
        'context',
        'repeticoes',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'repeticoes' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Junta as repetições de uma linha do tempo (mesma regra do
     * OrderFulfillmentTimeline::record): evento igual ao último da mesma
     * etapa soma no contador dele. Serve pros registros antigos, gravados
     * antes do contador existir.
     *
     * @param  Collection<int, self>  $eventos  em ordem cronológica
     * @return array{0: Collection<int, self>, 1: list<int>}  [linhas que ficam, ids repetidos]
     */
    public static function compactar(Collection $eventos): array
    {
        $ficam = [];
        $ultimoPorEtapa = [];
        $repetidos = [];

        foreach ($eventos as $evento) {
            $ultimo = $ultimoPorEtapa[$evento->step] ?? null;

            if ($ultimo && $ultimo->status === $evento->status && $ultimo->message === $evento->message) {
                $ultimo->repeticoes += max(1, (int) $evento->repeticoes);
                $ultimo->updated_at = $evento->updated_at ?? $evento->created_at;
                $repetidos[] = $evento->id;

                continue;
            }

            $ficam[] = $ultimoPorEtapa[$evento->step] = $evento;
        }

        return [collect($ficam), $repetidos];
    }
}
