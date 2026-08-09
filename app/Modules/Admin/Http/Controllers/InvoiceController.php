<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Services\NFe\NFeCertificateNotConfiguredException;
use App\Services\NFe\NFeDistribuicaoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class InvoiceController extends Controller
{
    public function index(): Response
    {
        $invoices = Invoice::query()
            ->with(['order:id,user_id,origin,external_order_id,total', 'order.user:id,name,email'])
            ->latest('created_at')
            ->get();

        return Inertia::render('Admin/Invoices/Index', [
            'invoices' => $invoices,
            'summary' => [
                'authorized_count' => $invoices->where('status', Invoice::STATUS_AUTHORIZED)->count(),
                'authorized_total' => $invoices->where('status', Invoice::STATUS_AUTHORIZED)->sum('valor_total'),
                'cancelled_count' => $invoices->where('status', Invoice::STATUS_CANCELLED)->count(),
                'cancelled_total' => $invoices->where('status', Invoice::STATUS_CANCELLED)->sum('valor_total'),
                'pending_count' => $invoices->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_SIGNED, Invoice::STATUS_SENT])->count(),
                'failed_count' => $invoices->whereIn('status', [Invoice::STATUS_REJECTED, Invoice::STATUS_DENIED, Invoice::STATUS_ERROR])->count(),
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
    public function show(Invoice $invoice): Response
    {
        $invoice->loadMissing(['order:id,user_id,origin,external_order_id,total,shipping_name,status', 'order.user:id,name,email']);

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
                'can_cancel' => $invoice->status === Invoice::STATUS_AUTHORIZED
                    && (! $invoice->autorizada_em || $invoice->autorizada_em->diffInHours(now()) < 24),
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
        ]);

        try {
            $invoices->cancelInvoice($invoice, $validated['motivo']);

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
