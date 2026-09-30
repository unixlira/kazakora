<?php

namespace App\Notifications;

use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use Illuminate\Notifications\Notification;

/**
 * BUG REAL 2026-09-29: o canal cancela a venda depois de a NF-e já estar
 * autorizada (OrderImportService::syncStatus()). Cancelar na SEFAZ é
 * irreversível e só vale até 24h da autorização — por isso o sistema NÃO
 * cancela sozinho: avisa os admins pra decidirem a tempo.
 */
class CancelledOrderWithAuthorizedInvoiceNotification extends Notification
{
    public function __construct(
        private readonly Order $order,
        private readonly Invoice $invoice,
        private readonly ?string $avisoDoCarrinho = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $prazo = $this->invoice->autorizada_em
            ? ' (autorizada em '.$this->invoice->autorizada_em->format('d/m H:i').', prazo até '.$this->invoice->autorizada_em->copy()->addHours(24)->format('d/m H:i').')'
            : '';

        // Carrinho do ML com outros pedidos ainda ativos: a nota é do
        // carrinho inteiro e NÃO deve ser cancelada (derrubaria a nota dos
        // itens que continuam vendidos) — ver PackDoPedido::avisoDeCancelamento().
        if ($this->avisoDoCarrinho) {
            return [
                'order_id' => $this->order->id,
                'invoice_id' => $this->invoice->id,
                'message' => "Pedido #{$this->order->id} cancelado no canal — NF-e nº {$this->invoice->numero} é do carrinho",
                'body' => $this->avisoDoCarrinho,
            ];
        }

        return [
            'order_id' => $this->order->id,
            'invoice_id' => $this->invoice->id,
            'message' => "Pedido #{$this->order->id} cancelado no canal com a NF-e nº {$this->invoice->numero} autorizada",
            'body' => "O {$this->order->origin} cancelou o pedido #{$this->order->id}, mas a NF-e nº {$this->invoice->numero} já está autorizada na SEFAZ{$prazo}. "
                .'Ela precisa ser cancelada em até 24h da autorização — o sistema não cancela sozinho, confira e cancele manualmente.',
        ];
    }
}
