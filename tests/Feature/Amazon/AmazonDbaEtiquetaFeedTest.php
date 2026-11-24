<?php

namespace Tests\Feature\Amazon;

use App\Modules\Admin\Models\AlertaAdmin;
use App\Modules\Admin\Support\AlertasAdmin;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Marketplace\Jobs\AguardarAgendamentoDbaJob;
use App\Modules\Marketplace\Jobs\CheckShipmentLabelJob;
use App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\AmazonDbaShipping;
use App\Modules\Marketplace\Support\LabelFetchService;
use App\Modules\Operacional\Mail\AgendarDbaNoSellerCentral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DBA da Amazon (24/11/2026, pedido #4474): etiqueta pelo feed
 * POST_EASYSHIP_DOCUMENTS quando o pacote existe sem PDF, e pedido que a
 * API não deixa agendar ("operation is not supported") vira alerta + e-mail
 * + nova conferência a cada 30 min, sem falhar calado.
 */
class AmazonDbaEtiquetaFeedTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://sellingpartnerapi.test';

    private const PEDIDO = '701-6794015-3038663';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.amazon.sp_api_base_url' => self::BASE,
            'services.amazon.marketplace_id' => 'A2Q3Y263D00KWC',
            'services.bling.amazon_loja_id' => null,
            'services.alerta_coleta_email' => ['ativo' => true, 'para' => 'lira@example.com'],
            'app.timezone' => 'America/Sao_Paulo',
        ]);

        Storage::fake('local');
        Mail::fake();

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_AMAZON, 'status' => MarketplaceAccount::STATUS_CONNECTED, 'seller_id' => 'A1VENDEDOR',
            'access_token' => 'lwa-token', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
        ]);
    }

    private function pedidoComNota(): Order
    {
        Company::create([
            'razao_social' => 'Kazakora Ltda', 'nome_fantasia' => 'KazaKora', 'cnpj' => '65604590000107', 'regime_tributario' => 'mei',
            'email' => 'loja@kazakora.com', 'phone' => '11999999999', 'zip' => '03187-040', 'street' => 'Rua Mogi Mirim', 'number' => '20',
            'neighborhood' => 'Mooca', 'city' => 'São Paulo', 'state' => 'SP',
        ]);

        $produto = Product::factory()->create(['sku' => 'KZ-1']);
        ProductFiscalData::create(['product_id' => $produto->id, 'peso_bruto' => 0.2, 'altura_cm' => 5, 'largura_cm' => 10, 'profundidade_cm' => 15]);

        $order = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_AMAZON, 'external_order_id' => self::PEDIDO,
            'shipping_name' => 'Maria', 'shipping_phone' => '1', 'shipping_zip' => '13010000', 'shipping_street' => 'Rua',
            'shipping_number' => '1', 'shipping_neighborhood' => 'Centro', 'shipping_city' => 'Campinas', 'shipping_state' => 'SP',
            'subtotal' => 100, 'total' => 100,
        ]);
        $order->items()->create(['product_id' => $produto->id, 'product_name' => 'Capinha', 'product_price' => 29.9, 'quantity' => 2, 'subtotal' => 59.8]);

        Storage::disk('local')->put('nfe/3467.xml', '<nfeProc>xml</nfeProc>');
        Invoice::create([
            'order_id' => $order->id, 'company_id' => Company::first()->id, 'status' => Invoice::STATUS_AUTHORIZED,
            'chave_acesso' => '35261165604590000107550020000034671065750212', 'xml_path' => 'nfe/3467.xml',
            'numero' => 3467, 'serie' => 2, 'valor_total' => 100,
        ]);

        return $order->fresh();
    }

    /** @return array<string, mixed> */
    private function pacote(): array
    {
        return [
            'scheduledPackageId' => ['amazonOrderId' => self::PEDIDO, 'packageId' => 'P9'],
            'trackingDetails' => ['trackingId' => 'TBR777'],
            'packageTimeSlot' => ['slotId' => 'PONTO', 'handoverMethod' => 'DROPOFF', 'startTime' => '2026-11-27T12:00:00Z', 'endTime' => '2026-11-27T21:00:00Z'],
        ];
    }

    /** @return array<string, mixed> Respostas do feed de documentos do Easy Ship até o PDF (em GZIP). */
    private function fakesDoFeed(): array
    {
        return [
            self::BASE.'/feeds/2021-06-30/documents/RES1' => Http::response(['feedDocumentId' => 'RES1', 'url' => 'https://docs.amazon.test/resultado']),
            self::BASE.'/feeds/2021-06-30/documents' => Http::response(['feedDocumentId' => 'FD1', 'url' => 'https://upload.amazon.test/fd1']),
            'https://upload.amazon.test/*' => Http::response('', 200),
            self::BASE.'/feeds/2021-06-30/feeds/F1' => Http::sequence()
                ->push(['feedId' => 'F1', 'processingStatus' => 'IN_PROGRESS'])
                ->whenEmpty(Http::response(['feedId' => 'F1', 'processingStatus' => 'DONE', 'resultFeedDocumentId' => 'RES1'])),
            self::BASE.'/feeds/2021-06-30/feeds' => Http::response(['feedId' => 'F1'], 202),
            'https://docs.amazon.test/resultado' => Http::response('<ProcessingReport><AdditionalInfo><DocumentReportReferenceID>555</DocumentReportReferenceID></AdditionalInfo></ProcessingReport>'),
            self::BASE.'/reports/2021-06-30/reports/555' => Http::response(['reportId' => '555', 'processingStatus' => 'DONE', 'reportDocumentId' => 'DOC9']),
            self::BASE.'/tokens/*' => Http::response(['restrictedDataToken' => 'rdt-doc']),
            self::BASE.'/reports/2021-06-30/documents/DOC9' => Http::response(['reportDocumentId' => 'DOC9', 'url' => 'https://docs.amazon.test/etiqueta', 'compressionAlgorithm' => 'GZIP']),
            'https://docs.amazon.test/etiqueta' => Http::response(gzencode('%PDF-etiqueta-feed')),
        ];
    }

    public function test_package_scheduled_in_seller_central_gets_the_label_from_the_feed_and_reaches_label_ready(): void
    {
        Queue::fake();
        $order = $this->pedidoComNota();
        $agendadoNoSellerCentral = false;

        Http::fake([
            self::BASE.'/orders/v0/orders/'.self::PEDIDO => Http::response(['payload' => ['AmazonOrderId' => self::PEDIDO, 'OrderStatus' => 'Unshipped']]),
            self::BASE.'/easyShip/2022-03-23/package*' => Http::response($this->pacote()),
            ...$this->fakesDoFeed(),
        ]);

        (new ConfirmChannelShippingJob($order->id))->handle(app(\App\Modules\Marketplace\Support\ChannelShippingService::class));

        $envio = ChannelShipment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(ChannelShipment::STATUS_CONFIRMED, $envio->status);
        $this->assertSame('TBR777', $envio->tracking_code);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/timeSlot') || str_contains($r->url(), '/packages/bulk'));
        Queue::assertPushed(CheckShipmentLabelJob::class);

        // O CheckShipmentLabelJob chama de novo e de novo; cada chamada avança um passo do feed.
        for ($i = 0; $i < 6 && ! in_array($envio->fresh()->status, [ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED], true); $i++) {
            (new CheckShipmentLabelJob($envio->id))->handle(app(LabelFetchService::class));
            $this->travel(AmazonDbaShipping::FEED_INTERVALO_SEGUNDOS + 1)->seconds();
        }

        $this->assertContains($envio->fresh()->status, [ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED]);
        $this->assertSame('%PDF-etiqueta-feed', Storage::disk('local')->get(AmazonDbaShipping::caminhoEtiqueta($order)));

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://upload.amazon.test/')
            && str_contains($r->body(), '<AmazonOrderID>'.self::PEDIDO.'</AmazonOrderID>')
            && str_contains($r->body(), '<DocumentType>ShippingLabel</DocumentType>')
            && str_contains($r->body(), '<MerchantIdentifier>A1VENDEDOR</MerchantIdentifier>'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/feeds/2021-06-30/feeds') && $r['feedType'] === 'POST_EASYSHIP_DOCUMENTS');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/reports/2021-06-30/documents/DOC9') && $r->hasHeader('x-amz-access-token', 'rdt-doc'));
        // Um feed só: os passos seguintes leem o mesmo feed.
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($par) => str_ends_with($par[0]->url(), '/feeds/2021-06-30/feeds'))->count());
    }

    public function test_time_slot_not_supported_falls_back_to_bulk_without_slot(): void
    {
        $order = $this->pedidoComNota();

        Http::fake([
            self::BASE.'/orders/v0/orders/'.self::PEDIDO => Http::response(['payload' => ['AmazonOrderId' => self::PEDIDO, 'OrderStatus' => 'Unshipped']]),
            self::BASE.'/delivery/2022-07-01/invoice/status*' => Http::response(['orderInvoicingStatus' => 'Accepted']),
            self::BASE.'/easyShip/2022-03-23/timeSlot' => Http::response(['errors' => [['code' => 'InvalidInput', 'message' => 'The operation is not supported for the given order in marketplace A2Q3Y263D00KWC (shipFrom: BR, shipTo: BR)']]], 400),
            self::BASE.'/easyShip/2022-03-23/packages/bulk' => Http::response(['scheduledPackages' => [$this->pacote()]]),
            self::BASE.'/easyShip/2022-03-23/package*' => Http::response([], 404),
        ]);

        $envio = app(AmazonDbaShipping::class)->agendar($order);

        $this->assertSame('TBR777', $envio['tracking_code']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/packages/bulk')
            && $r['orderScheduleDetailsList'] === [['amazonOrderId' => self::PEDIDO]]);
        $this->assertSame(0, AlertaAdmin::count());
    }

    public function test_time_slot_not_supported_and_bulk_rejected_alerts_and_schedules_a_new_check(): void
    {
        Queue::fake();
        $order = $this->pedidoComNota();
        $agendadoNoSellerCentral = false;

        Http::fake([
            self::BASE.'/orders/v0/orders/'.self::PEDIDO => Http::response(['payload' => [
                'AmazonOrderId' => self::PEDIDO, 'OrderStatus' => 'Unshipped', 'LatestShipDate' => '2026-11-28T02:59:59Z',
            ]]),
            self::BASE.'/delivery/2022-07-01/invoice/status*' => Http::response(['orderInvoicingStatus' => 'Accepted']),
            self::BASE.'/easyShip/2022-03-23/timeSlot' => Http::response(['errors' => [['code' => 'InvalidInput', 'message' => 'The operation is not supported for the given order in marketplace A2Q3Y263D00KWC (shipFrom: BR, shipTo: BR)']]], 400),
            self::BASE.'/easyShip/2022-03-23/packages/bulk' => Http::response(['rejectedOrders' => [['amazonOrderId' => self::PEDIDO, 'error' => ['message' => 'The operation is not supported']]]]),
            self::BASE.'/easyShip/2022-03-23/package*' => function () use (&$agendadoNoSellerCentral) {
                return $agendadoNoSellerCentral ? Http::response($this->pacote()) : Http::response([], 404);
            },
        ]);

        // Pelo job de verdade: não pode falhar (nem repetir 6 vezes) calado.
        (new ConfirmChannelShippingJob($order->id))->handle(app(\App\Modules\Marketplace\Support\ChannelShippingService::class));

        $envio = ChannelShipment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(ChannelShipment::STATUS_ERROR, $envio->status);
        $this->assertStringContainsString('agende no Seller Central', $envio->error_message);

        $alerta = AlertaAdmin::where('tipo', AlertasAdmin::AMAZON_DBA_AGENDAMENTO)->where('chave', "pedido:{$order->id}")->firstOrFail();
        $this->assertStringContainsString(self::PEDIDO, $alerta->mensagem);
        $this->assertStringContainsString('27/11/2026 23:59', $alerta->mensagem);

        Mail::assertSent(AgendarDbaNoSellerCentral::class, fn ($mail) => $mail->hasTo('lira@example.com')
            && $mail->pedidoAmazon === self::PEDIDO && $mail->prazo === '27/11/2026 23:59');

        Queue::assertPushed(AguardarAgendamentoDbaJob::class, fn ($job) => $job->orderId === $order->id
            && $job->prazo->equalTo(\Carbon\CarbonImmutable::parse('2026-11-28T02:59:59Z')));

        // Lira agendou no Seller Central: a próxima conferência acha o pacote e segue o fluxo.
        $agendadoNoSellerCentral = true;
        Queue::fake();

        (new AguardarAgendamentoDbaJob($order->id, \Carbon\CarbonImmutable::parse('2026-11-28T02:59:59Z')))->handle(app(AmazonDbaShipping::class));

        Queue::assertPushed(ConfirmChannelShippingJob::class, fn ($job) => $job->orderId === $order->id);
    }

    public function test_normal_path_keeps_the_earliest_drop_off_and_the_bulk_label_without_feed(): void
    {
        $order = $this->pedidoComNota();
        $zip = tempnam(sys_get_temp_dir(), 'z');
        $arquivo = new \ZipArchive;
        $arquivo->open($zip, \ZipArchive::OVERWRITE);
        $arquivo->addFromString('label.pdf', '%PDF-etiqueta-lote');
        $arquivo->close();

        Http::fake([
            self::BASE.'/orders/v0/orders/'.self::PEDIDO => Http::response(['payload' => ['AmazonOrderId' => self::PEDIDO, 'OrderStatus' => 'Unshipped']]),
            self::BASE.'/delivery/2022-07-01/invoice/status*' => Http::response(['orderInvoicingStatus' => 'Accepted']),
            self::BASE.'/easyShip/2022-03-23/timeSlot' => Http::response(['timeSlots' => [
                ['slotId' => 'PONTO-TARDE', 'handoverMethod' => 'DROPOFF', 'startTime' => '2026-11-28T12:00:00Z'],
                ['slotId' => 'COLETA', 'handoverMethod' => 'PICKUP', 'startTime' => '2026-11-25T12:00:00Z'],
                ['slotId' => 'PONTO-CEDO', 'handoverMethod' => 'DROPOFF', 'startTime' => '2026-11-26T12:00:00Z'],
            ]]),
            self::BASE.'/easyShip/2022-03-23/packages/bulk' => Http::response([
                'scheduledPackages' => [$this->pacote()],
                'printableDocumentsUrl' => 'https://docs.amazon.test/lote.zip',
            ]),
            self::BASE.'/easyShip/2022-03-23/package*' => Http::response([], 404),
            'https://docs.amazon.test/*' => Http::response(file_get_contents($zip)),
        ]);
        @unlink($zip);

        app(AmazonDbaShipping::class)->agendar($order);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/packages/bulk')
            && $r['orderScheduleDetailsList'][0]['packageDetails']['packageTimeSlot']['slotId'] === 'PONTO-CEDO');
        $this->assertSame('%PDF-etiqueta-lote', app(AmazonDbaShipping::class)->buscarEtiqueta($order));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/feeds/'));
        $this->assertSame(0, AlertaAdmin::count());
    }
}
