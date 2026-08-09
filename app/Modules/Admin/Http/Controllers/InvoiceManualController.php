<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Fiscal\Services\InvoiceEmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menu de emissão manual de nota fiscal — pedido explícito 2026-08-09.
 * Separado de ManualOrderController (que é "registrar uma venda que já
 * aconteceu em outro canal", sempre produto do catálogo com estoque) porque
 * aqui o item pode ser um serviço avulso sem estoque nenhum. Ver
 * InvoiceEmissionService.
 */
class InvoiceManualController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Admin/Invoices/Emitir', [
            'products' => Product::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'price']),
        ]);
    }

    public function store(Request $request, InvoiceEmissionService $service): RedirectResponse
    {
        $validated = $request->validate([
            'buyer_document' => ['required', 'string', 'max:20'],
            'buyer_name' => ['required', 'string', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:30'],
            'buyer_email' => ['nullable', 'email', 'max:255'],
            'shipping_zip' => ['required', 'string', 'max:9'],
            'shipping_street' => ['required', 'string', 'max:255'],
            'shipping_number' => ['required', 'string', 'max:20'],
            'shipping_complement' => ['nullable', 'string', 'max:255'],
            'shipping_neighborhood' => ['required', 'string', 'max:255'],
            'shipping_city' => ['required', 'string', 'max:255'],
            'shipping_state' => ['required', 'string', 'size:2'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.item_type' => ['nullable', Rule::in([OrderItem::TYPE_PRODUCT, OrderItem::TYPE_SERVICE])],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0.01'],
            // Fiscal manual — só exigido quando o item NÃO tem product_id
            // (validado abaixo com withValidator, "required_without" não dá
            // pra escrever direto pra item aninhado dentro de outro campo).
            'items.*.ncm' => ['nullable', 'string', 'max:10'],
            'items.*.cest' => ['nullable', 'string', 'max:10'],
            'items.*.cfop' => ['nullable', 'string', 'max:10'],
            'items.*.cfop_outros_estados' => ['nullable', 'string', 'max:10'],
            'items.*.origem_mercadoria' => ['nullable', 'integer', 'min:0', 'max:8'],
            'items.*.gtin' => ['nullable', 'string', 'max:20'],
            'items.*.unidade_tributavel' => ['nullable', 'string', 'max:6'],
            'items.*.icms_situacao_tributaria' => ['nullable', 'string', 'max:10'],
            'items.*.pis_situacao_tributaria' => ['nullable', 'string', 'max:4'],
            'items.*.pis_aliquota' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.cofins_situacao_tributaria' => ['nullable', 'string', 'max:4'],
            'items.*.cofins_aliquota' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.percentual_aproximado_tributos' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $validator = validator($validated);
        $validator->after(fn (Validator $v) => $this->validateFreeformItems($v, $validated));
        $validator->validate();

        $order = $service->create($validated);

        return redirect()->route('admin.notas-fiscais.listar')->with('success', "Nota fiscal em emissão — pedido #{$order->id} criado, acompanhe o status na listagem.");
    }

    /**
     * Item sem product_id (digitado na hora) precisa de description + NCM/
     * CFOP/CSOSN/CST — sem isso a NFeXmlBuilderService não teria como
     * montar o item. Feito aqui (não em regras estáticas do form request)
     * porque a obrigatoriedade depende de outro campo do MESMO item.
     */
    private function validateFreeformItems(Validator $validator, array $data): void
    {
        foreach ($data['items'] as $index => $item) {
            if (! empty($item['product_id'])) {
                continue;
            }

            $required = [
                'description' => 'descrição',
                'ncm' => 'NCM',
                'cfop' => 'CFOP',
                'icms_situacao_tributaria' => 'CSOSN (ICMS)',
                'pis_situacao_tributaria' => 'CST do PIS',
                'cofins_situacao_tributaria' => 'CST do COFINS',
            ];

            foreach ($required as $field => $label) {
                if (empty($item[$field])) {
                    $validator->errors()->add("items.{$index}.{$field}", "Item ".($index + 1).": {$label} é obrigatório pra item sem produto do catálogo.");
                }
            }
        }
    }
}
