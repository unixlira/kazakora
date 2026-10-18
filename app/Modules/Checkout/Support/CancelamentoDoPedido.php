<?php

namespace App\Modules\Checkout\Support;

use App\Models\User;
use App\Modules\Checkout\Mail\PedidoCancelado;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderEmailLog;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Modules\Fiscal\Support\PackDoPedido;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\PrintJob;
use App\Notifications\OrderStatusUpdated;
use App\Notifications\PedidoCanceladoPeloClienteNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Cancelamento de pedido pelo admin (botão "Cancelar pedido", 2026-10-17).
 *
 * Junta num lugar só o que antes vivia em OrderController::update() quando
 * o status virava "cancelado": NF-e na SEFAZ, estorno no Mercado Pago ou
 * Stripe e devolução do estoque. O botão soma motivo, quem cancelou e uma
 * prévia do que vai acontecer antes do clique.
 *
 * E-mail "Seu pedido foi cancelado" (Lira, 2026-10-17, voltou depois de ter
 * saído no mesmo dia): pedido do site avisa o cliente quando o admin cancela
 * (botão ou troca de status) e quando o próprio cliente cancela em Meus
 * pedidos (cancelarPeloCliente).
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
     * @return array{pode: bool, bloqueio: ?string, nota: string, pagamentos: list<string>, estoque: string, cliente: string, canal: ?string, etiqueta: ?string}
     */
    public function previa(Order $order): array
    {
        $order->loadMissing(['invoice', 'payments', 'items', 'user']);
        $canal = Order::rotuloDoCanal($order->origin, $order->site_origem);
        $doSite = $order->origin === Order::ORIGIN_STORE;

        return [
            'pode' => $order->status !== Order::STATUS_CANCELLED,
            'bloqueio' => $order->status === Order::STATUS_CANCELLED ? 'Este pedido já está cancelado.' : null,
            'nota' => $this->previaDaNota($order),
            'pagamentos' => $this->previaDosPagamentos($order),
            'estoque' => $this->previaDoEstoque($order),
            'cliente' => match (true) {
                ! $doSite => "O comprador é avisado pelo próprio {$canal}, não por nós.",
                $this->destinatario($order) === null => 'O cliente não tem e-mail cadastrado: ele só vê o status novo em Meus pedidos.',
                default => "O cliente recebe um e-mail avisando do cancelamento ({$this->destinatario($order)}) e vê o status novo em Meus pedidos.",
            },
            'canal' => $doSite ? null : "Pedido do {$canal}: aqui o cancelamento é só no nosso sistema. Cancele também no painel do {$canal}, senão o canal segue esperando o envio.",
            'etiqueta' => $this->previaDaEtiqueta($order),
        ];
    }

    /**
     * Cancela pelo botão do admin.
     *
     * @return array{ok: bool, erro: ?string, avisos: list<string>, etapas: list<array{etapa: string, ok: bool, texto: string}>}
     */
    public function cancelar(Order $order, string $motivo, ?int $usuarioId): array
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

        return ['ok' => true, 'erro' => null] + $this->executarEAvisar($order, $statusAnterior);
    }

    /**
     * Por que o cliente não pode cancelar sozinho agora (null = pode).
     * Só compra do site, antes de a etiqueta sair: depois disso o pacote
     * pode já estar na bancada ou nos Correios e quem resolve é o suporte.
     */
    public function bloqueioDoCliente(Order $order): ?string
    {
        if ($order->status === Order::STATUS_CANCELLED) {
            return 'Esta compra já está cancelada.';
        }

        if ($order->origin !== Order::ORIGIN_STORE) {
            return 'Esta compra só pode ser cancelada pelo canal onde foi feita.';
        }

        $preparando = ! in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_AWAITING_PAYMENT, Order::STATUS_PAID], true)
            || PrintJob::query()->where('order_id', $order->id)->where('is_thank_you', false)->where('status', PrintJob::STATUS_PRINTED)->exists()
            || CorreiosPrePostagem::query()->where('order_id', $order->id)->where('status', CorreiosPrePostagem::STATUS_GERADA)->exists();

        return $preparando
            ? 'Sua compra já está sendo preparada para envio. Para cancelar, fale com o suporte.'
            : null;
    }

    /**
     * Botão "Cancelar pedido" de Meus pedidos (2026-10-17): mesmo
     * cancelamento do admin (nota, estorno, estoque, e-mail) e os admins
     * são avisados no sino com o que ficou para tratar.
     *
     * @return array{ok: bool, erro: ?string, avisos?: list<string>, etapas?: list<array{etapa: string, ok: bool, texto: string}>}
     */
    public function cancelarPeloCliente(Order $order, User $cliente, string $motivo): array
    {
        if ($bloqueio = $this->bloqueioDoCliente($order)) {
            return ['ok' => false, 'erro' => $bloqueio];
        }

        $resultado = $this->cancelar($order, 'Cancelado pelo cliente: '.trim($motivo), $cliente->id);

        if ($resultado['ok']) {
            $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

            if ($admins->isNotEmpty()) {
                rescue(fn () => Notification::send($admins, new PedidoCanceladoPeloClienteNotification($order->refresh(), $resultado['avisos'])), report: false);
            }
        }

        return $resultado;
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
        return $this->executarEAvisar($order, $statusAnterior)['avisos'];
    }

    /**
     * executar() e, no pedido do site, o e-mail ao cliente com o valor que
     * foi estornado agora (só o que estava pago e virou estornado).
     *
     * @return array{avisos: list<string>, etapas: list<array{etapa: string, ok: bool, texto: string}>}
     */
    private function executarEAvisar(Order $order, string $statusAnterior): array
    {
        $capturados = $order->payments()->where('status', Payment::STATUS_CAPTURED)->pluck('id');

        $resultado = $this->executar($order, $statusAnterior);

        if ($order->origin !== Order::ORIGIN_STORE) {
            return $resultado;
        }

        $valorEstornado = (float) $order->payments()
            ->whereIn('id', $capturados)
            ->where('status', Payment::STATUS_REFUNDED)
            ->sum('amount');

        $email = $this->avisarCliente($order, $valorEstornado);
        $resultado['etapas'][] = array_intersect_key($email, array_flip(['etapa', 'ok', 'texto']));

        if (! $email['ok']) {
            $resultado['avisos'][] = $email['aviso'];
        }

        return $resultado;
    }

    private function destinatario(Order $order): ?string
    {
        return $order->user?->email ?: ($order->shipping_email ?: null);
    }

    /**
     * Manda o e-mail e registra em order_email_logs.
     *
     * @return array{etapa: string, ok: bool, texto: string, aviso?: string}
     */
    private function avisarCliente(Order $order, float $valorEstornado): array
    {
        $order->loadMissing('user');
        $destinatario = $this->destinatario($order);

        if ($destinatario === null) {
            return ['etapa' => 'E-mail ao cliente', 'ok' => true, 'texto' => 'Cliente sem e-mail cadastrado: nenhum e-mail enviado.'];
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

            return ['etapa' => 'E-mail ao cliente', 'ok' => true, 'texto' => "E-mail de cancelamento enviado para {$destinatario}."];
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

            $aviso = 'O e-mail de cancelamento para o cliente não saiu: avise por outro meio.';

            return ['etapa' => 'E-mail ao cliente', 'ok' => false, 'texto' => $aviso, 'aviso' => $aviso];
        }
    }

    /**
     * Mesmo que efeitos(), com o resultado de cada etapa para o modal do
     * botão "Cancelar pedido" (2026-10-17, pedido #3205: o Lira só via um
     * aviso solto e não sabia o que tinha dado certo).
     *
     * @return array{avisos: list<string>, etapas: list<array{etapa: string, ok: bool, texto: string}>}
     */
    private function executar(Order $order, string $statusAnterior): array
    {
        $this->tirarEtiquetasDaFila($order);

        $etapas = [$this->cancelarNotaSeAutorizada($order), $this->estornar($order)];

        // Só devolve estoque se o pedido nunca chegou a ser enviado —
        // se já saiu (shipped/completed), o produto saiu de verdade e
        // devolver aqui criaria estoque fantasma.
        if ($this->jaSaiu($statusAnterior)) {
            $etapas[] = ['etapa' => 'Estoque', 'ok' => true, 'texto' => 'Pedido já tinha saído: o estoque não volta (registre a devolução quando o produto chegar).'];
        } else {
            $jaDevolvido = (bool) $order->stock_restored_at;
            $this->finalizer->restoreStockIfNeeded($order, str_starts_with((string) $order->motivo_cancelamento, 'Cancelado pelo cliente') ? 'Pedido cancelado pelo cliente' : 'Pedido cancelado pelo admin');
            $etapas[] = ['etapa' => 'Estoque', 'ok' => true, 'texto' => $jaDevolvido ? 'O estoque já tinha sido devolvido antes.' : 'Estoque devolvido.'];
        }

        $etapas[] = ['etapa' => 'Pedido', 'ok' => true, 'texto' => 'Pedido cancelado no KazaKora.'];

        $avisos = collect($etapas)->reject(fn (array $etapa) => $etapa['ok'])->pluck('aviso')->values()->all();

        return [
            'avisos' => $avisos,
            'etapas' => array_map(fn (array $etapa) => array_intersect_key($etapa, array_flip(['etapa', 'ok', 'texto'])), $etapas),
        ];
    }

    /** @return array{etapa: string, ok: bool, texto: string, aviso?: string} */
    private function estornar(Order $order): array
    {
        $order->loadMissing('payments');
        $aEstornar = $order->payments->filter(fn (Payment $pagamento) => $pagamento->boletoPendente()
            || in_array($pagamento->status, [Payment::STATUS_CAPTURED, Payment::STATUS_AUTHORIZED], true));

        // Sem gate por status anterior: refundOrder() só age em Payment
        // já capturado/autorizado (nunca existe nenhum pra um pedido que
        // nunca chegou a ser cobrado), então chamar sempre é seguro.
        $erros = $this->finalizer->refundOrder($order);

        foreach ($erros as $erroDoEstorno) {
            Log::error('stripe.refund.order_cancel_failed', ['order_id' => $order->id, 'message' => $erroDoEstorno]);
        }

        if ($erros !== []) {
            $aviso = implode(' ', array_map(fn (string $erro) => "Reembolso: {$erro}", $erros));

            return ['etapa' => 'Estorno', 'ok' => false, 'texto' => $aviso.' Faça o estorno pelo painel do pagamento.', 'aviso' => $aviso];
        }

        if ($aEstornar->isEmpty()) {
            return ['etapa' => 'Estorno', 'ok' => true, 'texto' => $order->origin === Order::ORIGIN_STORE
                ? 'Nenhum pagamento recebido: nada a estornar.'
                : 'O estorno ao comprador sai pelo próprio canal.'];
        }

        $valor = 'R$ '.number_format((float) $aEstornar->sum('amount'), 2, ',', '.');
        $onde = $aEstornar->contains(fn (Payment $pagamento) => $pagamento->provider === Payment::PROVIDER_MERCADOPAGO) ? 'Mercado Pago' : 'Stripe';

        return ['etapa' => 'Estorno', 'ok' => true, 'texto' => "Estorno de {$valor} feito no {$onde}."];
    }

    /**
     * Cancela a NF-e do pedido junto (Etapa 5) quando ele tem uma nota
     * autorizada. Se o cancelamento na SEFAZ falhar (ex: prazo de 24h
     * expirado), o admin é avisado para tratar manualmente, mas o pedido
     * continua cancelado.
     *
     * @return array{etapa: string, ok: bool, texto: string, aviso?: string}
     */
    private function cancelarNotaSeAutorizada(Order $order): array
    {
        $order->loadMissing('invoice');
        $falha = fn (string $aviso) => ['etapa' => 'Nota fiscal', 'ok' => false, 'texto' => $aviso, 'aviso' => $aviso];

        // Carrinho do Mercado Livre: a nota é uma só pro carrinho inteiro.
        // Cancelar ela junto com UM pedido derrubaria a nota dos itens que
        // continuam vendidos — avisa e não mexe.
        if ($avisoDoCarrinho = $this->pack->avisoDeCancelamento($order)) {
            return $falha($avisoDoCarrinho);
        }

        if (! $order->invoice || $order->invoice->status !== Invoice::STATUS_AUTHORIZED) {
            return ['etapa' => 'Nota fiscal', 'ok' => true, 'texto' => 'Sem NF-e autorizada: nada a cancelar na SEFAZ.'];
        }

        // Fora das 24h o cancelamento tem multa ou nem é mais possível: a
        // decisão é de quem está na tela da nota, nunca automática.
        $janela = $order->invoice->janelaDeCancelamento();

        if ($janela === Invoice::JANELA_EXTEMPORANEA) {
            return $falha('Pedido cancelado, mas a NF-e passou das 24h. Se a mercadoria não saiu, cancele pela tela da nota (até 480h, com multa estimada de R$ '.number_format($order->invoice->multaCancelamentoExtemporaneo(), 2, ',', '.').'); se saiu, registre a devolução lá.');
        }

        if ($janela === Invoice::JANELA_EXPIRADA) {
            return $falha('Pedido cancelado, mas a NF-e passou de 480h e não pode mais ser cancelada. Registre a devolução na tela da nota.');
        }

        try {
            $nota = $this->invoices->cancel($order, "Cancelamento do pedido #{$order->id}");

            return ['etapa' => 'Nota fiscal', 'ok' => true, 'texto' => "NF-e nº {$nota->numero} cancelada na SEFAZ."];
        } catch (Throwable $exception) {
            Log::error('nfe.cancel.failed', ['order_id' => $order->id, 'message' => $exception->getMessage()]);

            return $falha("Pedido cancelado, mas a NF-e não pôde ser cancelada automaticamente na SEFAZ: {$exception->getMessage()}");
        }
    }

    /**
     * Etiqueta ainda na fila não sai mais (Lira, 2026-10-17). A que o
     * KoraSync já pegou não dá para segurar daqui; o PrintAgentController
     * recusa o download dela se o pedido estiver cancelado.
     */
    private function tirarEtiquetasDaFila(Order $order): void
    {
        PrintJob::query()
            ->where('order_id', $order->id)
            ->where('status', PrintJob::STATUS_QUEUED)
            ->update([
                'status' => PrintJob::STATUS_FAILED,
                'error_message' => PrintJob::MOTIVO_PEDIDO_CANCELADO,
            ]);
    }

    private function previaDaEtiqueta(Order $order): ?string
    {
        $impressaEm = PrintJob::query()
            ->where('order_id', $order->id)
            ->where('is_thank_you', false)
            ->where('status', PrintJob::STATUS_PRINTED)
            ->max('printed_at');

        if ($impressaEm) {
            return 'A etiqueta já foi impressa em '.Carbon::parse($impressaEm)->format('d/m H:i').': tire o pacote da bancada. A postagem nos Correios não é cancelada sozinha.';
        }

        $naFila = PrintJob::query()
            ->where('order_id', $order->id)
            ->where('status', PrintJob::STATUS_QUEUED)
            ->exists();

        return $naFila ? 'A etiqueta que está na fila da impressora sai da fila e não é impressa.' : null;
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
}
