<?php

namespace App\Modules\Checkout\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Cartão recusado pelo Mercado Pago na hora de pagar (2026-10-18). O pedido
 * é desfeito nesse caso, então a tentativa fica aqui e aparece na tela do
 * pedido que o mesmo cliente fizer em seguida.
 */
class PagamentoRecusado extends Model
{
    protected $table = 'pagamentos_recusados';

    /** Tentativas recusadas até este tempo antes do pedido aparecem nele. */
    public const JANELA_HORAS = 24;

    protected $fillable = [
        'site_origem',
        'user_id',
        'email',
        'pedido_desfeito_id',
        'metodo',
        'valor',
        'mercadopago_order_id',
        'mercadopago_payment_id',
        'status',
        'motivo_recusa',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
        ];
    }

    /** Tentativas do mesmo cliente (conta ou e-mail) nas horas antes do pedido. */
    public function scopeAntesDoPedido(Builder $query, Order $order): Builder
    {
        $email = mb_strtolower(trim((string) ($order->shipping_email ?: $order->user?->email)));

        if (! $order->user_id && $email === '') {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where(fn (Builder $quem) => $quem
                ->when($order->user_id, fn (Builder $q) => $q->orWhere('user_id', $order->user_id))
                ->when($email !== '', fn (Builder $q) => $q->orWhere('email', $email)))
            ->whereBetween('created_at', [$order->created_at->copy()->subHours(self::JANELA_HORAS), $order->created_at])
            ->orderBy('created_at');
    }
}
