<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Novo Pix para pedido do site que perdeu o prazo do Pix (pedido do Lira
 * 2026-10-23, pedidos 3375 e 3380): a Manuela oferece no WhatsApp um Pix que
 * vale 24 h e o cliente paga pela página do rastreio, sem login.
 *
 * Reabre o MESMO pedido (mesmo número e mesmo total, já com o desconto do
 * Pix), reserva o estoque de novo como o checkout faz (movimento de venda
 * com referência ao pedido e `stock_restored_at` zerado, para uma nova
 * expiração devolver uma vez só) e cria um Pix novo no Mercado Pago. O Pix
 * antigo fica marcado como substituído e não trava a confirmação.
 *
 * A confirmação segue o fluxo normal: varredura do orders:expire-abandoned
 * (a cada 5 min), a página do rastreio consultando enquanto está aberta e o
 * webhook do tópico "payment", se ligado. Pago, o OrderPaymentFinalizer faz
 * o resto (WhatsApp de pedido aprovado, nota, Purchase da Meta).
 */
class NovoPixDoPedido
{
    public const PAGO = 'pago';

    public const PENDENTE = 'pendente';

    public function __construct(
        private readonly MercadoPagoPaymentService $mercadoPago,
        private readonly StockManager $stock,
        private readonly OrderPaymentFinalizer $finalizer,
    ) {
    }

    /** Pix de vencimento longo ainda pagável do pedido, se houver. */
    public static function pixAberto(Order $order): ?Payment
    {
        if (! in_array($order->status, [Order::STATUS_AWAITING_PAYMENT, Order::STATUS_PENDING], true)) {
            return null;
        }

        return $order->payments->sortByDesc('id')->first(fn (Payment $payment) => $payment->pixAberto());
    }

    /** Motivo para não oferecer um novo Pix a este pedido, ou null se pode. */
    public function recusa(Order $order): ?string
    {
        $order->loadMissing('payments');

        if ($order->origin !== Order::ORIGIN_STORE) {
            return 'pedido de marketplace ('.$order->origin.'): quem recebe é o canal';
        }

        if ($order->payments->contains(fn (Payment $p) => in_array($p->status, [Payment::STATUS_CAPTURED, Payment::STATUS_AUTHORIZED, Payment::STATUS_REFUNDED], true))) {
            return 'pedido já tem pagamento aprovado ou estornado';
        }

        $aguardando = in_array($order->status, [Order::STATUS_AWAITING_PAYMENT, Order::STATUS_PENDING], true);
        $pixVencido = $order->status === Order::STATUS_CANCELLED
            && $order->motivo_cancelamento === SituacaoDoPagamento::MOTIVO_PIX_EXPIRADO;

        if (! $aguardando && ! $pixVencido) {
            return 'status "'.$order->status.'": só pedido cancelado por Pix vencido ou aguardando pagamento';
        }

        if (! $order->payments->contains('method_type', Payment::METHOD_PIX)) {
            return 'o pedido não foi feito no Pix (o total tem o desconto do Pix só quando o cliente escolheu Pix)';
        }

        if ((float) $order->total <= 0) {
            return 'pedido sem valor a pagar';
        }

        if (! $order->user) {
            return 'pedido sem cliente cadastrado';
        }

        return null;
    }

    /**
     * Itens sem estoque para reservar de novo. Vazio = tudo certo. Pedido
     * ainda aguardando com o estoque reservado não precisa de unidade nova.
     *
     * @return array<int, string>
     */
    public function faltaEstoque(Order $order, bool $travar = false): array
    {
        if (! $this->precisaReservar($order)) {
            return [];
        }

        $order->loadMissing('items');
        $porProduto = $order->items->filter(fn ($item) => $item->product_id)->groupBy('product_id');
        $consulta = Product::query()->whereIn('id', $porProduto->keys());
        $produtos = ($travar ? $consulta->lockForUpdate() : $consulta)->get()->keyBy('id');
        $falta = [];

        foreach ($porProduto as $produtoId => $itens) {
            $quantidade = (int) $itens->sum('quantity');
            $produto = $produtos->get($produtoId);
            $disponivel = (int) ($produto?->stock ?? 0);

            if ($disponivel < $quantidade) {
                $falta[] = $itens->first()->product_name.": precisa de {$quantidade}, tem {$disponivel}";
            }
        }

        return $falta;
    }

    /**
     * Reabre o pedido e gera o Pix novo. Lança RuntimeException se o pedido
     * não puder (recusa, falta de estoque, Pix antigo pago).
     */
    public function gerar(Order $order, int $horas = 24): Payment
    {
        $order->loadMissing(['payments', 'items', 'user']);

        if ($motivo = $this->recusa($order)) {
            throw new RuntimeException($motivo);
        }

        // Pix antigo ainda pendente no Mercado Pago (pedido aguardando):
        // pago? Então não é caso de Pix novo. Pendente: cancela lá antes, para
        // o cliente não pagar dois.
        foreach ($order->payments as $antigo) {
            if ($antigo->provider !== Payment::PROVIDER_MERCADOPAGO
                || $antigo->status !== Payment::STATUS_REQUIRES_CONFIRMATION
                || ! $antigo->mercadopago_payment_id) {
                continue;
            }

            $status = $this->mercadoPago->retrievePayment($antigo->mercadopago_payment_id)['status'] ?? null;

            if (in_array($status, ['pending', 'in_process', 'authorized'], true)) {
                $status = $this->mercadoPago->cancelPayment($antigo->mercadopago_payment_id)['status'] ?? null;
            }

            if ($status === 'approved') {
                throw new RuntimeException('o Pix anterior já está pago no Mercado Pago: confirme o pedido em vez de gerar outro');
            }

            if (in_array($status, ['cancelled', 'rejected'], true)) {
                $antigo->update(['status' => Payment::STATUS_CANCELED]);
            }
        }

        return DB::transaction(function () use ($order, $horas) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $order->load(['payments', 'items.product', 'user']);

            if ($motivo = $this->recusa($order)) {
                throw new RuntimeException($motivo);
            }

            if ($falta = $this->faltaEstoque($order, travar: true)) {
                throw new RuntimeException('sem estoque para reabrir: '.implode('; ', $falta));
            }

            if ($this->precisaReservar($order)) {
                foreach ($order->items as $item) {
                    if (! $item->product) {
                        continue;
                    }

                    $this->stock->adjust(
                        $item->product,
                        -(int) $item->quantity,
                        StockMovement::TYPE_SALE,
                        reason: "Pedido #{$order->id} reaberto com novo Pix",
                        reference: $order,
                    );
                }
            }

            $agora = now();
            $order->payments
                ->filter(fn (Payment $p) => $p->substituido_em === null)
                ->each(fn (Payment $p) => $p->forceFill(['substituido_em' => $agora])->save());

            $chave = (string) Str::uuid();
            $mp = $this->mercadoPago->createPixPayment(
                round((float) $order->total, 2),
                $this->pagador($order),
                "order-{$order->id}-novo-pix-".$agora->format('YmdHis'),
                "order:{$order->id}:novo-pix:{$chave}",
                $horas * 60,
            );
            $qr = $mp['point_of_interaction']['transaction_data'] ?? [];

            if (empty($mp['id']) || empty($qr['qr_code'])) {
                throw new RuntimeException('o Mercado Pago não devolveu o QR Code do Pix');
            }

            $payment = Payment::create([
                'order_id' => $order->id,
                'provider' => Payment::PROVIDER_MERCADOPAGO,
                'mercadopago_payment_id' => (string) $mp['id'],
                'method_type' => Payment::METHOD_PIX,
                'amount' => round((float) $order->total, 2),
                'status' => Payment::STATUS_REQUIRES_CONFIRMATION,
                'expira_em' => ! empty($mp['date_of_expiration']) ? Carbon::parse($mp['date_of_expiration']) : $agora->copy()->addHours($horas),
                'pix_copia_e_cola' => (string) $qr['qr_code'],
                'pix_qr_base64' => $qr['qr_code_base64'] ?? null,
            ]);

            $order->forceFill([
                'status' => Order::STATUS_AWAITING_PAYMENT,
                'cancelado_em' => null,
                'motivo_cancelamento' => null,
                'cancelado_por' => null,
                'stock_restored_at' => null,
            ])->save();

            Log::channel('mercadopago')->info('pedidos.novo_pix.gerado', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'expira_em' => $payment->expira_em?->toIso8601String(),
            ]);

            return $payment;
        });
    }

    /**
     * Confere no Mercado Pago um Pix de vencimento longo. Aprovado: pagamento
     * capturado e pedido pago pelo fluxo normal. Cancelado/recusado lá: só
     * marca o pagamento; quem cancela o pedido é o orders:expire-abandoned.
     */
    public function conferir(Payment $payment): string
    {
        if ($payment->status !== Payment::STATUS_REQUIRES_CONFIRMATION || ! $payment->mercadopago_payment_id) {
            return $payment->status === Payment::STATUS_CAPTURED ? self::PAGO : self::PENDENTE;
        }

        $mp = $this->mercadoPago->retrievePayment($payment->mercadopago_payment_id);
        $status = $mp['status'] ?? null;

        if ($status === 'approved') {
            $payment->update(['status' => Payment::STATUS_CAPTURED]);
            $order = $payment->order()->with('payments')->first();

            if ($order && $this->finalizer->finalize($order)) {
                Log::channel('mercadopago')->info('pedidos.novo_pix.pago', ['order_id' => $order->id]);
            }

            return self::PAGO;
        }

        if (in_array($status, ['cancelled', 'rejected'], true)) {
            $payment->recusar($mp);
        }

        return self::PENDENTE;
    }

    /**
     * Cancelado (estoque devolvido) ou pendente depois de falha de pagamento:
     * reserva de novo. Aguardando com o estoque ainda preso: não.
     */
    private function precisaReservar(Order $order): bool
    {
        return $order->stock_restored_at !== null;
    }

    /** Mesmo pagador do checkout (CheckoutController::mercadoPagoPayer, com $pix). */
    private function pagador(Order $order): array
    {
        $user = $order->user;
        $cpf = preg_replace('/\D/', '', $user->cpf ?? '');
        $nome = preg_split('/\s+/', trim((string) $user->name), 2);

        return array_filter([
            'email' => str_replace('{pedido}', (string) $order->id, (string) config('loja.email_pagador_pix')),
            'first_name' => $nome[0] ?? $user->name,
            'last_name' => $nome[1] ?? $nome[0] ?? $user->name,
            'identification' => $cpf ? ['type' => 'CPF', 'number' => $cpf] : null,
        ]);
    }
}
