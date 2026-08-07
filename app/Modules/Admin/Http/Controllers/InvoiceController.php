<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
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
}
