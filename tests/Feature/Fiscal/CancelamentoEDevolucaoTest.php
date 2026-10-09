<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Modules\Fiscal\Services\SalesReturnService;
use App\Services\NFe\NFeCertificateService;
use App\Services\NFe\NFeWebserviceService;
use App\Services\NFe\NFeXmlBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use NFePHP\Common\Certificate;
use RuntimeException;
use Tests\TestCase;

/**
 * Orientação do contador (Contabilidade Galícia, 2026-10-08): cancelamento
 * até 24h normal, até 480h com multa e só se a mercadoria não saiu, depois
 * só devolução; devolução de pessoa física com declaração + NF-e de entrada
 * 1202/2202 referenciando a venda.
 */
class CancelamentoEDevolucaoTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE_VENDA = '35261065604590000107550020000030001000030001';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function empresa(): Company
    {
        return Company::query()->create([
            'razao_social' => 'KAZAKORA COMERCIO LTDA',
            'cnpj' => '65604590000107',
            'inscricao_estadual' => '158.571.233.113',
            'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL,
            'city' => 'São Paulo',
            'state' => 'SP',
            'street' => 'Rua Teste',
            'number' => '100',
            'neighborhood' => 'Centro',
            'zip' => '01000-000',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function venda(array $invoice = [], array $order = []): Invoice
    {
        $product = Product::factory()->create(['name' => 'Webcam Full HD', 'stock' => 3]);
        $other = Product::factory()->create(['name' => 'Saboneteira', 'stock' => 0]);

        $sale = Order::query()->create([
            'status' => Order::STATUS_COMPLETED,
            'origin' => Order::ORIGIN_STORE,
            'buyer_document' => '12345678909',
            'shipping_name' => 'José da Silva',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '74000-000',
            'shipping_street' => 'Rua das Flores',
            'shipping_number' => '10',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'Goiânia',
            'shipping_state' => 'GO',
            'subtotal' => 200,
            'shipping_cost' => 15,
            'discount_amount' => 20,
            'total' => 195,
            ...$order,
        ]);
        $sale->items()->create(['product_id' => $product->id, 'product_name' => 'Webcam Full HD', 'product_price' => 80, 'quantity' => 2, 'subtotal' => 160]);
        $sale->items()->create(['product_id' => $other->id, 'product_name' => 'Saboneteira', 'product_price' => 40, 'quantity' => 1, 'subtotal' => 40]);

        return Invoice::query()->create([
            'order_id' => $sale->id,
            'origem' => Invoice::ORIGEM_PEDIDO,
            'status' => Invoice::STATUS_AUTHORIZED,
            'ambiente' => 'producao',
            'serie' => 2,
            'numero' => 3000,
            'valor_total' => 195,
            'chave_acesso' => self::CHAVE_VENDA,
            'protocolo_autorizacao' => '135260000000001',
            'autorizada_em' => now()->subHours(2),
            ...$invoice,
        ]);
    }

    private function sefazCancela(string $cStat): void
    {
        $certificates = Mockery::mock(NFeCertificateService::class);
        $certificates->shouldReceive('load')->andReturn(Mockery::mock(Certificate::class));
        $this->app->instance(NFeCertificateService::class, $certificates);

        $webservice = Mockery::mock(NFeWebserviceService::class);
        $webservice->shouldReceive('cancelarComEvento')->andReturn([
            'request' => '<envEvento/>',
            'response' => "<retEnvEvento xmlns=\"http://www.portalfiscal.inf.br/nfe\"><retEvento><infEvento><cStat>{$cStat}</cStat><xMotivo>ok</xMotivo><nProt>135269999999999</nProt></infEvento></retEvento></retEnvEvento>",
        ]);
        $this->app->instance(NFeWebserviceService::class, $webservice);
    }

    public function test_dentro_de_24h_cancela_normal_e_guarda_o_xml_do_evento(): void
    {
        $this->sefazCancela('135');
        $invoice = $this->venda();

        $cancelled = app(InvoiceService::class)->cancelInvoice($invoice, 'Cliente desistiu da compra antes do envio');

        $this->assertSame(Invoice::STATUS_CANCELLED, $cancelled->status);
        $this->assertFalse($cancelled->cancelamento_extemporaneo);
        Storage::disk('local')->assertExists($cancelled->xml_cancelamento_path);

        $this->actingAs($this->admin())
            ->get("/admin/notas-fiscais/{$invoice->id}/cancelamento-xml")
            ->assertOk();
    }

    public function test_depois_de_24h_so_cancela_confirmando_que_a_mercadoria_nao_saiu(): void
    {
        $this->sefazCancela('155');
        $invoice = $this->venda(['autorizada_em' => now()->subHours(30)]);

        $this->assertSame(Invoice::JANELA_EXTEMPORANEA, $invoice->janelaDeCancelamento());
        // 1% de 195 = 1,95; o mínimo de 6 UFESPs (6 x 38,42, UFESP 2026) vale mais.
        $this->assertSame(230.52, $invoice->multaCancelamentoExtemporaneo());

        try {
            app(InvoiceService::class)->cancelInvoice($invoice, 'Cliente desistiu da compra antes do envio');
            $this->fail('Cancelou fora do prazo sem confirmação.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('R$ 230,52', $exception->getMessage());
        }

        $this->actingAs($this->admin())
            ->post("/admin/notas-fiscais/{$invoice->id}/cancelar", ['motivo' => 'Cliente desistiu da compra antes do envio', 'fora_do_prazo' => true])
            ->assertSessionHas('success');

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->status);
        $this->assertTrue($invoice->cancelamento_extemporaneo);
    }

    public function test_depois_de_480h_nao_cancela_e_manda_registrar_devolucao(): void
    {
        $this->sefazCancela('155');
        $invoice = $this->venda(['autorizada_em' => now()->subHours(481)]);

        $this->actingAs($this->admin())
            ->post("/admin/notas-fiscais/{$invoice->id}/cancelar", ['motivo' => 'Cliente desistiu da compra antes do envio', 'fora_do_prazo' => true])
            ->assertSessionHas('error', fn ($message) => str_contains($message, '480h'));

        $this->assertSame(Invoice::STATUS_AUTHORIZED, $invoice->fresh()->status);
    }

    public function test_devolucao_parcial_cria_nota_de_entrada_referenciando_a_venda(): void
    {
        Queue::fake();
        $invoice = $this->venda();
        $webcam = $invoice->order->items->firstWhere('product_name', 'Webcam Full HD');

        $this->actingAs($this->admin())
            ->post("/admin/notas-fiscais/{$invoice->id}/devolucao", [
                'itens' => [$webcam->id => 1],
                'motivo' => 'Produto chegou com defeito',
                'volta_ao_estoque' => true,
                'declaracao' => UploadedFile::fake()->create('declaracao.pdf', 50, 'application/pdf'),
            ])
            ->assertSessionHas('success');

        $return = Order::query()->where('origin', Order::ORIGIN_SALES_RETURN_INVOICE)->sole();
        $this->assertSame(['sales_return', self::CHAVE_VENDA, 'José da Silva', '12345678909', 'GO'], [$return->fiscal_operation_type, $return->fiscal_referenced_nfe_key, $return->shipping_name, $return->buyer_document, $return->shipping_state]);
        // 80 de produto; desconto da venda na mesma proporção (20 x 80/200); frete não volta.
        $this->assertSame(['80.00', '8.00', '0.00', '72.00'], [$return->subtotal, $return->discount_amount, $return->shipping_cost, $return->total]);
        $this->assertSame([[$webcam->product_id, 1]], $return->items->map(fn ($item) => [$item->product_id, $item->quantity])->all());
        $this->assertStringContainsString('NF-e nº 3000 série 2', $return->fiscal_additional_info);
        Storage::disk('local')->assertExists($return->return_declaration_path);
        $this->assertSame(4, $webcam->product->fresh()->stock);
        Queue::assertPushed(GenerateInvoiceJob::class, fn ($job) => $job->orderId === $return->id);
    }

    public function test_xml_da_devolucao_sai_como_entrada_2202_referenciando_a_venda(): void
    {
        Queue::fake();
        config(['nfe.ambiente' => 'producao']);
        Http::fake(['servicodados.ibge.gov.br/*' => Http::response([['id' => 3550308, 'nome' => 'São Paulo'], ['id' => 5208707, 'nome' => 'Goiânia']])]);
        $this->empresa();
        $invoice = $this->venda();
        $webcam = $invoice->order->items->firstWhere('product_name', 'Webcam Full HD');
        ProductFiscalData::query()->create([
            'product_id' => $webcam->product_id, 'ncm' => '85258929', 'cfop' => '5102', 'cfop_outros_estados' => '6108',
            'origem' => 0, 'unidade_tributavel' => 'UN', 'icms_situacao_tributaria' => '102', 'pis_situacao_tributaria' => '49', 'cofins_situacao_tributaria' => '49',
        ]);

        $return = app(SalesReturnService::class)->registrar($invoice, [$webcam->id => 1], 'Produto chegou com defeito', false);
        $xml = app(NFeXmlBuilderService::class)->build($return->fresh(['items']), 3001)['xml'];

        $this->assertStringContainsString('<tpNF>0</tpNF>', $xml);
        $this->assertStringContainsString('<finNFe>4</finNFe>', $xml);
        $this->assertStringContainsString('<refNFe>'.self::CHAVE_VENDA.'</refNFe>', $xml);
        $this->assertStringContainsString('<CFOP>2202</CFOP>', $xml);
        $this->assertStringContainsString('<CSOSN>900</CSOSN>', $xml);
        $this->assertStringContainsString('<vNF>72.00</vNF>', $xml);
    }

    public function test_nao_devolve_mais_do_que_foi_vendido(): void
    {
        Queue::fake();
        $invoice = $this->venda();
        $webcam = $invoice->order->items->firstWhere('product_name', 'Webcam Full HD');
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/notas-fiscais/{$invoice->id}/devolucao", ['itens' => [$webcam->id => 2], 'motivo' => 'Produto chegou com defeito'])->assertSessionHas('success');
        $this->actingAs($admin)
            ->post("/admin/notas-fiscais/{$invoice->id}/devolucao", ['itens' => [$webcam->id => 1], 'motivo' => 'Produto chegou com defeito'])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'só 0 unidade'));

        $this->assertSame(1, Order::query()->where('origin', Order::ORIGIN_SALES_RETURN_INVOICE)->count());
    }

    public function test_declaracao_sai_preenchida_com_cliente_nota_e_itens(): void
    {
        $this->empresa();
        $invoice = $this->venda();
        $webcam = $invoice->order->items->firstWhere('product_name', 'Webcam Full HD');

        $this->actingAs($this->admin())
            ->get("/admin/notas-fiscais/{$invoice->id}/declaracao-devolucao?itens[{$webcam->id}]=1&motivo=Defeito")
            ->assertOk()
            ->assertSee('José da Silva')
            ->assertSee('123.456.789-09')
            ->assertSee('KAZAKORA COMERCIO LTDA')
            ->assertSee('65.604.590/0001-07')
            ->assertSee(self::CHAVE_VENDA)
            ->assertSee('Webcam Full HD')
            ->assertDontSee('Saboneteira')
            ->assertSee('Defeito');
    }

    public function test_tela_da_nota_mostra_prazos_e_itens_para_devolver(): void
    {
        $invoice = $this->venda(['autorizada_em' => now()->subHours(30)], ['origin' => Order::ORIGIN_TIKTOK_SHOP]);

        $this->actingAs($this->admin())
            ->get("/admin/notas-fiscais/{$invoice->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Invoices/Show', false)
                ->where('invoice.janela_cancelamento', 'extemporanea')
                ->where('invoice.can_cancel', true)
                ->where('invoice.multa_cancelamento', 230.52)
                ->where('invoice.devolucao.tipo', 'venda')
                ->where('invoice.devolucao.canal_emite_devolucao', true)
                ->where('invoice.devolucao.itens.0.disponivel', 2));
    }
}
