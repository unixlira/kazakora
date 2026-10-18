<?php

namespace App\Services\MercadoPago\Exceptions;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\PagamentoRecusado;

/**
 * Cartão recusado na criação do pagamento. Lançada dentro da transação do
 * pedido (que é desfeita); guarda o necessário para registrar a tentativa
 * depois do rollback, com registrar() no catch.
 */
class PagamentoRecusadoException extends MercadoPagoException
{
    private array $tentativa;

    public function __construct(Order $order, ?User $user, string $metodo, float $valor, array $mpOrder, array $mpPayment)
    {
        parent::__construct('Pagamento no cartão recusado: '.($mpPayment['status_detail'] ?? 'motivo não informado pela operadora.'));

        $this->tentativa = [
            'site_origem' => $order->site_origem,
            'user_id' => $user?->id,
            'email' => mb_strtolower(trim((string) ($order->shipping_email ?: $user?->email))) ?: null,
            'pedido_desfeito_id' => $order->id,
            'metodo' => $metodo,
            'valor' => $valor,
            'mercadopago_order_id' => isset($mpOrder['id']) ? (string) $mpOrder['id'] : null,
            'mercadopago_payment_id' => isset($mpPayment['id']) ? (string) $mpPayment['id'] : null,
            'status' => $mpPayment['status'] ?? null,
            'motivo_recusa' => isset($mpPayment['status_detail']) ? mb_substr((string) $mpPayment['status_detail'], 0, 80) : null,
        ];
    }

    /** Chamar só depois do rollback: a conta de visitante criada no pedido some junto. */
    public function registrar(): void
    {
        $tentativa = $this->tentativa;

        if ($tentativa['user_id'] && ! User::query()->whereKey($tentativa['user_id'])->exists()) {
            $tentativa['user_id'] = null;
        }

        PagamentoRecusado::create($tentativa);
    }
}
