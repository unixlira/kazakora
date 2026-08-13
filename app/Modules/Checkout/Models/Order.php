<?php

namespace App\Modules\Checkout\Models;

use App\Models\User;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\InvoiceGenerationLog;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_PAID = 'paid';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const ORIGIN_STORE = 'loja';

    // Mesmo vocabulário de canal que o módulo de Marketplace já usa
    // (product_channel_listings.channel, marketplace_accounts.channel) —
    // não um conjunto paralelo, pra "canal" ser um conceito único no app
    // inteiro e um canal novo (ex: TikTok Shop de verdade) não precisar
    // de mapeamento nenhum entre os dois lados.
    public const ORIGIN_MERCADO_LIVRE = MarketplaceAccount::CHANNEL_MERCADO_LIVRE;

    public const ORIGIN_SHOPEE = MarketplaceAccount::CHANNEL_SHOPEE;

    public const ORIGIN_TIKTOK_SHOP = MarketplaceAccount::CHANNEL_TIKTOK_SHOP;

    public const ORIGIN_AMAZON = MarketplaceAccount::CHANNEL_AMAZON;

    public const ORIGIN_SHEIN = MarketplaceAccount::CHANNEL_SHEIN;

    // Pedido explícito 2026-08-09: emissão manual de nota fiscal (produto
    // ou serviço avulso) pelo novo menu /admin/notas-fiscais/emitir — não é
    // uma venda de canal nem do site, é só um jeito de ter um Order pra
    // pendurar a Invoice (a arquitetura inteira de NFe já é Order-based).
    // Não entra em nenhum dos switches/mapas de canal de marketplace
    // existentes (comporta-se como um canal desconhecido neles, igual
    // ORIGIN_STORE hoje em qualquer lugar que só trata canal de
    // marketplace de verdade).
    public const ORIGIN_MANUAL_INVOICE = 'nota_fiscal_avulsa';

    protected $fillable = [
        'user_id',
        'status',
        'origin',
        'external_order_id',
        'buyer_document',
        'disputed_at',
        'stock_restored_at',
        'packed_at',
        'shipping_method_id',
        'shipping_carrier_name',
        'shipping_name',
        'shipping_phone',
        'shipping_email',
        'shipping_whatsapp',
        'shipping_zip',
        'shipping_street',
        'shipping_number',
        'shipping_complement',
        'shipping_neighborhood',
        'shipping_city',
        'shipping_state',
        'subtotal',
        'shipping_cost',
        'coupon_code',
        'discount_amount',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'disputed_at' => 'datetime',
            'stock_restored_at' => 'datetime',
            'packed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function marketplaceClaims(): HasMany
    {
        return $this->hasMany(\App\Modules\Marketplace\Models\MarketplaceClaim::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function invoiceGenerationLogs(): HasMany
    {
        return $this->hasMany(InvoiceGenerationLog::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(OrderEmailLog::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function latestEmailLog(): HasOne
    {
        return $this->hasOne(OrderEmailLog::class)->latestOfMany(['created_at', 'id']);
    }

    public function channelShipment(): HasOne
    {
        return $this->hasOne(ChannelShipment::class);
    }

    public function channelFee(): HasOne
    {
        return $this->hasOne(OrderChannelFee::class);
    }

    public function fulfillmentEvents(): HasMany
    {
        return $this->hasMany(OrderFulfillmentEvent::class)->orderBy('created_at')->orderBy('id');
    }
}
