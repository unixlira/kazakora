<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\LabelFetchService;
use App\Notifications\OrderStatusUpdated;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class OrderController extends Controller
{
    private const STATUSES = [
        Order::STATUS_PENDING,
        Order::STATUS_PAID,
        Order::STATUS_SHIPPED,
        Order::STATUS_COMPLETED,
        Order::STATUS_CANCELLED,
    ];

    private const CHANNELS = [
        Order::ORIGIN_STORE,
        Order::ORIGIN_MERCADO_LIVRE,
        Order::ORIGIN_SHOPEE,
        Order::ORIGIN_TIKTOK_SHOP,
    ];

    public function index(Request $request): Response
    {
        return Inertia::render('Admin/Orders/Index', [
            'orders' => Order::query()
                // latestEmailLog não pode usar seleção parcial de colunas aqui:
                // combinado com o latestOfMany(['created_at','id']) da relação,
                // o Laravel gera um SELECT com `order_id` ambíguo entre as
                // subqueries — MySQL rejeita (SQLSTATE 23000), reproduzido e
                // confirmado em 2026-07-31. A tabela é pequena, carregar a
                // linha inteira não tem custo relevante.
                ->with(['user:id,name,email', 'invoice:id,order_id,status', 'latestEmailLog'])
                ->withCount('items')
                ->when($request->filled('origin'), fn ($query) => $query->where('origin', $request->string('origin')))
                ->latest()
                ->get(),
            'statuses' => self::STATUSES,
            'channels' => self::CHANNELS,
            'filters' => $request->only('origin'),
        ]);
    }

    public function show(Order $order): Response
    {
        return Inertia::render('Admin/Orders/Show', [
            'order' => $order->load(['items', 'user:id,name,email', 'invoice', 'channelShipment'])
                ->loadSum('items as units_count', 'quantity'),
            'statuses' => self::STATUSES,
            'invoiceGenerationLogs' => $order->invoiceGenerationLogs,
            'emailLogs' => $order->emailLogs,
            'fulfillmentEvents' => $order->fulfillmentEvents,
            'auditLogs' => AuditLog::query()
                ->where('entity', class_basename(Order::class))
                ->where('entity_id', $order->id)
                ->with('user:id,name')
                ->latest('created_at')
                ->get(),
        ]);
    }

    public function update(Request $request, Order $order, InvoiceService $invoices, OrderPaymentFinalizer $finalizer): RedirectResponse
    {
        // 'origin' opcional (sometimes|nullable) — pedido explícito
        // 2026-08-21 (pedido #559, importado errado como "loja"/site em vez
        // do canal certo): corrige um erro de digitação/seleção na hora de
        // importar, sem mexer em mais nada do pedido. NÃO recria
        // ChannelShipment/Invoice nem dispara nenhum job automático (esses
        // só disparam na criação/transição pra pago de verdade, ver
        // OrderImportService) — é só a correção do dado em si; se o pedido
        // corrigido precisar do pipeline automático de verdade (etiqueta,
        // nota), isso é responsabilidade de outra ação (ex.: reimportar).
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'origin' => ['sometimes', 'nullable', Rule::in(self::CHANNELS)],
        ]);

        $previousStatus = $order->status;
        $statusChanged = $previousStatus !== $validated['status'];
        $originChanged = array_key_exists('origin', $validated) && $validated['origin'] !== null && $validated['origin'] !== $order->origin;

        if ($originChanged) {
            // Mesma trava única (origin, external_order_id) que
            // OrderImportService usa pra não duplicar pedido — corrigir o
            // canal pra um que já tem outro pedido de verdade com esse
            // mesmo external_order_id seria uma colisão de dados, não uma
            // correção.
            $conflict = Order::query()
                ->where('origin', $validated['origin'])
                ->where('external_order_id', $order->external_order_id)
                ->whereKeyNot($order->id)
                ->exists();

            if ($order->external_order_id && $conflict) {
                return back()->with('error', 'Já existe outro pedido com esse mesmo ID externo nesse canal — não é possível trocar pra ele.');
            }
        }

        // Nunca grava origin=null — 'nullable' na validação acima é só pra
        // aceitar o campo ausente/vazio sem 422 quando só o status está
        // sendo trocado (ver template, o mesmo form manda os dois campos
        // juntos, mas outros chamadores futuros do PATCH podem mandar só
        // status).
        $order->update($originChanged ? $validated : ['status' => $validated['status']]);

        if ($statusChanged && $order->user) {
            $order->user->notify(new OrderStatusUpdated($order));
        }

        $warnings = [];

        if ($statusChanged && $validated['status'] === Order::STATUS_CANCELLED) {
            if ($invoiceWarning = $this->cancelInvoiceIfAuthorized($order, $invoices)) {
                $warnings[] = $invoiceWarning;
            }

            // Sem gate por status anterior: refundOrder() só age em Payment
            // já capturado/autorizado (nunca existe nenhum pra um pedido que
            // nunca chegou a ser cobrado), então chamar sempre é seguro.
            foreach ($finalizer->refundOrder($order) as $refundError) {
                Log::error('stripe.refund.order_cancel_failed', ['order_id' => $order->id, 'message' => $refundError]);
                $warnings[] = "Reembolso: {$refundError}";
            }

            // Só devolve estoque se o pedido nunca chegou a ser enviado —
            // se já saiu (shipped/completed), o produto saiu de verdade e
            // devolver aqui criaria estoque fantasma.
            if (! in_array($previousStatus, [Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true)) {
                $finalizer->restoreStockIfNeeded($order, 'Pedido cancelado pelo admin');
            }
        }

        $message = $originChanged ? 'Status e canal do pedido atualizados.' : 'Status do pedido atualizado.';
        $response = back()->with('success', $message);

        return $warnings ? $response->with('warning', implode(' ', $warnings)) : $response;
    }

    /**
     * Botão "Verificar etiqueta agora" (Admin/Orders/Show) — pedido explícito
     * 2026-08-13: usuário voltando repetidas vezes pedindo pra destravar
     * etiqueta de pedido parado (Shopee, depois Mercado Livre) porque não
     * tinha nenhum jeito de checar/forçar isso sozinho pelo painel — só via
     * intervenção manual direto no servidor. Reaproveita o MESMO serviço
     * que CheckShipmentLabelJob usa nos bastidores (LabelFetchService::
     * attempt(), seguro pra chamar quantas vezes quiser: se o canal ainda
     * não liberou, só devolve "não pronta" sem side-effect nenhum; se já
     * tem PrintJob, o firstOrCreate() de dentro de attempt() não duplica).
     * Chama síncrono, direto do clique, pro usuário ver o resultado real na
     * hora — a mesma pergunta "por que não imprimiu" agora tem resposta
     * própria na tela, sem precisar pedir pra alguém investigar no servidor.
     */
    public function checkLabel(Order $order): RedirectResponse
    {
        $order->loadMissing('channelShipment');
        $shipment = $order->channelShipment;

        if (! $shipment) {
            return back()->with('error', 'Este pedido não tem envio de canal registrado — nada pra verificar.');
        }

        if (in_array($shipment->status, [ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED], true)) {
            return back()->with('success', 'A etiqueta já está pronta — o KoraSync já deve ter imprimido ou vai imprimir no próximo ciclo.');
        }

        $ready = app(LabelFetchService::class)->attempt($shipment);

        return $ready
            ? back()->with('success', 'Etiqueta ficou pronta agora — o KoraSync já pode imprimir.')
            : back()->with('error', "O {$shipment->channel} ainda não liberou a etiqueta desse envio. Isso é decidido do lado do canal (Shopee/Mercado Livre), não é algo que dá pra forçar — o sistema continua tentando sozinho automaticamente.");
    }

    /**
     * Pedido explícito 2026-08-21: reimprimir uma etiqueta já pronta direto
     * pelo navegador do admin, sem depender do KoraSync — feito no dia em
     * que o agente local ficou preso (job já visto antes fica pra sempre
     * marcado localmente, mesmo reaberto no servidor, ver
     * QueueEngine.SyncFromServerAsync no repo do KoraSync) e nenhum jeito
     * de reimprimir na hora existia. `inline` (não `attachment`, ver
     * InvoiceController::danfe()) — abre o PDF direto no visualizador do
     * navegador, pronto pra Ctrl+P, sem precisar baixar e abrir manual.
     * Serve o arquivo exatamente como está — não gera nada novo, não
     * consulta o canal (isso é o que checkLabel() acima já faz).
     */
    public function printLabel(Order $order): HttpResponse
    {
        $order->loadMissing('channelShipment');
        $shipment = $order->channelShipment;

        abort_unless($shipment?->label_path && Storage::disk('local')->exists($shipment->label_path), 404, 'Etiqueta ainda não foi baixada pra este pedido.');

        return response(Storage::disk('local')->get($shipment->label_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"etiqueta-pedido-{$order->id}.pdf\"",
        ]);
    }

    /**
     * Botão "Corrigir etiquetas de hoje" (Admin/Orders/Index) — pedido
     * explícito 2026-08-21, urgente: várias etiquetas de Mercado Livre e
     * Shopee do dia foram baixadas/marcadas como prontas ENQUANTO o layout
     * combinado ainda tinha bugs (versão deitada errada, depois desligada
     * de vez). checkLabel() (ver acima) não resolve isso sozinho — ele nem
     * tenta de novo quando o shipment já está STATUS_LABEL_READY/
     * _DOWNLOADED, então essas etiquetas ficavam presas com o PDF antigo
     * pra sempre. Este botão substitui o comando de tinker manual que seria
     * necessário: busca de novo no canal (idempotente, mesmo método que
     * checkLabel() usa) e REGRAVA label_path com o código corrigido, pra
     * TODOS os envios de Mercado Livre/Shopee com etiqueta pronta HOJE —
     * depois é só usar "Reimprimir etiqueta" em cada pedido normalmente.
     * Síncrono de propósito (mesmo racional de checkLabel()): usuário
     * precisa ver o resultado (quantos corrigiram, quantos falharam) na
     * hora, sem esperar fila.
     */
    public function fixTodaysLabels(): RedirectResponse
    {
        $shipments = ChannelShipment::query()
            ->whereIn('channel', [MarketplaceAccount::CHANNEL_MERCADO_LIVRE, MarketplaceAccount::CHANNEL_SHOPEE])
            ->whereIn('status', [ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED])
            ->whereDate('label_ready_at', today())
            ->with('order')
            ->get();

        if ($shipments->isEmpty()) {
            return back()->with('success', 'Nenhuma etiqueta de hoje pra corrigir — todas já estão com o layout atual, ou nenhuma foi gerada ainda hoje.');
        }

        $fixed = 0;
        $failed = [];

        foreach ($shipments as $shipment) {
            try {
                if (app(LabelFetchService::class)->attempt($shipment)) {
                    $fixed++;
                } else {
                    $failed[] = $shipment->order_id;
                }
            } catch (Throwable $exception) {
                Log::warning('admin.orders.fix_todays_labels_failed', [
                    'shipment_id' => $shipment->id,
                    'order_id' => $shipment->order_id,
                    'message' => $exception->getMessage(),
                ]);
                $failed[] = $shipment->order_id;
            }
        }

        $message = "{$fixed} etiqueta(s) corrigida(s) — já pode reimprimir cada pedido normalmente.";

        if ($failed !== []) {
            $message .= ' Falharam: pedido(s) #'.implode(', #', $failed).' (veja o log pra detalhe).';

            return back()->with('warning', $message);
        }

        return back()->with('success', $message);
    }

    /**
     * Cancela a NF-e do pedido junto (Etapa 5) quando ele tem uma nota
     * autorizada. Nunca bloqueia a mudança de status do pedido em si — se o
     * cancelamento na SEFAZ falhar (ex: prazo de 24h expirado), o admin
     * precisa ser avisado pra tratar manualmente, mas o pedido já foi
     * marcado como cancelado de qualquer forma.
     */
    private function cancelInvoiceIfAuthorized(Order $order, InvoiceService $invoices): ?string
    {
        $order->loadMissing('invoice');

        if (! $order->invoice || $order->invoice->status !== Invoice::STATUS_AUTHORIZED) {
            return null;
        }

        try {
            $invoices->cancel($order, "Cancelamento do pedido #{$order->id}");

            return null;
        } catch (Throwable $exception) {
            Log::error('nfe.cancel.failed', ['order_id' => $order->id, 'message' => $exception->getMessage()]);

            return "Pedido cancelado, mas a NF-e não pôde ser cancelada automaticamente na SEFAZ: {$exception->getMessage()}";
        }
    }
}
