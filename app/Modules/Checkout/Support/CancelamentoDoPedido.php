<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Checkout\Mail\PedidoCancelado;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderEmailLog;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Modules\Fiscal\Support\PackDoPedido;
use App\Notifications\OrderStatusUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Cancelamento de pedido pelo admin (botão "Cancelar pedido", 2026-10-17).
 *
 * Junta num lugar só o que antes vivia em OrderController::update() quando
 * o status virava "cancelado": NF-e na SEFAZ, estorno no Mercado Pago ou
 * Stripe e devolução do estoque. O botão soma motivo, quem cancelou, e-mail
 * ao cliente do site e uma prévia do que vai acontecer antes do clique.
 */
class CancelamentoDoPedido
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly OrderPaymentFinalizer $finalizer,
        private readonly PackDoPedido $pack,
    ) {
    }

    /**
     * O que acontece se cancelar agora, em frases curtas para a tela.
     *
     * @return array{pode: bool, bloqueio: ?string, nota: string, pagamentos: list<string>, estoque: string, cliente: string, canal: ?string, pode_avisar_cliente: bool}
     */
    public function previa(Order $order): array
    {
        $order->loadMissing(['invoice', 'payments', 'items', 'user']);
        $canal = Order::rotuloDoCanal($order->origin, $order->site_origem);
        $doSite = $order->origin === Order::ORIGIN_STORE;
        $destinatario = $this->destinatario($order);

        return [
            'pode' => $order->status !== Order::STATUS_CANCELLED,
            'bloqueio' => $order->status === Order::STATUS_CANCELLED ? 'Este pedido já está cancelado.' : null,
            'nota' => $this->previaDaNota($order),
            'pagamentos' => $this->previaDosPagamentos($order),
            'estoque' => $this->previaDoEstoque($order),
            'cliente' => match (true) {
                ! $doSite => "O comprador é avisado pelo próprio {$canal}, não por nós.",
                $destinatario === null => 'O cliente não tem e-mail cadastrado: não dá para avisar por e-mail.',
                default => "O cliente recebe um e-mail avisando do cancelamento ({$destinatario}) e vê o status novo em Meus pedidos.",
            },
            'canal' => $doSite ? null : "Pedido do {$canal}: aqui o cancelamento é só no nosso sistema. Cancele também no painel do {$canal}, senão o canal segue esperando o envio.",
            'pode_avisar_cliente' => $doSite && $destinatario !== null,
        ];
    }

    /**
     * Cancela pelo botão do admin.
     *
     * @return array{ok: bool, erro: ?string, avisos: list<string>}
     */
    public function cancelar(Order $order, string $motivo, ?int $usuarioId, bool $avisarCliente = true): array
    {
        // Trava: dois cliques (ou o webhook do canal chegando junto) não
        // podem rodar estorno e devolução de estoque duas vezes.
        $statusAnterior = DB::transaction(function () use ($order, $motivo, $usuarioId) {
            $travado = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if (! $travado || $travado->status === Order::STATUS_CANCELLED) {
                return null;
            }

            $anterior = $travado->status;

            $travado->forceFill([
                'status' => Order::STATUS_CANCELLED,
                'cancelado_em' => now(),
                'cancelado_por' => $usuarioId,
                'motivo_cancelamento' => mb_substr(trim($motivo), 0, 500),
            ])->save();

            return $anterior;
        });

        if ($statusAnterior === null) {
            return ['ok' => false, 'erro' => 'Este pedido já está cancelado. Nada foi feito de novo.', 'avisos' => []];
        }

        $order->refresh();

        if ($order->user) {
            $order->user->notify(new OrderStatusUpdated($order));
        }

        $capturados = $order->payments()->where('status', Payment::STATUS_CAPTURED)->pluck('id');

        $avisos = $this->efeitos($order, $statusAnterior);

        if ($avisarCliente && $order->origin === Order::ORIGIN_STORE) {
            $valorEstornado = (float) $order->payments()
                ->whereIn('id', $capturados)
                ->where('status', Payment::STATUS_REFUNDED)
                ->sum('amount');

            if ($erroDoEmail = $this->avisarCliente($order, $valorEstornado)) {
                $avisos[] = $erroDoEmail;
            }
        }

        return ['ok' => true, 'erro' => null, 'avisos' => $avisos];
    }

    /**
     * NF-e, estorno e estoque de um pedido que acabou de virar cancelado
     * (pelo botão ou pela troca de status). Nunca desfaz o cancelamento:
     * o que não der certo volta como aviso para o admin tratar.
     *
     * @return list<string>
     */
    public function efeitos(Order $order, string $statusAnterior): array
    {
        $avisos = [];

        if ($avisoDaNota = $this->cancelarNotaSeAutorizada($order)) {
            $avisos[] = $avisoDaNota;
        }

        // Sem gate por status anterior: refundOrder() só age em Payment
        // já capturado/autorizado (nunca existe nenhum pra um pedido que
        // nunca chegou a ser cobrado), então chamar sempre é seguro.
        foreach ($this->finalizer->refundOrder($order) as $erroDoEstorno) {
            Log::error('stripe.refund.order_cancel_failed', ['order_id' => $order->id, 'message' => $erroDoEstorno]);
            $avisos[] = "Reembolso: {$erroDoEstorno}";
        }

        // Só devolve estoque se o pedido nunca chegou a ser enviado —
        // se já saiu (shipped/completed), o produto saiu de verdade e
        // devolver aqui criaria estoque fantasma.
        if (! $this->jaSaiu($statusAnterior)) {
            $this->finalizer->restoreStockIfNeeded($order, 'Pedido cancelado pelo admin');
        }

        return $avisos;
    }

    /**
     * Cancela a NF-e do pedido junto (Etapa 5) quando ele tem uma nota
     * autorizada. Se o cancelamento na SEFAZ falhar (ex: prazo de 24h
     * expirado), o admin é avisado para tratar manualmente, mas o pedido
     * continua cancelado.
     */
    private function cancelarNotaSeAutorizada(Order $order): ?string
    {
        $order->loadMissing('invoice');

        // Carrinho do Mercado Livre: a nota é uma só pro carrinho inteiro.
        // Cancelar ela junto com UM pedido derrubaria a nota dos itens que
        // continuam vendidos — avisa e não mexe.
        if ($avisoDoCarrinho = $this->pack->avisoDeCancelamento($order)) {
            return $avisoDoCarrinho;
        }

        if (! $order->invoice || $order->invoice->status !== Invoice::STATUS_AUTHORIZED) {
            return null;
        }

        // Fora das 24h o cancelamento tem multa ou nem é mais possível: a
        // decisão é de quem está na tela da nota, nunca automática.
        $janela = $order->invoice->janelaDeCancelamento();

        if ($janela === Invoice::JANELA_EXTEMPORANEA) {
            return 'Pedido cancelado, mas a NF-e passou das 24h. Se a mercadoria não saiu, cancele pela tela da nota (até 480h, com multa estimada de R$ '.number_format($order->invoice->multaCancelamentoExtemporaneo(), 2, ',', '.').'); se saiu, registre a devolução lá.';
        }

        if ($janela === Invoice::JANELA_EXPIRADA) {
            return 'Pedido cancelado, mas a NF-e passou de 480h e não pode mais ser cancelada. Registre a devolução na tela da nota.';
        }

        try {
            $this->invoices->cancel($order, "Cancelamento do pedido #{$order->id}");

            return null;
        } catch (Throwable $exception) {
            Log::error('nfe.cancel.failed', ['order_id' => $order->id, 'message' => $exception->getMessage()]);

            return "Pedido cancelado, mas a NF-e não pôde ser cancelada automaticamente na SEFAZ: {$exception->getMessage()}";
        }
    }

    private function previaDaNota(Order $order): string
    {
        $nota = $order->invoice;

        if (! $nota || $nota->status !== Invoice::STATUS_AUTHORIZED) {
            return 'Sem NF-e autorizada: nada a cancelar na SEFAZ.';
        }

        if ($this->pack->avisoDeCancelamento($order)) {
            return "A NF-e nº {$nota->numero} é do carrinho inteiro do Mercado Livre e NÃO será cancelada (cobre outros pedidos). Tratar com o contador.";
        }

        return match ($nota->janelaDeCancelamento()) {
            Invoice::JANELA_NORMAL => "A NF-e nº {$nota->numero} será cancelada na SEFAZ (ainda dentro das 24h).",
            Invoice::JANELA_EXTEMPORANEA => "A NF-e nº {$nota->numero} passou das 24h e NÃO será cancelada sozinha: trate na tela da nota (multa estimada de R$ ".number_format($nota->multaCancelamentoExtemporaneo(), 2, ',', '.').').',
            default => "A NF-e nº {$nota->numero} passou de 480h e não pode mais ser cancelada: registre a devolução na tela da nota.",
        };
    }

    /** @return list<string> */
    private function previaDosPagamentos(Order $order): array
    {
        $linhas = $order->payments->map(function (Payment $pagamento) {
            $onde = $pagamento->provider === Payment::PROVIDER_MERCADOPAGO ? 'Mercado Pago' : 'Stripe';
            $valor = 'R$ '.number_format((float) $pagamento->amount, 2, ',', '.');

            return match (true) {
                $pagamento->boletoPendente() => "Boleto de {$valor} ainda não pago: será cancelado no {$onde}.",
                $pagamento->status === Payment::STATUS_CAPTURED => "{$valor} pagos: estorno total pedido ao {$onde}.",
                $pagamento->status === Payment::STATUS_AUTHORIZED => "{$valor} só autorizados no cartão: a autorização será cancelada no {$onde}.",
                default => null,
            };
        })->filter()->values()->all();

        if ($linhas !== []) {
            return $linhas;
        }

        return [$order->origin === Order::ORIGIN_STORE
            ? 'Nenhum pagamento recebido: nada a estornar.'
            : 'O dinheiro fica com o marketplace: o estorno ao comprador sai pelo próprio canal quando ele cancelar lá.'];
    }

    private function previaDoEstoque(Order $order): string
    {
        if ($this->jaSaiu($order->status)) {
            return 'O pedido já foi enviado: o estoque NÃO volta (registre a devolução quando o produto chegar).';
        }

        if ($order->stock_restored_at) {
            return 'O estoque deste pedido já foi devolvido antes: nada muda.';
        }

        $unidades = (int) $order->items->sum('quantity');

        return $unidades === 1 ? '1 unidade volta para o estoque.' : "{$unidades} unidades voltam para o estoque.";
    }

    private function jaSaiu(string $status): bool
    {
        return in_array($status, [Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true);
    }

    private function destinatario(Order $order): ?string
    {
        return $order->user?->email ?: ($order->shipping_email ?: null);
    }

    /** Manda o e-mail e registra em order_email_logs; devolve o aviso se falhar. */
    private function avisarCliente(Order $order, float $valorEstornado): ?string
    {
        $destinatario = $this->destinatario($order);

        if ($destinatario === null) {
            return null;
        }

        try {
            Mail::to($destinatario)->send(new PedidoCancelado($order, $valorEstornado));

            OrderEmailLog::create([
                'order_id' => $order->id,
                'mailable' => PedidoCancelado::class,
                'attempt' => 1,
                'status' => OrderEmailLog::STATUS_SENT,
                'invoice_attached' => false,
            ]);

            return null;
        } catch (Throwable $exception) {
            Log::error('pedido.cancelado.email_failed', ['order_id' => $order->id, 'message' => $exception->getMessage()]);

            OrderEmailLog::create([
                'order_id' => $order->id,
                'mailable' => PedidoCancelado::class,
                'attempt' => 1,
                'status' => OrderEmailLog::STATUS_FAILED,
                'invoice_attached' => false,
                'error_message' => mb_substr($exception->getMessage(), 0, 500),
            ]);

            return 'O e-mail de cancelamento para o cliente não saiu: avise por outro meio.';
        }
    }
}
