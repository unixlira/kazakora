<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Financeiro\Models\MercadoPagoPagamento;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Fiscal\Services\SalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Venda cancelada/estornada com NF-e de venda viva e sem devolução (DAS de
 * setembro, 2026-10-19) e frete da nota de venda na devolução (#1725, #1872).
 */
class DevolucaoVendasCanceladasTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function venda(string $status = Order::STATUS_CANCELLED, string $origem = Order::ORIGIN_MERCADO_LIVRE, float $frete = 0.0, float $desconto = 0.0): Order
    {
        $this->seq++;
        $produto = Product::factory()->create();
        ProductFiscalData::create(['product_id' => $produto->id, ...ProductFiscalData::defaultMeiAttributes(), 'ncm' => '85076000']);

        $order = Order::create([
            'user_id' => User::factory()->create()->id, 'status' => $status, 'origin' => $origem,
            'external_order_id' => 'EXT-'.$this->seq, 'shipping_name' => 'Cliente', 'shipping_phone' => '1', 'shipping_zip' => '01001000',
            'shipping_street' => 'R', 'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'Boa Vista',
            'shipping_state' => 'RR', 'subtotal' => 39.00, 'discount_amount' => $desconto, 'shipping_cost' => $frete, 'total' => 39.00 - $desconto + $frete,
        ]);
        $order->items()->create(['product_id' => $produto->id, 'product_name' => 'Power Bank', 'product_price' => 39.00, 'quantity' => 1, 'subtotal' => 39.00, 'item_type' => OrderItem::TYPE_PRODUCT]);

        $chave = '3526096560459000010755002'.str_pad((string) (1000 + $this->seq), 9, '0', STR_PAD_LEFT).'1469013333';
        $xml = "invoices/{$order->id}/nfe-{$chave}.xml";
        Storage::disk('local')->put($xml, '<nfeProc><NFe><infNFe><det nItem="1"><prod><xProd>Power Bank</xProd><NCM>85076000</NCM></prod><imposto><ICMS><ICMSSN102><orig>0</orig></ICMSSN102></ICMS></imposto></det>'
            ."<total><ICMSTot><vProd>39.00</vProd><vFrete>{$frete}</vFrete><vDesc>{$desconto}</vDesc><vNF>".(39.00 - $desconto + $frete).'</vNF></ICMSTot></total></infNFe></NFe></nfeProc>');
        Invoice::create([
            'order_id' => $order->id, 'serie' => 2, 'numero' => 1000 + $this->seq, 'status' => Invoice::STATUS_AUTHORIZED, 'chave_acesso' => $chave,
            'origem' => Invoice::ORIGEM_PEDIDO, 'ambiente' => Invoice::AMBIENTE_PRODUCAO, 'autorizada_em' => now()->subDays(20), 'xml_path' => $xml,
            'valor_total' => 39.00 - $desconto + $frete,
        ]);

        return $order;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Bus::fake([GenerateInvoiceJob::class]);
    }

    public function test_devolucao_leva_o_frete_e_o_desconto_da_nota_de_venda(): void
    {
        $order = $this->venda(frete: 11.20, desconto: 20.00);
        $item = $order->items()->first();

        $devolucao = app(SalesReturnService::class)->registrar($order->invoice, [$item->id => 1], 'teste', voltaAoEstoque: false, emitirAgora: false);

        $this->assertSame('11.20', (string) $devolucao->shipping_cost);
        $this->assertSame('30.20', (string) $devolucao->total);
    }

    public function test_frete_do_pedido_fora_da_nota_nao_entra(): void
    {
        // Shopee: frete no pedido, nota sem vFrete.
        $order = $this->venda();
        $order->update(['shipping_cost' => 22.65]);

        $devolucao = app(SalesReturnService::class)->registrar($order->invoice, [$order->items()->first()->id => 1], 'teste', voltaAoEstoque: false, emitirAgora: false);

        $this->assertSame('39.00', (string) $devolucao->total);
    }

    public function test_simular_lista_sem_emitir_e_ignora_venda_valida(): void
    {
        $cancelada = $this->venda();
        $this->venda(status: Order::STATUS_COMPLETED);

        $this->artisan('devolucoes:vendas-canceladas', ['--simular' => true])
            ->expectsOutputToContain("#{$cancelada->id} ")
            ->expectsOutputToContain('1 caso(s)')
            ->assertSuccessful();

        $this->assertSame(0, Order::query()->where('origin', Order::ORIGIN_SALES_RETURN_INVOICE)->count());
        Bus::assertNotDispatched(GenerateInvoiceJob::class);
    }

    public function test_estornado_e_tiktok_entram(): void
    {
        $estornado = $this->venda(status: Order::STATUS_COMPLETED);
        $estornado->forceFill(['estornado_em' => now(), 'estorno_origem' => 'mercado_pago'])->save();
        $tiktok = $this->venda(origem: Order::ORIGIN_TIKTOK_SHOP);

        $this->artisan('devolucoes:vendas-canceladas', ['--simular' => true])
            ->expectsOutputToContain("#{$estornado->id} ")
            ->expectsOutputToContain("#{$tiktok->id} ")
            ->assertSuccessful();
    }

    public function test_emitir_exige_pedido(): void
    {
        $this->venda();

        $this->artisan('devolucoes:vendas-canceladas')->assertFailed();
        Bus::assertNotDispatched(GenerateInvoiceJob::class);
    }

    public function test_emite_o_pedido_indicado_uma_vez_so(): void
    {
        $order = $this->venda(frete: 11.20, desconto: 20.00);
        $outra = $this->venda();

        $this->artisan('devolucoes:vendas-canceladas', ['--pedido' => [$order->id]])->assertSuccessful();

        $devolucao = Order::query()->where('origin', Order::ORIGIN_SALES_RETURN_INVOICE)->sole();
        $this->assertSame($order->invoice->chave_acesso, $devolucao->fiscal_referenced_nfe_key);
        $this->assertSame('30.20', (string) $devolucao->total);
        Bus::assertDispatchedTimes(GenerateInvoiceJob::class, 1);

        // Já tem devolução: some da lista; a outra continua.
        $this->artisan('devolucoes:vendas-canceladas', ['--simular' => true])
            ->expectsOutputToContain("#{$outra->id} ")
            ->expectsOutputToContain('1 caso(s)')
            ->assertSuccessful();
    }

    public function test_estorno_coberto_pelo_ml_nao_emite(): void
    {
        $order = $this->venda();
        MercadoPagoPagamento::create(['mp_payment_id' => '177013132336', 'status' => 'refunded', 'status_detalhe' => 'bpp_covered', 'canal' => 'mercado_livre', 'valor' => 39.00, 'order_id' => $order->id]);

        $this->artisan('devolucoes:vendas-canceladas', ['--pedido' => [$order->id]])
            ->expectsOutputToContain('bpp_covered')
            ->assertSuccessful();

        $this->assertSame(0, Order::query()->where('origin', Order::ORIGIN_SALES_RETURN_INVOICE)->count());
    }
}
