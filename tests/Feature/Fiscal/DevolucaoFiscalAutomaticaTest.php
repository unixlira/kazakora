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
        $order->items()->update(['product_id' => null, 'product_name' => 'Carregador USB-C Duplo 50W']);
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
}
