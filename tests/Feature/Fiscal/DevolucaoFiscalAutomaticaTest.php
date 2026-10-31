<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Marketplace\Models\MarketplaceReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Nota de devolução automática (2026-10-11): abriu devolução → NF-e de
 * entrada montada; produto chegou → emite; não voltou → descarta.
 */
class DevolucaoFiscalAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    private function venda(string $origem = Order::ORIGIN_MERCADO_LIVRE, ?string $ncm = '85044010', int $serie = 2): Order
    {
        $produto = Product::factory()->create();
        ProductFiscalData::create(['product_id' => $produto->id, ...ProductFiscalData::defaultMeiAttributes(), 'ncm' => $ncm]);

        $order = Order::create([
            'user_id' => User::factory()->create()->id, 'status' => Order::STATUS_COMPLETED, 'origin' => $origem,
            'external_order_id' => 'ML-1', 'shipping_name' => 'Cliente', 'shipping_phone' => '1', 'shipping_zip' => '01001000',
            'shipping_street' => 'R', 'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'SP',
            'shipping_state' => 'RJ', 'subtotal' => 49.99, 'total' => 49.99,
        ]);
        $order->items()->create(['product_id' => $produto->id, 'product_name' => 'Power Bank', 'product_price' => 49.99, 'quantity' => 1, 'subtotal' => 49.99, 'item_type' => OrderItem::TYPE_PRODUCT]);
        Invoice::create([
            'order_id' => $order->id, 'serie' => $serie, 'numero' => 1506, 'status' => Invoice::STATUS_AUTHORIZED,
            'chave_acesso' => '35260865604590000107550020000015061077037900', 'origem' => Invoice::ORIGEM_PEDIDO, 'ambiente' => Invoice::AMBIENTE_PRODUCAO,
        ]);

        return $order;
    }

    private function caso(Order $order): MarketplaceReturn
    {
        return MarketplaceReturn::create([
            'channel' => $order->origin, 'external_id' => 'CLAIM-1', 'order_id' => $order->id, 'external_order_id' => $order->external_order_id,
            'kind' => MarketplaceReturn::KIND_DEVOLUCAO, 'reason_label' => 'Produto com defeito', 'situacao' => MarketplaceReturn::AGUARDANDO_ENVIO,
        ]);
    }

    public function test_abriu_devolucao_prepara_e_emite_quando_chega(): void
    {
        Bus::fake([GenerateInvoiceJob::class]);
        $caso = $this->caso($this->venda());

        $caso->refresh();
        $this->assertSame('preparada', $caso->devolucao_fiscal_status);
        $rascunho = Order::find($caso->devolucao_order_id);
        $this->assertSame(Order::ORIGIN_SALES_RETURN_INVOICE, $rascunho->origin);
        $this->assertSame('35260865604590000107550020000015061077037900', $rascunho->fiscal_referenced_nfe_key);
        Bus::assertNotDispatched(GenerateInvoiceJob::class);

        $caso->update(['situacao' => MarketplaceReturn::ENTREGUE]);

        Bus::assertDispatched(GenerateInvoiceJob::class, fn ($job) => true);
        $this->assertSame('emitida', $caso->fresh()->devolucao_fiscal_status);
    }

    public function test_cancelada_descarta_o_rascunho(): void
    {
        Bus::fake([GenerateInvoiceJob::class]);
        $caso = $this->caso($this->venda());
        $rascunhoId = $caso->fresh()->devolucao_order_id;

        $caso->update(['situacao' => MarketplaceReturn::CANCELADA]);

        $this->assertNull(Order::find($rascunhoId));
        $this->assertSame('descartada', $caso->fresh()->devolucao_fiscal_status);
        Bus::assertNotDispatched(GenerateInvoiceJob::class);
    }

    public function test_tiktok_e_nota_do_bling_ficam_com_o_canal(): void
    {
        Bus::fake([GenerateInvoiceJob::class]);

        $this->assertSame('pelo_canal', $this->caso($this->venda(Order::ORIGIN_TIKTOK_SHOP))->fresh()->devolucao_fiscal_status);
        $this->assertNull(MarketplaceReturn::first()->devolucao_order_id);
    }

    public function test_item_sem_cadastro_pega_o_ncm_do_xml_da_venda(): void
    {
        Bus::fake([GenerateInvoiceJob::class]);
        \Illuminate\Support\Facades\Storage::fake('local');

        $order = $this->venda();
        // Produto excluído do catálogo (caso real: produto 36).
        $order->items()->update(['product_name' => 'Carregador USB-C Duplo 50W']);
        Product::find($order->items()->first()->product_id)->delete();
        \Illuminate\Support\Facades\Storage::disk('local')->put('invoices/x/venda.xml',
            '<nfeProc><det nItem="1"><prod><xProd>Carregador USB-C Duplo 50W</xProd><NCM>85044010</NCM></prod><imposto><ICMS><ICMSSN102><orig>2</orig></ICMSSN102></ICMS></imposto></det></nfeProc>');
        $order->invoice->update(['xml_path' => 'invoices/x/venda.xml']);

        $caso = $this->caso($order)->fresh();

        $item = Order::find($caso->devolucao_order_id)->items->first();
        $this->assertSame('85044010', $item->ncm);
        $this->assertSame(2, (int) $item->origem_mercadoria);
    }

    public function test_sem_ncm_fica_pendente(): void
    {
        Bus::fake([GenerateInvoiceJob::class]);

        $caso = $this->caso($this->venda(ncm: null))->fresh();

        $this->assertSame('pendencia', $caso->devolucao_fiscal_status);
        $this->assertStringContainsString('NCM', $caso->devolucao_fiscal_obs);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_botao_emite_na_hora_sem_o_produto_chegar_e_segundo_clique_nao_dispara(): void
    {
        Bus::fake([GenerateInvoiceJob::class]);
        $caso = $this->caso($this->venda())->fresh();
        $rascunhoId = $caso->devolucao_order_id;
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/devolucoes/{$caso->id}", ['acao' => 'emitir_nota'])->assertSessionHas('success');

        Bus::assertDispatchedTimes(GenerateInvoiceJob::class, 1);
        Bus::assertDispatched(GenerateInvoiceJob::class, fn ($job) => $job->orderId === $rascunhoId);
        $caso->refresh();
        $this->assertSame('emitida', $caso->devolucao_fiscal_status);
        $this->assertSame($rascunhoId, $caso->devolucao_order_id);
        $this->assertSame(MarketplaceReturn::AGUARDANDO_ENVIO, $caso->situacao);
        $this->assertSame($admin->id, $caso->events()->where('description', 'like', '%Enviada à SEFAZ%')->sole()->user_id);

        $this->actingAs($admin)->post("/admin/devolucoes/{$caso->id}", ['acao' => 'emitir_nota'])->assertSessionHas('error');

        Bus::assertDispatchedTimes(GenerateInvoiceJob::class, 1);
    }

    public function test_dois_cliques_ao_mesmo_tempo_so_um_passa(): void
    {
        Bus::fake([GenerateInvoiceJob::class]);
        $caso = $this->caso($this->venda())->fresh();
        $mesmoCasoNoOutroClique = MarketplaceReturn::find($caso->id);
        $servico = app(\App\Modules\Fiscal\Services\DevolucaoFiscalAutomatica::class);

        $this->assertNull($servico->emitirAgora($caso));
        // Ainda lê "preparada" na memória: quem barra é o update condicional.
        $this->assertSame('preparada', $mesmoCasoNoOutroClique->devolucao_fiscal_status);
        $this->assertNotNull($servico->emitirAgora($mesmoCasoNoOutroClique));

        Bus::assertDispatchedTimes(GenerateInvoiceJob::class, 1);
    }

    public function test_botao_recusa_status_diferente_de_preparada(): void
    {
        Bus::fake([GenerateInvoiceJob::class]);
        $caso = $this->caso($this->venda(ncm: null))->fresh();
        $this->assertSame('pendencia', $caso->devolucao_fiscal_status);

        $this->actingAs($this->admin())->post("/admin/devolucoes/{$caso->id}", ['acao' => 'emitir_nota'])->assertSessionHas('error');

        $this->assertSame('pendencia', $caso->fresh()->devolucao_fiscal_status);
        Bus::assertNotDispatched(GenerateInvoiceJob::class);
    }

    public function test_botao_recusa_rascunho_que_ja_tem_nota(): void
    {
        Bus::fake([GenerateInvoiceJob::class]);
        $caso = $this->caso($this->venda())->fresh();
        Invoice::create([
            'order_id' => $caso->devolucao_order_id, 'serie' => 2, 'numero' => 1507, 'status' => Invoice::STATUS_REJECTED,
            'origem' => Invoice::ORIGEM_PEDIDO, 'ambiente' => Invoice::AMBIENTE_PRODUCAO,
        ]);

        $this->actingAs($this->admin())->post("/admin/devolucoes/{$caso->id}", ['acao' => 'emitir_nota'])->assertSessionHas('error');

        $this->assertSame('preparada', $caso->fresh()->devolucao_fiscal_status);
        Bus::assertNotDispatched(GenerateInvoiceJob::class);
    }

    public function test_botao_recusa_rascunho_que_nao_existe_mais(): void
    {
        Bus::fake([GenerateInvoiceJob::class]);
        $caso = $this->caso($this->venda())->fresh();
        MarketplaceReturn::query()->whereKey($caso->id)->update(['devolucao_order_id' => null]);

        $this->actingAs($this->admin())->post("/admin/devolucoes/{$caso->id}", ['acao' => 'emitir_nota'])->assertSessionHas('error');

        $this->assertSame('preparada', $caso->fresh()->devolucao_fiscal_status);
        Bus::assertNotDispatched(GenerateInvoiceJob::class);
    }

    public function test_tela_nao_mostra_o_pedido_tecnico_nos_textos_gravados_antes(): void
    {
        Bus::fake([GenerateInvoiceJob::class]);
        $caso = $this->caso($this->venda())->fresh();
        $velho = 'NF-e de entrada montada (pedido #3595); sai quando o produto chegar.';
        MarketplaceReturn::query()->whereKey($caso->id)->update(['devolucao_fiscal_obs' => $velho]);
        $caso->events()->create(['situacao' => $caso->situacao, 'description' => "Nota de devolução: {$velho}", 'happened_at' => now()]);

        $linha = $this->actingAs($this->admin())->get('/admin/devolucoes')->assertOk()->viewData('page')['props']['casos'][0];

        $textos = [$linha['notaDevolucao']['obs'], ...array_column($linha['historico'], 'texto')];
        $this->assertStringNotContainsString('#3595', implode(' ', $textos));
        $this->assertStringNotContainsString('montada', implode(' ', $textos));
        $this->assertSame(\App\Modules\Fiscal\Services\DevolucaoFiscalAutomatica::OBS_PREPARADA, $linha['notaDevolucao']['obs']);
        // O banco continua como estava: a correção é só na exibição.
        $this->assertSame($velho, $caso->fresh()->devolucao_fiscal_obs);
    }

    public function test_texto_novo_nao_traz_numero_de_pedido(): void
    {
        Bus::fake([GenerateInvoiceJob::class]);
        $caso = $this->caso($this->venda())->fresh();

        $this->assertStringNotContainsString('#', $caso->devolucao_fiscal_obs);
        $this->assertStringContainsString('ainda não foi emitida', $caso->devolucao_fiscal_obs);
    }
}
