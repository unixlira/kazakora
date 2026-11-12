<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cadastros\Models\Supplier;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use App\Modules\Operacional\Models\PurchaseOrder;
use App\Modules\Operacional\Models\PurchaseOrderItem;
use App\Modules\Operacional\Support\CustoDaCompra;
use App\Modules\Operacional\Support\LeitorXmlCompra;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tela Compras, menu Financeiro (pedido do Lira em 12/11/2026: o custo do
 * produto sair do que ele pagou de verdade). Só admin.
 *
 * Reaproveita purchase_orders/purchase_order_items (os pedidos de compra de
 * 2026-07-23) com origem 'compra'. Funciona sem nota (a maior parte das
 * compras); com NF-e, o XML pré-preenche fornecedor e itens.
 *
 * - Custo efetivo do item: CustoDaCompra::ratear (frete e outros custos
 *   rateados pelo valor de cada item).
 * - products.cost_price só muda pelo botão "Atualizar custo do produto".
 * - Entrada no estoque é opcional (desligada por padrão) e usa o mesmo
 *   StockManager do "Receber" dos pedidos de compra; a compra fica como
 *   recebida. Compra recebida não troca mais os itens (o estoque já mudou).
 */
class ComprasController extends Controller
{
    public const PAGAMENTOS = [
        'pix' => 'Pix',
        'cartao' => 'Cartão de crédito',
        'boleto' => 'Boleto',
        'dinheiro' => 'Dinheiro',
        'transferencia' => 'Transferência',
        'outro' => 'Outro',
    ];

    public function __construct(private readonly StockManager $stock) {}

    public function index(Request $request): Response
    {
        $mes = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('mes')) ? (string) $request->query('mes') : now()->format('Y-m');
        $inicio = Carbon::createFromFormat('Y-m-d', $mes.'-01')->startOfDay();
        $fornecedor = $request->integer('fornecedor') ?: null;

        $compras = PurchaseOrder::query()
            ->where('origem', PurchaseOrder::ORIGEM_COMPRA)
            ->where('purchase_date', '>=', $inicio->toDateString())
            ->where('purchase_date', '<', $inicio->copy()->addMonthNoOverflow()->toDateString())
            ->when($fornecedor, fn ($q) => $q->where('supplier_id', $fornecedor))
            ->with(['supplier:id,name', 'items'])
            ->orderByDesc('purchase_date')->orderByDesc('id')
            ->get()
            ->map(fn (PurchaseOrder $c) => $this->resumo($c));

        return Inertia::render('Admin/Compras/Index', [
            'mes' => $mes,
            'fornecedor' => $fornecedor,
            'compras' => $compras->values(),
            'fornecedores' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'totais' => [
                'produtos' => round((float) $compras->sum('produtos'), 2),
                'frete' => round((float) $compras->sum('frete'), 2),
                'outros' => round((float) $compras->sum('outros'), 2),
                'total' => round((float) $compras->sum('total'), 2),
            ],
        ]);
    }

    public function create(): Response
    {
        return $this->formulario(null);
    }

    /** Lê o XML da NF-e e abre o formulário já preenchido (nada é salvo). */
    public function importarXml(Request $request, LeitorXmlCompra $leitor): Response|RedirectResponse
    {
        $request->validate(['xml' => ['required', 'file', 'max:2048']]);

        try {
            $dados = $leitor->ler((string) file_get_contents($request->file('xml')->getRealPath()));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['xml' => $e->getMessage()]);
        }

        if ($dados['invoice_key'] && PurchaseOrder::query()->where('invoice_key', $dados['invoice_key'])->exists()) {
            throw ValidationException::withMessages(['xml' => 'Essa NF-e já foi lançada em outra compra.']);
        }

        return $this->formulario(null, $dados);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $this->validar($request);

        $compra = DB::transaction(function () use ($request, $dados) {
            $compra = PurchaseOrder::create($this->cabecalho($request, $dados) + [
                'origem' => PurchaseOrder::ORIGEM_COMPRA,
                'status' => PurchaseOrder::STATUS_SENT,
                'created_by' => $request->user()->id,
            ]);
            $this->gravarItens($compra, $dados['items']);
            CustoDaCompra::recalcular($compra);

            if ($request->boolean('entrada_estoque')) {
                $this->darEntrada($compra);
            }

            return $compra;
        });

        $this->guardarComprovante($request, $compra);

        return redirect()->route('admin.compras.exibir', $compra)->with('success', 'Compra lançada. Confira o custo de cada item abaixo.');
    }

    public function show(PurchaseOrder $compra): Response
    {
        $this->daTela($compra);
        $compra->load(['supplier:id,name,document', 'items.product:id,name,sku,cost_price,parent_product_id', 'items.costAppliedBy:id,name', 'creator:id,name']);
        $variacoes = Product::query()->whereIn('parent_product_id', $compra->items->pluck('product_id'))
            ->selectRaw('parent_product_id, COUNT(*) as total')->groupBy('parent_product_id')->pluck('total', 'parent_product_id');

        return Inertia::render('Admin/Compras/Show', [
            'compra' => $this->resumo($compra) + [
                'payment_method' => $compra->payment_method,
                'pagamento' => self::PAGAMENTOS[$compra->payment_method] ?? null,
                'notes' => $compra->notes,
                'invoice_key' => $compra->invoice_key,
                'tem_comprovante' => (bool) $compra->attachment_path,
                'criado_por' => $compra->creator?->name,
                'linhas' => $compra->items->sortBy('id')->values()->map(fn (PurchaseOrderItem $i) => [
                    'id' => $i->id,
                    'product_id' => $i->product_id,
                    'produto' => $i->product?->name ?? $i->description,
                    'sku' => $i->product?->sku,
                    'descricao_nota' => $i->description,
                    'quantity' => $i->quantity,
                    'unit_cost' => (float) $i->unit_cost,
                    'custo_efetivo' => $i->effective_unit_cost !== null ? round((float) $i->effective_unit_cost, 2) : null,
                    'custo_atual' => $i->product?->cost_price !== null ? (float) $i->product->cost_price : null,
                    'variacoes' => (int) ($variacoes[$i->product_id] ?? 0),
                    'aplicado_em' => $i->cost_applied_at?->format('d/m/Y H:i'),
                    'aplicado_por' => $i->costAppliedBy?->name,
                    'custo_anterior' => $i->previous_cost !== null ? (float) $i->previous_cost : null,
                ]),
            ],
        ]);
    }

    public function edit(PurchaseOrder $compra): Response
    {
        $this->daTela($compra);

        return $this->formulario($compra);
    }

    public function update(Request $request, PurchaseOrder $compra): RedirectResponse
    {
        $this->daTela($compra);
        $recebida = $compra->status === PurchaseOrder::STATUS_RECEIVED;
        $dados = $this->validar($request, $compra);

        DB::transaction(function () use ($request, $dados, $compra, $recebida) {
            $compra->update($this->cabecalho($request, $dados));

            // Recebida: o estoque já entrou com esses itens, eles não mudam.
            if (! $recebida) {
                $compra->items()->delete();
                $this->gravarItens($compra, $dados['items']);
            }
            CustoDaCompra::recalcular($compra);
        });

        $this->guardarComprovante($request, $compra);

        return redirect()->route('admin.compras.exibir', $compra)->with('success', $recebida
            ? 'Compra atualizada. Os itens não mudam porque o estoque já entrou.'
            : 'Compra atualizada.');
    }

    public function destroy(PurchaseOrder $compra): RedirectResponse
    {
        $this->daTela($compra);

        if ($compra->status === PurchaseOrder::STATUS_RECEIVED) {
            return back()->with('error', 'Essa compra já deu entrada no estoque e não pode ser excluída.');
        }

        if ($compra->attachment_path) {
            Storage::disk('local')->delete($compra->attachment_path);
        }
        $compra->delete();

        return redirect()->route('admin.compras.listar')->with('success', 'Compra excluída.');
    }

    /** Botão "Atualizar custo do produto": um item (item_ids) ou todos. */
    public function atualizarCusto(Request $request, PurchaseOrder $compra): RedirectResponse
    {
        $this->daTela($compra);
        $ids = $request->validate([
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer', Rule::exists('purchase_order_items', 'id')->where('purchase_order_id', $compra->id)],
        ])['item_ids'] ?? null;

        $mudou = CustoDaCompra::aplicar($compra, $ids ?: null, $request->user());

        return back()->with('success', $mudou === 1 ? 'Custo de 1 produto atualizado.' : "Custo de {$mudou} produtos atualizado.");
    }

    public function comprovante(PurchaseOrder $compra): StreamedResponse
    {
        $this->daTela($compra);
        abort_unless($compra->attachment_path && Storage::disk('local')->exists($compra->attachment_path), 404);

        return Storage::disk('local')->response($compra->attachment_path);
    }

    // ------------------------------------------------------------------

    private function daTela(PurchaseOrder $compra): void
    {
        abort_unless($compra->origem === PurchaseOrder::ORIGEM_COMPRA, 404);
    }

    private function formulario(?PurchaseOrder $compra, ?array $importado = null): Response
    {
        $compra?->load('items');

        return Inertia::render('Admin/Compras/Form', [
            'compra' => $compra ? [
                'id' => $compra->id,
                'recebida' => $compra->status === PurchaseOrder::STATUS_RECEIVED,
                'purchase_date' => $compra->purchase_date?->toDateString(),
                'supplier_id' => $compra->supplier_id,
                'notes' => $compra->notes,
                'freight' => (float) $compra->freight,
                'other_costs' => (float) $compra->other_costs,
                'payment_method' => $compra->payment_method,
                'invoice_key' => $compra->invoice_key,
                'tem_comprovante' => (bool) $compra->attachment_path,
                'items' => $compra->items->sortBy('id')->values()->map(fn (PurchaseOrderItem $i) => [
                    'product_id' => $i->product_id, 'description' => $i->description, 'quantity' => $i->quantity, 'unit_cost' => (float) $i->unit_cost,
                ]),
            ] : null,
            'importado' => $importado,
            'fornecedores' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'produtos' => Product::query()->orderBy('name')->get(['id', 'name', 'sku', 'cost_price', 'parent_product_id']),
            'pagamentos' => self::PAGAMENTOS,
        ]);
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?PurchaseOrder $compra = null): array
    {
        $itensObrigatorios = ! $compra || $compra->status !== PurchaseOrder::STATUS_RECEIVED;

        return $request->validate([
            'purchase_date' => ['required', 'date'],
            'supplier_id' => ['nullable', 'required_without:supplier_name', 'integer', 'exists:suppliers,id'],
            'supplier_name' => ['nullable', 'required_without:supplier_id', 'string', 'max:255'],
            'supplier_document' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'freight' => ['nullable', 'numeric', 'min:0'],
            'other_costs' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', Rule::in(array_keys(self::PAGAMENTOS))],
            'invoice_key' => ['nullable', 'digits:44', Rule::unique('purchase_orders', 'invoice_key')->ignore($compra?->id)],
            'comprovante' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'entrada_estoque' => ['nullable', 'boolean'],
            'items' => [$itensObrigatorios ? 'required' : 'nullable', 'array', $itensObrigatorios ? 'min:1' : 'min:0'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ], [
            'items.*.product_id.required' => 'Escolha o produto do catálogo em todos os itens.',
            'supplier_id.required_without' => 'Escolha o fornecedor ou digite o nome de um novo.',
        ]);
    }

    /** @return array<string, mixed> */
    private function cabecalho(Request $request, array $dados): array
    {
        $fornecedor = $dados['supplier_id'] ?? null;
        if (! $fornecedor) {
            $nome = trim((string) $dados['supplier_name']);
            $fornecedor = Supplier::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($nome)])->value('id')
                ?? Supplier::create(['name' => $nome, 'document' => $dados['supplier_document'] ?? null, 'is_active' => true])->id;
        }

        return [
            'supplier_id' => $fornecedor,
            'purchase_date' => $dados['purchase_date'],
            'notes' => $dados['notes'] ?? null,
            'freight' => round((float) ($dados['freight'] ?? 0), 2),
            'other_costs' => round((float) ($dados['other_costs'] ?? 0), 2),
            'payment_method' => $dados['payment_method'] ?? null,
            'invoice_key' => $dados['invoice_key'] ?? null,
        ];
    }

    private function gravarItens(PurchaseOrder $compra, array $itens): void
    {
        foreach ($itens as $item) {
            $compra->items()->create([
                'product_id' => $item['product_id'],
                'description' => $item['description'] ?? null,
                'quantity' => (int) $item['quantity'],
                'unit_cost' => round((float) $item['unit_cost'], 2),
            ]);
        }
    }

    private function darEntrada(PurchaseOrder $compra): void
    {
        foreach ($compra->items()->with('product')->get() as $item) {
            $this->stock->adjust($item->product, $item->quantity, StockMovement::TYPE_RESTOCK,
                reason: "Compra #{$compra->id}", reference: $compra);
        }
        $compra->update(['status' => PurchaseOrder::STATUS_RECEIVED, 'received_at' => now()]);
    }

    private function guardarComprovante(Request $request, PurchaseOrder $compra): void
    {
        if (! $request->hasFile('comprovante')) {
            return;
        }

        $arquivo = $request->file('comprovante');
        $caminho = $arquivo->storeAs("compras/{$compra->id}", Str::random(24).'.'.($arquivo->extension() ?: 'pdf'), 'local');
        if ($compra->attachment_path && $compra->attachment_path !== $caminho) {
            Storage::disk('local')->delete($compra->attachment_path);
        }
        $compra->update(['attachment_path' => $caminho]);
    }

    /** @return array<string, mixed> */
    private function resumo(PurchaseOrder $c): array
    {
        $produtos = round((float) $c->items->sum(fn (PurchaseOrderItem $i) => $i->quantity * (float) $i->unit_cost), 2);

        return [
            'id' => $c->id,
            'data' => $c->purchase_date?->toDateString(),
            'fornecedor' => $c->supplier?->name,
            'itens' => $c->items->count(),
            'unidades' => (int) $c->items->sum('quantity'),
            'produtos' => $produtos,
            'frete' => (float) $c->freight,
            'outros' => (float) $c->other_costs,
            'total' => round($produtos + (float) $c->freight + (float) $c->other_costs, 2),
            'recebida' => $c->status === PurchaseOrder::STATUS_RECEIVED,
            'com_nota' => (bool) $c->invoice_key,
        ];
    }
}
