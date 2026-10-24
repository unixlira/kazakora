<?php

namespace App\Support;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Coupon;

/**
 * Cupom de boas-vindas (config loja.cupom_automatico, pedido do Lira
 * 2026-10-24): BEMVINDO de 3% em qualquer forma de pagamento. Desde o 2º
 * pedido do dia não entra sozinho: o pop-up do checkout oferece e o cliente
 * aplica ou fecha. Os 2% a mais do Pix são o "Desconto Checkout" do produto
 * (DescontoPix).
 */
class CupomAutomatico
{
    public static function codigo(): string
    {
        return Coupon::normalizar((string) config('loja.cupom_automatico'));
    }

    public static function eh(?string $codigo): bool
    {
        return self::codigo() !== '' && Coupon::normalizar($codigo) === self::codigo();
    }

    /** O cupom automático, se existe e vale para esta compra. */
    public static function para(float $subtotal, ?User $user = null, ?string $email = null): ?Coupon
    {
        $coupon = self::codigo() !== '' ? Coupon::buscar(self::codigo()) : null;

        return $coupon && ! $coupon->motivoInvalido($subtotal, $user, $email) ? $coupon : null;
    }

    /**
     * % que o cupom automático tira de um produto na vitrine (página de
     * produto, preço no Pix). Zero quando o cupom é em R$, tem pedido mínimo,
     * é pessoal, está fora do ar ou o produto está em oferta (cupom não vale
     * na Oferta do Dia nem na Relâmpago).
     */
    public static function percentualNaVitrine(?Product $produto = null): float
    {
        if ($produto?->emOferta()) {
            return 0.0;
        }

        $coupon = once(function () {
            $coupon = self::codigo() !== '' ? Coupon::buscar(self::codigo()) : null;

            return $coupon
                && $coupon->discount_type === Coupon::TYPE_PERCENTAGE
                && ! $coupon->user_id
                && ! (float) $coupon->min_order_value
                && ! $coupon->motivoInvalido(0)
                ? $coupon
                : null;
        });

        return $coupon ? (float) $coupon->discount_value : 0.0;
    }
}
