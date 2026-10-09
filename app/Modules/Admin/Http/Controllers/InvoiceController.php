<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Modules\Fiscal\Services\SalesReturnService;
use App\Modules\Marketplace\Support\ChannelInvoiceSubmissionService;
use App\Services\NFe\NFeCertificateNotConfiguredException;
use App\Services\NFe\NFeDistribuicaoService;
use App\Support\Http\TableSort;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class InvoiceController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * Abas da listagem → status reais. Mesmos grupos que a tela já usava
     * quando filtrava no navegador.
     */
    private const TAB_STATUSES = [
        'authorized' => [Invoice::STATUS_AUTHORIZED],
        'cancelled' => [Invoice::STATUS_CANCELLED],
        'pending_group' => [Invoice::STATUS_PENDING, Invoice::STATUS_SIGNED, Invoice::STATUS_SENT],
        'failed_group' => [Invoice::STATUS_REJECTED, Invoice::STATUS_DENIED, Invoice::STATUS_ERROR],
    ];

    // Mesmos rótulos de ORIGIN_STYLES em Invoices/Index.vue.
    private const ORIGIN_LABELS = [
        'loja' => 'Loja',
        'mercado_livre' => 'Mercado Livre',
        'shopee' => 'Shopee',
        'amazon' => 'Amazon',
        'tiktok_shop' => 'TikTok Shop',
        'shein' => 'Shein',
        'nota_fiscal_avulsa' => 'Emissão manual',
    ];

    public function index(Request $request): Response
    {
        // Paginado no servidor — antes a tela recebia TODAS as notas
        // (~2000, 1,7 MB de HTML medido em produção 2026-09-29) só pra
        // aba/busca/ordenação rodarem no navegador. Aba, busca e ordenação
        // viraram query param tratado aqui, nada que dava pra fazer antes
        // se perdeu.
        $tab = array_key_exists($request->string('status')->toString(), self::TAB_STATUSES) ? $request->string('status')->toString() : 'all';
        $search = TableSort::likeTerm($request->string('search')->toString());
        // "123/1" (número/série, como a coluna Nota mostra) — tratado à
        // parte porque concatenar no SQL muda entre MySQL e SQLite.
        preg_match('/^\s*(\d+)\s*\/\s*(\d+)\s*$/', $request->string('search')->toString(), $numeroSerie);
        // A busca do navegador também casava o nome da plataforma mostrado
        // na coluna ("Shopee", "Mercado Livre"...) — mantém isso mapeando o
        // texto digitado pros origins cujo rótulo contém o termo.
        $searchText = mb_strtolower(trim($request->string('search')->toString()));
        $matchingOrigins = $searchText === '' ? [] : array_keys(array_filter(
            self::ORIGIN_LABELS,
            fn (string $label) => str_contains(mb_strtolower($label), $searchText),
        ));
        $sort = TableSort::resolve($request, [
            'numero' => 'numero',
            'valor_total' => 'valor_total',
            'status' => 'status',
            'chave_acesso' => 'chave_acesso',
            'autorizada_em' => 'autorizada_em',
            'origin' => 'origin',
            'external_order_id' => 'external_order_id',
        ], 'created_at');

        // Sem eager-load de order.user aqui — a coluna "Cliente" saiu da
        // listagem (pedido explícito 2026-08-09, fica só na tela de view),
        // então só precisa do essencial pra pintar a plataforma colorida.
        $invoices = Invoice::query()
            ->with('order:id,origin,external_order_id')
            ->when($tab !== 'all', fn ($query) => $query->whereIn('status', self::TAB_STATUSES[$tab]))
            ->when($search, function ($query) use ($search, $numeroSerie, $matchingOrigins) {
                $query->where(function ($query) use ($search, $numeroSerie, $matchingOrigins) {
                    $query->where('numero', 'like', $search)
                        ->when($numeroSerie, fn ($query) => $query->orWhere(fn ($query) => $query->where('numero', (int) $numeroSerie[1])->where('serie', (int) $numeroSerie[2])))
                        ->orWhere('chave_acesso', 'like', $search)
                        ->orWhere('destinatario_nome', 'like', $search)
                        ->orWhereHas('order', fn ($order) => $order->where('external_order_id', 'like', $search)->orWhere('id', 'like', $search))
                        ->when($matchingOrigins, fn ($query) => $query->orWhereHas('order', fn ($order) => $order->whereIn('origin', $matchingOrigins)));
                });
            })
            ->when(
                in_array($sort['column'], ['origin', 'external_order_id'], true),
                // Plataforma/pedido na plataforma moram em orders — ordena
                // por subquery em vez de join pra não duplicar/colidir
                // colunas do select de invoices.
                fn ($query) => $query->orderBy(Order::query()->select($sort['column'])->whereColumn('orders.id', 'invoices.order_id'), $sort['direction']),
                fn ($query) => $query->orderBy($sort['column'], $sort['direction']),
            )
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Cards do topo sempre contam TODAS as notas (independente de aba e
        // busca, como já era) — agregado direto no SQL, sem carregar linha.
        $pendingStatuses = self::TAB_STATUSES['pending_group'];
        $failedStatuses = self::TAB_STATUSES['failed_group'];
        $totals = Invoice::query()
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as authorized_count', [Invoice::STATUS_AUTHORIZED])
            ->selectRaw('SUM(CASE WHEN status = ? THEN valor_total ELSE 0 END) as authorized_total', [Invoice::STATUS_AUTHORIZED])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled_count', [Invoice::STATUS_CANCELLED])
            ->selectRaw('SUM(CASE WHEN status = ? THEN valor_total ELSE 0 END) as cancelled_total', [Invoice::STATUS_CANCELLED])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) as pending_count', $pendingStatuses)
            ->selectRaw('SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) as failed_count', $failedStatuses)
            ->toBase()
            ->first();

        return Inertia::render('Admin/Invoices/Index', [
            'invoices' => $invoices,
            'filters' => [
                'status' => $tab,
                'search' => $request->string('search')->toString(),
                'sort' => $sort['key'],
                'direction' => $sort['direction'],
            ],
            'summary' => [
                'authorized_count' => (int) ($totals->authorized_count ?? 0),
                'authorized_total' => round((float) ($totals->authorized_total ?? 0), 2),
                'cancelled_count' => (int) ($totals->cancelled_count ?? 0),
                'cancelled_total' => round((float) ($totals->cancelled_total ?? 0), 2),
                'pending_count' => (int) ($totals->pending_count ?? 0),
                'failed_count' => (int) ($totals->failed_count ?? 0),
            ],
        ]);
    }

    /**
     * Emissão manual — reaproveita o mesmo job assíncrono do fluxo automático
     * (mesma lógica de retry/log/notificação), só muda quem dispara.
     */
    public function issue(Order $order): RedirectResponse
    {
        $order->loadMissing('invoice');

        if ($order->invoice?->status === Invoice::STATUS_AUTHORIZED) {
            return back()->with('error', 'Este pedido já tem uma nota fiscal autorizada.');
        }

        if (in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_AWAITING_PAYMENT], true)) {
            return back()->with('error', 'O pedido ainda não foi pago — não é possível emitir a nota fiscal.');
        }

        GenerateInvoiceJob::dispatch($order->id);

        return back()->with('success', 'Emissão da nota fiscal agendada.');
    }

    /**
     * Download do DANFE via navegador do admin — não existia nenhuma rota
     * pra isso antes (só o e-mail de recibo anexa o PDF automaticamente).
     * Pedido explícito 2026-08-07: precisava de um jeito de baixar a nota
     * de um pedido específico pra salvar manualmente numa pasta local
     * (não tem como o Kazakora escrever direto no PC do usuário).
     */
    public function danfe(Order $order): HttpResponse
    {
        $order->loadMissing('invoice');

        abort_unless($order->invoice?->danfe_path && Storage::disk('local')->exists($order->invoice->danfe_path), 404, 'DANFE não encontrado — nota ainda não autorizada ou PDF não gerado.');

        return response(Storage::disk('local')->get($order->invoice->danfe_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"danfe-pedido-{$order->id}.pdf\"",
        ]);
    }

    /**
     * Download do XML autorizado (nfeProc) — Amazon (e outros canais/
     * contadores) às vezes pedem o XML em vez do PDF do DANFE. Mesmo
     * padrão de danfe() acima.
     */
    public function xml(Order $order): HttpResponse
    {
        $order->loadMissing('invoice');

        abort_unless($order->invoice?->xml_path && Storage::disk('local')->exists($order->invoice->xml_path), 404, 'XML não encontrado — nota ainda não autorizada.');

        return response(Storage::disk('local')->get($order->invoice->xml_path), 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"nfe-pedido-{$order->id}.xml\"",
        ]);
    }

    /**
     * Botão "Reenviar nota pro canal" (Admin/Orders/Show) — pedido explícito
     * 2026-08-13: um pedido Shopee travado levou o usuário a emitir a nota
     * manualmente por aqui, e não existia um jeito de reempurrar o ENVIO da
     * nota já autorizada pro canal na hora — só o automático
     * (SubmitInvoiceToChannelJob), que tem backoff de até ~3h e meia (ver
     * comentário lá) antes de desistir e avisar os admins. Reaproveita o
     * MESMO serviço que o job usa (ChannelInvoiceSubmissionService::submit(),
     * já idempotente: se o canal já aceitou, não refaz nada) — só chama
     * síncrono aqui, pro admin ver o resultado na hora em vez de esperar um
     * retry automático.
     */
    public function resubmitToChannel(Order $order, ChannelInvoiceSubmissionService $service): RedirectResponse
    {
        $order->loadMissing('invoice');

        if (! $order->invoice || $order->invoice->status !== Invoice::STATUS_AUTHORIZED) {
            return back()->with('error', 'Só é possível reenviar ao canal uma nota fiscal já autorizada.');
        }

        if (in_array($order->origin, [Order::ORIGIN_STORE, Order::ORIGIN_MANUAL_INVOICE], true)) {
            return back()->with('error', 'Este pedido não é de um canal de marketplace — não há canal pra reenviar a nota.');
        }

        try {
            $service->submit($order);

            return back()->with('success', 'Nota fiscal reenviada ao canal com sucesso.');
        } catch (Throwable $exception) {
            return back()->with('error', "O canal recusou o reenvio da nota: {$exception->getMessage()}");
        }
    }

    public function cancel(Request $request, Order $order, InvoiceService $invoices): RedirectResponse
    {
        $validated = $request->validate([
            'motivo' => ['required', 'string', 'min:15', 'max:500'],
        ]);

        try {
            $invoices->cancel($order, $validated['motivo']);

            return back()->with('success', 'Nota fiscal cancelada com sucesso.');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    /**
     * Tela de view de uma nota específica — pedido explícito 2026-08-09.
     * Funciona tanto pra nota ligada a um Order do Kazakora quanto pra nota
     * órfã trazida pela sincronização SEFAZ (origem='sefaz', sem order_id),
     * por isso a rota é /notas-fiscais/{invoice}, não aninhada em /pedidos.
     */
    public function show(Invoice $invoice, SalesReturnService $returns): Response
    {
        $invoice->loadMissing(['order:id,user_id,origin,external_order_id,total,shipping_name,status,fiscal_operation_type,fiscal_referenced_nfe_key,return_declaration_path', 'order.user:id,name,email']);

        return Inertia::render('Admin/Invoices/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'origem' => $invoice->origem,
                'status' => $invoice->status,
                'ambiente' => $invoice->ambiente,
                'numero' => $invoice->numero,
                'serie' => $invoice->serie,
                'valor_total' => $invoice->valor_total,
                'chave_acesso' => $invoice->chave_acesso,
                'protocolo_autorizacao' => $invoice->protocolo_autorizacao,
                'autorizada_em' => $invoice->autorizada_em,
                'motivo_rejeicao' => $invoice->motivo_rejeicao,
                'protocolo_cancelamento' => $invoice->protocolo_cancelamento,
                'motivo_cancelamento' => $invoice->motivo_cancelamento,
                'cancelada_em' => $invoice->cancelada_em,
                'has_xml' => (bool) ($invoice->xml_path && Storage::disk('local')->exists($invoice->xml_path)),
                'has_danfe' => (bool) ($invoice->danfe_path && Storage::disk('local')->exists($invoice->danfe_path)),
                'can_cancel' => in_array($invoice->janelaDeCancelamento(), [Invoice::JANELA_NORMAL, Invoice::JANELA_EXTEMPORANEA], true),
                // Prazos de SP (contador, 2026-10-08): normal até 24h, com
                // multa até 480h, depois só devolução.
                'janela_cancelamento' => $invoice->janelaDeCancelamento(),
                'cancelamento_normal_ate' => $invoice->prazosDeCancelamento()['normal_ate'],
                'cancelamento_extemporaneo_ate' => $invoice->prazosDeCancelamento()['extemporaneo_ate'],
                'multa_cancelamento' => $invoice->multaCancelamentoExtemporaneo(),
                'cancelamento_extemporaneo' => (bool) $invoice->cancelamento_extemporaneo,
                'has_xml_cancelamento' => (bool) ($invoice->xml_cancelamento_path && Storage::disk('local')->exists($invoice->xml_cancelamento_path)),
                'devolucao' => $this->devolucao($invoice, $returns),
                'destinatario_nome' => $invoice->order?->shipping_name ?? $invoice->destinatario_nome,
                'destinatario_documento' => $invoice->destinatario_documento,
                'order' => $invoice->order ? [
                    'id' => $invoice->order->id,
                    'origin' => $invoice->order->origin,
                    'external_order_id' => $invoice->order->external_order_id,
                    'customer' => $invoice->order->user?->name,
                ] : null,
            ],
        ]);
    }

    /**
     * Devolução na tela da nota: na nota de venda, o que ainda pode voltar e
     * as devoluções já feitas; na nota de devolução, a venda de origem e a
     * declaração do cliente.
     *
     * @return array<string, mixed>|null
     */
    private function devolucao(Invoice $invoice, SalesReturnService $returns): ?array
    {
        $order = $invoice->order;

        if (! $order) {
            return null;
        }

        if ($order->fiscal_operation_type === 'sales_return') {
            $venda = Invoice::query()->where('chave_acesso', $order->fiscal_referenced_nfe_key)->first(['id', 'numero', 'serie']);

            return [
                'tipo' => 'entrada',
                'venda' => $venda ? ['id' => $venda->id, 'numero' => $venda->numero, 'serie' => $venda->serie] : null,
                'tem_declaracao' => (bool) ($order->return_declaration_path && Storage::disk('local')->exists($order->return_declaration_path)),
            ];
        }

        $impedimento = $returns->impedimento($invoice);

        return [
            'tipo' => 'venda',
            'impedimento' => $impedimento,
            'itens' => $impedimento ? [] : $returns->itensDevolviveis($invoice)->all(),
            'canal_emite_devolucao' => in_array($order->origin, SalesReturnService::CANAIS_QUE_EMITEM_DEVOLUCAO, true),
            'devolucoes' => Invoice::query()
                ->whereHas('order', fn ($query) => $query->where('origin', Order::ORIGIN_SALES_RETURN_INVOICE)->where('fiscal_referenced_nfe_key', $invoice->chave_acesso))
                ->orderBy('id')
                ->get(['id', 'numero', 'serie', 'status', 'valor_total'])
                ->all(),
        ];
    }

    public function danfeForInvoice(Invoice $invoice): HttpResponse
    {
        abort_unless($invoice->danfe_path && Storage::disk('local')->exists($invoice->danfe_path), 404, 'DANFE não encontrado — nota ainda não autorizada, PDF não gerado, ou é uma nota trazida da SEFAZ sem cópia local do PDF.');

        return response(Storage::disk('local')->get($invoice->danfe_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"danfe-{$invoice->chave_acesso}.pdf\"",
        ]);
    }

    public function xmlForInvoice(Invoice $invoice): HttpResponse
    {
        abort_unless($invoice->xml_path && Storage::disk('local')->exists($invoice->xml_path), 404, 'XML não encontrado — nota ainda não autorizada, ou é uma nota trazida da SEFAZ sem cópia local do XML.');

        return response(Storage::disk('local')->get($invoice->xml_path), 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"nfe-{$invoice->chave_acesso}.xml\"",
        ]);
    }

    /**
     * Cancelamento a partir da tela de view — cobre nota órfã (sem Order)
     * que o cancel() acima (aninhado em /pedidos) não alcança.
     */
    public function cancelInvoice(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $validated = $request->validate([
            'motivo' => ['required', 'string', 'min:15', 'max:500'],
            'fora_do_prazo' => ['boolean'],
        ]);

        try {
            $invoices->cancelInvoice($invoice, $validated['motivo'], (bool) ($validated['fora_do_prazo'] ?? false));

            return back()->with('success', 'Nota fiscal cancelada com sucesso — cancelamento enviado à SEFAZ.');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    /**
     * "Buscar todas no SEFAZ" — pedido explícito 2026-08-09. Consulta a
     * Distribuição DFe e importa/atualiza o que a SEFAZ souber e este
     * sistema ainda não tiver local. Ver NFeDistribuicaoService.
     */
    public function syncSefaz(NFeDistribuicaoService $distribuicao): RedirectResponse
    {
        try {
            $resultado = $distribuicao->sync();

            return back()->with('success', $resultado['mensagem']);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (NFeCertificateNotConfiguredException) {
            return back()->with('error', 'Certificado digital não configurado — configure em Empresa antes de sincronizar com a SEFAZ.');
        }
    }
}
