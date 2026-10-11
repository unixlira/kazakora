<?php

namespace App\Modules\Checkout\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    /** Pedidos que contam como "cupom usado" (pagamento confirmado). */
    public const STATUS_USO = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED];

    protected $fillable = [
        'code',
        'name',
        'discount_type',
        'discount_value',
        'min_order_value',
        'max_uses',
        'one_per_customer',
        'starts_at',
        'expires_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'min_order_value' => 'decimal:2',
            'max_uses' => 'integer',
            'one_per_customer' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** Código sempre em maiúsculas e sem espaços. */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => self::normalizar($value));
    }

    public static function normalizar(?string $codigo): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', (string) $codigo));
    }

    public static function buscar(?string $codigo): ?self
    {
        $codigo = self::normalizar($codigo);

        return $codigo === '' ? null : static::query()->where('code', $codigo)->first();
    }

    public function disparos(): HasMany
    {
        return $this->hasMany(CouponDisparo::class);
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Order::class, 'coupon_code', 'code');
    }

    public function scopeComUsos(Builder $query): Builder
    {
        return $query->withCount(['pedidos as usos' => fn ($pedidos) => $pedidos->whereIn('status', self::STATUS_USO)]);
    }

    public function totalUsos(): int
    {
        return array_key_exists('usos', $this->attributes)
            ? (int) $this->attributes['usos']
            : $this->pedidos()->whereIn('status', self::STATUS_USO)->count();
    }

    /**
     * Por que o cupom não vale para esta compra — null quando vale.
     * Mensagens já no tom de quem lê no checkout.
     */
    public function motivoInvalido(float $subtotal, ?User $user = null, ?string $email = null): ?string
    {
        if (! $this->is_active) {
            return 'Este cupom não está mais ativo.';
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'Este cupom vale a partir de '.$this->starts_at->format('d/m/Y').'.';
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'Este cupom expirou em '.$this->expires_at->format('d/m/Y').'.';
        }
        if ($this->min_order_value && $subtotal < (float) $this->min_order_value) {
            return 'Este cupom vale para compras a partir de R$ '.number_format((float) $this->min_order_value, 2, ',', '.').'.';
        }
        if ($this->max_uses && $this->totalUsos() >= $this->max_uses) {
            return 'Este cupom já atingiu o limite de usos.';
        }
        if ($this->one_per_customer && ($user || $email)) {
            $jaUsou = $this->pedidos()
                ->whereIn('status', self::STATUS_USO)
                ->where(fn ($pedidos) => $pedidos
                    ->when($user, fn ($q) => $q->orWhere('user_id', $user->id))
                    ->when($email, fn ($q) => $q->orWhere('shipping_email', $email)))
                ->exists();
            if ($jaUsou) {
                return 'Você já usou este cupom em outra compra.';
            }
        }

        return null;
    }

    public function discountFor(float $subtotal): float
    {
        if ($this->discount_type === self::TYPE_PERCENTAGE) {
            return round($subtotal * ((float) $this->discount_value / 100), 2);
        }

        return min($subtotal, round((float) $this->discount_value, 2));
    }

    /** "10% de desconto" / "R$ 20,00 de desconto". */
    public function descricaoDesconto(): string
    {
        return $this->discount_type === self::TYPE_PERCENTAGE
            ? rtrim(rtrim(number_format((float) $this->discount_value, 2, ',', '.'), '0'), ',').'% de desconto'
            : 'R$ '.number_format((float) $this->discount_value, 2, ',', '.').' de desconto';
    }
}
