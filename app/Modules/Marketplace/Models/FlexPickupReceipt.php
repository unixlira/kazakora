<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um comprovante de entrega ao entregador do Flex: os pacotes que saíram
 * juntos, a hora, quem levou, a assinatura, a foto e o consentimento.
 *
 * Ver FlexPickupService::entregar() pra como é criado e
 * database/migrations/..._create_flex_pickup_receipts_table.php pro porquê
 * de cada coluna.
 */
class FlexPickupReceipt extends Model
{
    protected $fillable = [
        'carrier_name',
        'collected_at',
        'device',
        'consented_at',
        'consent_text',
        'signature_path',
        'photo_path',
        'order_ids',
        'orders_count',
    ];

    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'consented_at' => 'datetime',
            'order_ids' => 'array',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'pickup_receipt_id');
    }

    /** Assinatura e foto existem? É o que separa um recibo assinado de uma baixa simples. */
    public function assinado(): bool
    {
        return $this->signature_path !== null;
    }
}
