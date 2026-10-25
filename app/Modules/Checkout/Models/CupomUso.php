<?php

namespace App\Modules\Checkout\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quem já usou o cupom, pelo CPF/CNPJ (pedido do Lira 2026-10-25, cupom
 * BEMVINDO): gravado só quando o pagamento é confirmado, pelo webhook do
 * banco (OrderPaymentFinalizer). Pedido aberto ou Pix vencido não conta.
 */
class CupomUso extends Model
{
    protected $table = 'cupom_usos';

    protected $fillable = ['coupon_id', 'documento', 'order_id', 'user_id'];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** CPF (11) ou CNPJ (14) só com os números; vazio quando não é nenhum dos dois. */
    public static function documento(?string $valor): string
    {
        $numeros = preg_replace('/\D/', '', (string) $valor);

        return in_array(strlen($numeros), [11, 14], true) ? $numeros : '';
    }

    /**
     * Registra o cupom do pedido pago para o CPF/CNPJ de quem comprou. Uma
     * vez por documento e cupom; pedido sem cupom ou sem documento não grava.
     */
    public static function registrar(Order $order): ?self
    {
        $coupon = Coupon::buscar($order->coupon_code);
        $documento = self::documento($order->buyer_document ?: $order->user?->cpf);

        if (! $coupon || $documento === '') {
            return null;
        }

        return static::query()->firstOrCreate(
            ['coupon_id' => $coupon->id, 'documento' => $documento],
            ['order_id' => $order->id, 'user_id' => $order->user_id],
        );
    }
}
