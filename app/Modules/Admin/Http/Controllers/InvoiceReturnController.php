<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\SalesReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Devolução de venda pra pessoa física, a partir da tela da nota de venda:
 * declaração pro cliente assinar + NF-e de entrada 1202/2202. Ver
 * SalesReturnService.
 */
class InvoiceReturnController extends Controller
{
    /** Declaração de devolução preenchida, pronta pra imprimir e o cliente assinar. */
    public function declaration(Request $request, Invoice $invoice, SalesReturnService $returns): View
    {
        abort_if($impedimento = $returns->impedimento($invoice), 422, $impedimento ?? '');

        $quantidades = (array) $request->query('itens', []);
        $itens = $returns->itensDevolviveis($invoice)
            // Sem itens na URL, sai tudo o que ainda pode voltar.
            ->map(fn (array $item) => $item + ['devolver' => $quantidades === [] ? $item['disponivel'] : min((int) ($quantidades[$item['id']] ?? 0), $item['disponivel'])])
            ->filter(fn (array $item) => $item['devolver'] > 0)
            ->values();

        return view('fiscal.declaracao-devolucao', [
            'invoice' => $invoice,
            'order' => $invoice->order,
            'company' => Company::query()->first(),
            'itens' => $itens,
            'motivo' => (string) $request->query('motivo', ''),
        ]);
    }

    public function store(Request $request, Invoice $invoice, SalesReturnService $returns): RedirectResponse
    {
        $validated = $request->validate([
            'itens' => ['required', 'array'],
            'itens.*' => ['nullable', 'integer', 'min:0'],
            'motivo' => ['required', 'string', 'min:10', 'max:300'],
            'volta_ao_estoque' => ['boolean'],
            'declaracao' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        try {
            $order = $returns->registrar($invoice, $validated['itens'], $validated['motivo'], (bool) ($validated['volta_ao_estoque'] ?? false), $request->file('declaracao'));
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Devolução registrada (pedido técnico #{$order->id}). A NF-e de entrada está sendo emitida; acompanhe na lista de notas.");
    }

    /** Declaração assinada que chegou depois: anexa na devolução. */
    public function attachDeclaration(Request $request, Invoice $invoice): RedirectResponse
    {
        $request->validate(['declaracao' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        abort_unless($invoice->order?->fiscal_operation_type === 'sales_return', 404);

        $file = $request->file('declaracao');
        $invoice->order->update(['return_declaration_path' => $file->storeAs("devolucoes/{$invoice->order_id}", 'declaracao-devolucao.'.($file->extension() ?: 'pdf'), 'local')]);

        return back()->with('success', 'Declaração de devolução anexada.');
    }

    public function downloadDeclaration(Invoice $invoice): StreamedResponse
    {
        $path = $invoice->order?->return_declaration_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'Declaração de devolução não anexada.');

        return Storage::disk('local')->download($path, "declaracao-devolucao-nfe-{$invoice->numero}.".pathinfo($path, PATHINFO_EXTENSION));
    }

    /** Evento de cancelamento (procEventoNFe) que o contador pede junto com as notas. */
    public function cancellationXml(Invoice $invoice): StreamedResponse
    {
        abort_unless($invoice->xml_cancelamento_path && Storage::disk('local')->exists($invoice->xml_cancelamento_path), 404, 'XML do cancelamento não guardado (nota cancelada antes de 09/10/2026 ou fora do Kazakora).');

        return Storage::disk('local')->download($invoice->xml_cancelamento_path, "cancelamento-{$invoice->chave_acesso}.xml", ['Content-Type' => 'application/xml']);
    }
}
