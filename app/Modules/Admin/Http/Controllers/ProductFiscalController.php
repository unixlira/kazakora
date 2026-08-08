<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductFiscalController extends Controller
{
    private const CSOSN_OPTIONS = ['101', '102', '103', '201', '202', '203', '300', '400', '500', '900'];

    private const PIS_COFINS_CST_OPTIONS = [
        '01', '02', '03', '04', '05', '06', '07', '08', '09',
        '49', '50', '51', '52', '53', '54', '55', '56',
        '60', '61', '62', '63', '64', '65', '66', '67',
        '70', '71', '72', '73', '74', '75', '98', '99',
    ];

    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'ncm' => ['required', 'string', 'max:8'],
            'origem' => ['required', 'integer', 'between:0,8'],
            'cfop' => ['required', 'string', 'max:4'],
            'icms_situacao_tributaria' => ['required', 'string', Rule::in(self::CSOSN_OPTIONS)],
            'cfop_outros_estados' => ['required', 'string', 'max:4'],
            'unidade_tributavel' => ['required', 'string', 'max:6'],
            'pis_situacao_tributaria' => ['nullable', 'string', Rule::in(self::PIS_COFINS_CST_OPTIONS)],
            'cofins_situacao_tributaria' => ['nullable', 'string', Rule::in(self::PIS_COFINS_CST_OPTIONS)],
            'percentual_aproximado_tributos' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cest' => ['nullable', 'string', 'max:7'],
            'tipo_operacao' => ['nullable', 'integer', 'in:1,2'],
            'recopi_numero' => ['nullable', 'string', 'max:30'],
            'ex_tipi' => ['nullable', 'string', 'max:10'],
            'fci_numero' => ['nullable', 'string', 'max:40'],
            'informacoes_adicionais' => ['nullable', 'string', 'max:1000'],
            'item_agrupavel' => ['boolean'],
        ]);

        $product->fiscalData()->updateOrCreate(['product_id' => $product->id], $validated);

        $this->retryStuckInvoices($product);

        return back()->with('success', 'Dados fiscais atualizados com sucesso.');
    }

    /**
     * Reprocessa a nota de qualquer pedido que tinha esse produto e ainda
     * não tem NF-e autorizada/externa — cobre tanto o caso comum (falha
     * anterior por "produto sem dados fiscais", agora resolvida) quanto um
     * pedido que nem chegou a tentar ainda (produto acabou de ser
     * cadastrado/auto-importado). Idempotente: GenerateInvoiceJob é
     * ShouldBeUnique por pedido, então redisparar um pedido que já tem uma
     * emissão rodando não duplica nada.
     */
    private function retryStuckInvoices(Product $product): void
    {
        Order::query()
            ->whereHas('items', fn ($query) => $query->where('product_id', $product->id))
            ->whereDoesntHave('invoice', fn ($query) => $query->whereIn('status', [Invoice::STATUS_AUTHORIZED, Invoice::STATUS_EXTERNAL]))
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED])
            ->pluck('id')
            ->each(fn (int $orderId) => GenerateInvoiceJob::dispatch($orderId));
    }
}
