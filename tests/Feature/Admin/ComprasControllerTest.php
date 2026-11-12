<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Cadastros\Models\Supplier;
use App\Modules\Catalog\Models\Product;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Operacional\Models\PurchaseOrder;
use App\Modules\Operacional\Support\CustoDaCompra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tela Compras (pedido do Lira em 12/11/2026): o custo do produto sai do que
 * a loja pagou, com frete e outros custos rateados, e só muda com o clique.
 */
class ComprasControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function compra(User $admin, array $dados = []): PurchaseOrder
    {
        $a = Product::factory()->create(['cost_price' => 8]);
        $b = Product::factory()->create(['cost_price' => null]);

        $this->actingAs($admin)->post('/admin/compras', array_merge([
            'purchase_date' => '2026-11-10',
            'supplier_name' => 'Fornecedor Novo do Brás',
            'freight' => 10,
            'other_costs' => 0,
            'payment_method' => 'pix',
            'items' => [
                ['product_id' => $a->id, 'quantity' => 2, 'unit_cost' => 10],
                ['product_id' => $b->id, 'quantity' => 1, 'unit_cost' => 30],
            ],
        ], $dados))->assertSessionHasNoErrors()->assertRedirect();

        return PurchaseOrder::query()->latest('id')->firstOrFail();
    }

    public function test_rateio_do_frete_e_outros_custos_pelo_valor_do_item(): void
    {
        // Itens R$ 20 e R$ 30 (total 50); R$ 10 de extras: 4 + 6.
        $this->assertSame([12.0, 36.0], CustoDaCompra::ratear([
            ['quantity' => 2, 'unit_cost' => 10],
            ['quantity' => 1, 'unit_cost' => 30],
        ], 7, 3));
        // Tudo de valor zero: rateio pela quantidade.
        $this->assertSame([2.5, 2.5], CustoDaCompra::ratear([
            ['quantity' => 2, 'unit_cost' => 0],
            ['quantity' => 2, 'unit_cost' => 0],
        ], 10, 0));
    }

    public function test_lanca_compra_sem_nota_com_fornecedor_novo_e_comprovante_sem_mexer_no_custo(): void
    {
        $admin = $this->admin();
        $compra = $this->compra($admin, ['comprovante' => UploadedFile::fake()->create('pix.pdf', 20, 'application/pdf')]);

        $this->assertSame(PurchaseOrder::ORIGEM_COMPRA, $compra->origem);
        $this->assertSame('Fornecedor Novo do Brás', $compra->supplier->name);
        $this->assertNotNull($compra->attachment_path);
        Storage::disk('local')->assertExists($compra->attachment_path);
        $itens = $compra->items()->orderBy('id')->get();
        $this->assertSame([12.0, 36.0], $itens->map(fn ($i) => (float) $i->effective_unit_cost)->all());
        // Nada muda o custo do produto sem o clique.
        $this->assertSame('8.00', (string) $itens[0]->product->fresh()->cost_price);
        $this->assertNull($itens[1]->product->fresh()->cost_price);

        $this->actingAs($admin)->get("/admin/compras/{$compra->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Compras/Show', false)
                ->where('compra.total', 60)
                ->where('compra.linhas.0.custo_efetivo', 12)
                ->where('compra.linhas.0.custo_atual', 8)
                ->where('compra.tem_comprovante', true));
        $this->actingAs($admin)->get("/admin/compras/{$compra->id}/comprovante")->assertOk();

        $this->actingAs($admin)->get('/admin/compras?mes=2026-11')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Compras/Index', false)->has('compras', 1)->where('totais.total', 60));
        $this->actingAs($admin)->get('/admin/compras?mes=2026-10')->assertOk()
            ->assertInertia(fn ($page) => $page->has('compras', 0));
        $this->actingAs($admin)->get('/admin/compras?mes=2026-11&fornecedor='.($compra->supplier_id + 99))->assertOk()
            ->assertInertia(fn ($page) => $page->has('compras', 0));
    }

    public function test_edita_e_exclui(): void
    {
        $admin = $this->admin();
        $compra = $this->compra($admin, ['comprovante' => UploadedFile::fake()->create('pix.pdf', 20, 'application/pdf')]);
        $itens = $compra->items()->orderBy('id')->get();
        $fornecedor = Supplier::create(['name' => 'Outro', 'is_active' => true]);

        $this->actingAs($admin)->put("/admin/compras/{$compra->id}", [
            'purchase_date' => '2026-11-11',
            'supplier_id' => $fornecedor->id,
            'freight' => 0,
            'other_costs' => 0,
            'items' => [['product_id' => $itens[0]->product_id, 'quantity' => 3, 'unit_cost' => 11]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $compra->refresh();
        $this->assertSame($fornecedor->id, $compra->supplier_id);
        $this->assertSame(1, $compra->items()->count());
        $this->assertSame(11.0, (float) $compra->items()->first()->effective_unit_cost);

        $arquivo = $compra->attachment_path;
        $this->actingAs($admin)->delete("/admin/compras/{$compra->id}")->assertRedirect('/admin/compras');
        $this->assertDatabaseMissing('purchase_orders', ['id' => $compra->id]);
        Storage::disk('local')->assertMissing($arquivo);
    }

    public function test_atualizar_custo_por_item_leva_para_as_variacoes_e_guarda_quem_aplicou(): void
    {
        $admin = $this->admin();
        $pai = Product::factory()->create(['cost_price' => 5]);
        $filhos = Product::factory()->count(2)->create(['parent_product_id' => $pai->id, 'cost_price' => 5]);
        $outro = Product::factory()->create(['cost_price' => 1]);

        $this->actingAs($admin)->post('/admin/compras', [
            'purchase_date' => '2026-11-10', 'supplier_name' => 'X', 'freight' => 10,
            'items' => [
                ['product_id' => $pai->id, 'quantity' => 2, 'unit_cost' => 10],
                ['product_id' => $outro->id, 'quantity' => 1, 'unit_cost' => 30],
            ],
        ])->assertSessionHasNoErrors();
        $compra = PurchaseOrder::query()->firstOrFail();
        $itemPai = $compra->items()->where('product_id', $pai->id)->firstOrFail();

        $this->actingAs($admin)->post("/admin/compras/{$compra->id}/atualizar-custo", ['item_ids' => [$itemPai->id]])->assertRedirect();

        $this->assertSame('12.00', (string) $pai->fresh()->cost_price);
        $filhos->each(fn ($f) => $this->assertSame('12.00', (string) $f->fresh()->cost_price));
        $this->assertSame('1.00', (string) $outro->fresh()->cost_price);
        $itemPai->refresh();
        $this->assertSame('5.00', (string) $itemPai->previous_cost);
        $this->assertSame($admin->id, $itemPai->cost_applied_by);
        $this->assertNotNull($itemPai->cost_applied_at);

        // Sem lista: todos os itens.
        $this->actingAs($admin)->post("/admin/compras/{$compra->id}/atualizar-custo")->assertRedirect();
        $this->assertSame('36.00', (string) $outro->fresh()->cost_price);
    }

    public function test_entrada_no_estoque_opcional_trava_itens_e_exclusao(): void
    {
        $admin = $this->admin();
        $produto = Product::factory()->create(['stock' => 3, 'cost_price' => 1]);

        $this->actingAs($admin)->post('/admin/compras', [
            'purchase_date' => '2026-11-10', 'supplier_name' => 'X', 'entrada_estoque' => 1,
            'items' => [['product_id' => $produto->id, 'quantity' => 4, 'unit_cost' => 10]],
        ])->assertSessionHasNoErrors();
        $compra = PurchaseOrder::query()->firstOrFail();

        $this->assertSame(7, (int) $produto->fresh()->stock);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $compra->status);

        // Recebida: muda o frete (recalcula o custo), mas não os itens.
        $this->actingAs($admin)->put("/admin/compras/{$compra->id}", [
            'purchase_date' => '2026-11-10', 'supplier_id' => $compra->supplier_id, 'freight' => 8, 'items' => [],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $compra->items()->count());
        $this->assertSame(12.0, (float) $compra->items()->first()->effective_unit_cost);

        $this->actingAs($admin)->delete("/admin/compras/{$compra->id}");
        $this->assertDatabaseHas('purchase_orders', ['id' => $compra->id]);

        // Sem a flag, o estoque não muda.
        $this->actingAs($admin)->post('/admin/compras', [
            'purchase_date' => '2026-11-10', 'supplier_name' => 'X',
            'items' => [['product_id' => $produto->id, 'quantity' => 4, 'unit_cost' => 10]],
        ]);
        $this->assertSame(7, (int) $produto->fresh()->stock);
    }

    public function test_importa_xml_da_nfe_casando_por_gtin_e_sku(): void
    {
        $admin = $this->admin();
        $fonte = Product::factory()->create(['name' => 'Fonte Pet Branca']);
        ProductFiscalData::create(['product_id' => $fonte->id, ...ProductFiscalData::defaultMeiAttributes(), 'gtin' => '7891234567895']);
        $filtro = Product::factory()->create(['sku' => 'SKU-CASA']);
        $fornecedor = Supplier::create(['name' => 'Distribuidora', 'document' => '12.345.678/0001-99', 'is_active' => true]);
        $xml = UploadedFile::fake()->createWithContent('nota.xml', file_get_contents(base_path('tests/fixtures/compras/nfe-compra-exemplo.xml')));

        $this->actingAs($admin)->post('/admin/compras/importar-xml', ['xml' => $xml])->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Compras/Form', false)
                ->where('importado.invoice_key', '35261112345678000199550010000012341000012345')
                ->where('importado.purchase_date', '2026-11-05')
                ->where('importado.supplier_id', $fornecedor->id)
                ->where('importado.freight', 20)
                ->where('importado.other_costs', 10)
                ->has('importado.items', 3)
                ->where('importado.items.0.product_id', $fonte->id)
                ->where('importado.items.0.quantity', 10)
                ->where('importado.items.0.unit_cost', 25.5)
                ->where('importado.items.1.product_id', $filtro->id)
                ->where('importado.items.2.product_id', null));

        $this->actingAs($admin)->post('/admin/compras/importar-xml', [
            'xml' => UploadedFile::fake()->createWithContent('ruim.xml', '<html>não é nota</html>'),
        ])->assertSessionHasErrors('xml');
    }

    public function test_so_admin(): void
    {
        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $compra = $this->compra($this->admin());

        $this->actingAs($gerente)->get('/admin/compras')->assertForbidden();
        $this->actingAs($gerente)->get("/admin/compras/{$compra->id}")->assertForbidden();
        $this->actingAs($gerente)->post('/admin/compras', ['purchase_date' => '2026-11-10'])->assertForbidden();
        $this->actingAs($gerente)->post("/admin/compras/{$compra->id}/atualizar-custo")->assertForbidden();
        $this->actingAs($gerente)->delete("/admin/compras/{$compra->id}")->assertForbidden();
        $this->assertDatabaseHas('purchase_orders', ['id' => $compra->id]);
    }
}
