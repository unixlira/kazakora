<?php

namespace Tests\Feature\Fiscal;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Modules\Fiscal\Support\PackDoPedido;
use App\Modules\Marketplace\Support\MercadoLivrePackInvoiceGate;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Services\MercadoLivre\MercadoLivreClient;
use App\Services\NFe\NFeCertificateService;
use App\Services\NFe\NFeXmlBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Carrinho do Mercado Livre = uma NF-e só.
 *
 * Caso real que originou isto (2026-09-11): pack 2000014906196989, pedidos
 * #1588 (Dispensador, R$ 47,69) e #1589 (Power Bank, R$ 49,99). Saiu uma nota
 * por pedido, o ML recusou as duas por valor e a venda ficou 4 dias parada.
 */
class MercadoLivrePackInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private const PACK = '2000014906196989';

    private function pedido(string $externo, float $valor, string $produto, array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => $externo,
            'channel_pack_id' => self::PACK,
            'buyer_document' => '04285518740',
            'shipping_name' => 'Ana Rosa',
            'shipping_phone' => '21999999999',
            'shipping_zip' => '24934105',
            'shipping_street' => 'Rua X',
            'shipping_number' => '39',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'Maricá',
            'shipping_state' => 'RJ',
            'subtotal' => $valor,
            'shipping_cost' => 0,
            'total' => $valor,
        ], $extra));

        $order->items()->create([
            'product_name' => $produto,
            'product_price' => $valor,
            'quantity' => 1,
            'subtotal' => $valor,
            'item_type' => OrderItem::TYPE_PRODUCT,
        ]);

        return $order;
    }

    /** @return array{0: Order, 1: Order} */
    private function carrinho(): array
    {
        return [
            $this->pedido('2000018325566408', 47.69, 'Dispensador de sabão'),
            $this->pedido('2000018325568030', 49.99, 'Power Bank'),
        ];
    }

    private function nota(Order $order, string $status, float $valor, int $numero): Invoice
    {
        return Invoice::create([
            'order_id' => $order->id,
            'status' => $status,
            'serie' => 2,
            'numero' => $numero,
            'ambiente' => 'producao',
            'valor_total' => $valor,
            'chave_acesso' => str_pad((string) $numero, 44, '3', STR_PAD_LEFT),
        ]);
    }

    private function jobHandle(int $orderId): void
    {
        $orderImport = Mockery::mock(OrderImportService::class);
        $orderImport->shouldReceive('refreshBuyerInfo')->andReturnNull();

        (new GenerateInvoiceJob($orderId))->handle(app(InvoiceService::class), app(OrderFulfillmentTimeline::class), $orderImport);
    }

    private function gateQueConfirmaCarrinhoCompleto(): void
    {
        $gate = Mockery::mock(MercadoLivrePackInvoiceGate::class);
        $gate->shouldReceive('packId')->andReturnUsing(fn (Order $order) => $order->channel_pack_id);
        $gate->shouldReceive('garantirCompleto')->andReturnNull();
        $this->app->instance(MercadoLivrePackInvoiceGate::class, $gate);
    }

    public function test_nota_do_titular_sai_com_os_itens_e_o_valor_do_carrinho_inteiro(): void
    {
        Storage::fake('local');
        [$titular] = $this->carrinho();

        $montado = null;
        $xmlBuilder = Mockery::mock(NFeXmlBuilderService::class);
        // Duas montagens na primeira emissão (reserva do número + rebuild da
        // pendente, comportamento de sempre do issue()) — as duas têm que
        // sair com o carrinho inteiro; $montado guarda a última.
        $xmlBuilder->shouldReceive('build')->atLeast()->once()->andReturnUsing(function (Order $order, int $numero) use (&$montado) {
            $montado = $order;

            return ['xml' => '<xml/>', 'chave' => str_repeat('4', 44)];
        });
        $this->app->instance(NFeXmlBuilderService::class, $xmlBuilder);

        $certificado = Mockery::mock(NFeCertificateService::class);
        $certificado->shouldReceive('isConfigured')->andReturn(false);
        $this->app->instance(NFeCertificateService::class, $certificado);

        $invoice = app(InvoiceService::class)->issue($titular->fresh());

        $this->assertSame($titular->id, $invoice->order_id);
        $this->assertSame('97.68', $invoice->valor_total);
        $this->assertSame(97.68, (float) $montado->total);
        $this->assertSame(['Dispensador de sabão', 'Power Bank'], $montado->items->pluck('product_name')->all());
        $this->assertSame(1, Invoice::query()->count());

        // O pedido de verdade continua com o próprio valor.
        $this->assertSame('47.69', (string) $titular->fresh()->total);
    }

    public function test_pedido_do_carrinho_que_nao_e_titular_nunca_emite_nota_propria(): void
    {
        [, $irmao] = $this->carrinho();

        $this->expectException(RuntimeException::class);

        app(PackDoPedido::class)->pedidoFiscal($irmao->fresh());
    }

    public function test_irmao_cancelado_fica_fora_e_o_pedido_volta_a_ser_avulso(): void
    {
        [$titular, $irmao] = $this->carrinho();
        $irmao->update(['status' => Order::STATUS_CANCELLED]);

        $fiscal = app(PackDoPedido::class)->pedidoFiscal($titular->fresh());

        $this->assertSame(47.69, (float) $fiscal->total);
        $this->assertTrue($fiscal->exists);
    }

    public function test_job_do_irmao_delega_ao_titular_sem_emitir(): void
    {
        Queue::fake();
        $this->gateQueConfirmaCarrinhoCompleto();
        [$titular, $irmao] = $this->carrinho();

        $invoices = Mockery::mock(InvoiceService::class);
        $invoices->shouldNotReceive('issue');
        $this->app->instance(InvoiceService::class, $invoices);

        $this->jobHandle($irmao->id);

        Queue::assertPushed(GenerateInvoiceJob::class, fn (GenerateInvoiceJob $job) => $job->orderId === $titular->id);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_carrinho_com_notas_separadas_bloqueia_e_nao_emite_por_cima(): void
    {
        Queue::fake();
        $this->gateQueConfirmaCarrinhoCompleto();
        [$titular, $irmao] = $this->carrinho();
        $this->nota($titular, Invoice::STATUS_REJECTED, 47.69, 2028);
        $this->nota($irmao, Invoice::STATUS_AUTHORIZED, 49.99, 2029);

        $invoices = Mockery::mock(InvoiceService::class);
        $invoices->shouldNotReceive('issue');
        $this->app->instance(InvoiceService::class, $invoices);

        $this->jobHandle($titular->id);

        $this->assertDatabaseHas('order_fulfillment_events', [
            'order_id' => $titular->id,
            'step' => OrderFulfillmentEvent::STEP_INVOICE_ISSUED,
            'status' => OrderFulfillmentEvent::STATUS_FAILED,
        ]);
        Queue::assertNotPushed(GenerateInvoiceJob::class);
    }

    public function test_nota_autorizada_que_nao_cobre_o_carrinho_inteiro_bloqueia(): void
    {
        [$titular] = $this->carrinho();
        $this->nota($titular, Invoice::STATUS_AUTHORIZED, 47.69, 2028);

        $pack = app(PackDoPedido::class);

        $this->assertNotNull($pack->motivoDeBloqueio($titular->fresh()));
        $this->assertNull($pack->titular($titular->fresh()));
    }

    public function test_carrinho_com_nota_cancelada_ja_foi_tratado_a_mao_e_nao_emite_sozinho(): void
    {
        [$titular, $irmao] = $this->carrinho();
        $this->nota($titular, Invoice::STATUS_CANCELLED, 47.69, 2028);
        $this->nota($irmao, Invoice::STATUS_CANCELLED, 49.99, 2029);

        $this->expectException(RuntimeException::class);

        app(PackDoPedido::class)->pedidoFiscal($titular->fresh());
    }

    public function test_gate_importa_o_pedido_do_carrinho_que_ainda_nao_chegou(): void
    {
        $primeiro = $this->pedido('2000018325566408', 47.69, 'Dispensador de sabão');

        $client = Mockery::mock(MercadoLivreClient::class);
        $client->shouldReceive('get')->with('packs/'.self::PACK)->andReturn([
            'orders' => [['id' => 2000018325568030], ['id' => 2000018325566408]],
        ]);
        $this->app->instance(MercadoLivreClient::class, $client);

        $import = Mockery::mock(OrderImportService::class);
        $import->shouldReceive('import')->once()->with(Order::ORIGIN_MERCADO_LIVRE, '2000018325568030')
            ->andReturnUsing(fn () => $this->pedido('2000018325568030', 49.99, 'Power Bank', ['channel_pack_id' => null]));
        $this->app->instance(OrderImportService::class, $import);

        app(MercadoLivrePackInvoiceGate::class)->garantirCompleto($primeiro, self::PACK);

        $this->assertSame(2, Order::query()->where('channel_pack_id', self::PACK)->count());
        $this->assertTrue(app(PackDoPedido::class)->ehCarrinho($primeiro));
    }

    public function test_gate_segura_a_nota_enquanto_falta_pedido_do_carrinho(): void
    {
        $primeiro = $this->pedido('2000018325566408', 47.69, 'Dispensador de sabão');

        $client = Mockery::mock(MercadoLivreClient::class);
        $client->shouldReceive('get')->andReturn(['orders' => [['id' => 2000018325568030], ['id' => 2000018325566408]]]);
        $this->app->instance(MercadoLivreClient::class, $client);

        $import = Mockery::mock(OrderImportService::class);
        $import->shouldReceive('import')->andReturnNull();
        $this->app->instance(OrderImportService::class, $import);

        $this->expectException(RuntimeException::class);

        app(MercadoLivrePackInvoiceGate::class)->garantirCompleto($primeiro, self::PACK);
    }

    public function test_cancelar_um_pedido_do_carrinho_nao_cancela_a_nota_do_carrinho(): void
    {
        [$titular, $irmao] = $this->carrinho();
        $this->nota($titular, Invoice::STATUS_AUTHORIZED, 97.68, 2534);
        $irmao->update(['status' => Order::STATUS_CANCELLED]);

        // Irmão cancelado: o item dele está na nota do carrinho, que segue
        // valendo pro titular — avisa (devolução parcial é do contador).
        $aviso = app(PackDoPedido::class)->avisoDeCancelamento($irmao->fresh());
        $this->assertStringContainsString('nº 2534', (string) $aviso);

        $irmao->update(['status' => Order::STATUS_PAID]);
        $titular->update(['status' => Order::STATUS_CANCELLED]);

        $aviso = app(PackDoPedido::class)->avisoDeCancelamento($titular->fresh());
        $this->assertStringContainsString('nº 2534', (string) $aviso);
        $this->assertStringContainsString("#{$irmao->id}", (string) $aviso);
    }

    public function test_retry_stuck_nao_redispara_o_pedido_coberto_pela_nota_do_carrinho(): void
    {
        Queue::fake();
        [$titular, $irmao] = $this->carrinho();
        $this->nota($titular, Invoice::STATUS_PENDING, 97.68, 2534);

        $this->artisan('nfe:retry-stuck', ['--forcar' => true])->assertSuccessful();

        Queue::assertPushed(GenerateInvoiceJob::class, fn (GenerateInvoiceJob $job) => $job->orderId === $titular->id);
        Queue::assertNotPushed(GenerateInvoiceJob::class, fn (GenerateInvoiceJob $job) => $job->orderId === $irmao->id);
    }
}
