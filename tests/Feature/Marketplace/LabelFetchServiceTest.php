<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\MarketplaceChannelDriver;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrintJob;
use App\Modules\Marketplace\Support\LabelFetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class LabelFetchServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeShipment(string $channel = MarketplaceAccount::CHANNEL_MERCADO_LIVRE): ChannelShipment
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $order = Order::create([
            'user_id' => $user->id,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => 'ML-1',
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ]);

        $order->items()->create([
            'product_name' => 'Produto teste',
            'product_price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
        ]);

        return ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => $channel,
            'external_shipment_id' => 'SHIP-1',
            'shipping_method' => 'self_service',
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    private function mockDriver(array $fetchLabelResult, string $channel = MarketplaceAccount::CHANNEL_MERCADO_LIVRE): void
    {
        $driver = Mockery::mock(MarketplaceChannelDriver::class);
        $driver->shouldReceive('fetchLabel')->once()->andReturn($fetchLabelResult);

        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->with($channel)->once()->andReturn($driver);

        $this->app->instance(MarketplaceDriverManager::class, $manager);
    }

    public function test_attempt_returns_false_and_changes_nothing_when_label_is_not_ready(): void
    {
        $shipment = $this->makeShipment();
        $this->mockDriver(['ready' => false, 'contents' => null, 'content_type' => null]);

        $ready = app(LabelFetchService::class)->attempt($shipment->fresh());

        $this->assertFalse($ready);
        $this->assertSame(ChannelShipment::STATUS_CONFIRMED, $shipment->fresh()->status);
        $this->assertDatabaseCount('print_jobs', 0);
    }

    public function test_attempt_downloads_and_registers_the_label_when_ready(): void
    {
        Storage::fake('local');
        $shipment = $this->makeShipment();
        $this->mockDriver(['ready' => true, 'contents' => 'not-a-real-pdf', 'content_type' => 'application/octet-stream']);

        $ready = app(LabelFetchService::class)->attempt($shipment->fresh());

        $this->assertTrue($ready);
        $fresh = $shipment->fresh();
        $this->assertSame(ChannelShipment::STATUS_LABEL_READY, $fresh->status);
        $this->assertNotNull($fresh->label_ready_at);
        Storage::disk('local')->assertExists($fresh->label_path);

        $this->assertDatabaseHas('print_jobs', [
            'order_id' => $shipment->order_id,
            'status' => PrintJob::STATUS_QUEUED,
        ]);
        $this->assertDatabaseHas('order_fulfillment_events', [
            'order_id' => $shipment->order_id,
            'step' => 'label_generated',
            'status' => 'success',
        ]);
    }

    public function test_attempt_is_idempotent_and_does_not_duplicate_the_print_job(): void
    {
        Storage::fake('local');
        $shipment = $this->makeShipment();
        PrintJob::create(['order_id' => $shipment->order_id, 'label_path' => 'labels/existing.pdf', 'status' => PrintJob::STATUS_PRINTED]);

        $this->mockDriver(['ready' => true, 'contents' => 'conteudo', 'content_type' => 'application/octet-stream']);

        app(LabelFetchService::class)->attempt($shipment->fresh());

        $this->assertSame(1, PrintJob::where('order_id', $shipment->order_id)->count());
    }

    /**
     * BUG REAL 2026-08-10 — cobertura de regressão. O zip do
     * response_type=zpl2 do Mercado Livre (MercadoLivreDriver::fetchLabel())
     * vem com DOIS arquivos: um PDF da PLP (não é a etiqueta térmica, é uma
     * folha A4 — foi esse PDF sendo mandado direto pra impressora que
     * causava "impressora parada, nada sai") e um .txt com o ZPL de
     * verdade. Precisa achar o .txt certo, não o primeiro entry do zip.
     */
    public function test_attempt_picks_the_zpl_txt_entry_not_the_plp_pdf_from_a_multi_file_zip(): void
    {
        Storage::fake('local');
        Http::fake(['api.labelary.com/*' => Http::response(self::minimalPdf(), 200, ['Content-Type' => 'application/pdf'])]);

        $shipment = $this->makeShipment();

        $zip = self::buildZip([
            'plp.pdf' => "%PDF-1.4\nconteúdo irrelevante, não é a etiqueta\n%%EOF",
            'thermal_zpl_shipping_label.txt' => "^XA^FO50,50^A0N,50,50^FDTeste^FS^XZ",
        ]);

        $this->mockDriver(['ready' => true, 'contents' => $zip, 'content_type' => 'application/force-download']);

        $ready = app(LabelFetchService::class)->attempt($shipment->fresh());

        $this->assertTrue($ready);
        $fresh = $shipment->fresh();
        $labelContents = Storage::disk('local')->get($fresh->label_path);

        // Se tivesse pego o PDF errado (índice 0 do zip), isso teria virado
        // a PLP crua em vez de passar pela conversão ZPL->PDF do Labelary.
        $this->assertStringStartsWith('%PDF-', $labelContents);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.labelary.com'));
    }

    /**
     * Escopo pedido 2026-08-15: a declaração de conteúdo (SKU + quantidade
     * no rodapé da etiqueta) só entra pra Shopee/TikTok, canal onde o
     * problema real de quantidade errada enviada apareceu — Mercado Livre
     * continua recebendo a etiqueta crua do canal, sem sobreposição nenhuma.
     */
    public function test_attempt_adds_the_declaration_overlay_for_shopee(): void
    {
        Storage::fake('local');
        $shipment = $this->makeShipment(MarketplaceAccount::CHANNEL_SHOPEE);
        $this->mockDriver(['ready' => true, 'contents' => self::minimalPdf(), 'content_type' => 'application/pdf'], MarketplaceAccount::CHANNEL_SHOPEE);

        $ready = app(LabelFetchService::class)->attempt($shipment->fresh());

        $this->assertTrue($ready);
        $labelContents = Storage::disk('local')->get($shipment->fresh()->label_path);

        $this->assertStringContainsString('CONFERIR QTD ANTES DE EMBALAR', $labelContents);
    }

    public function test_attempt_does_not_touch_the_label_for_mercado_livre(): void
    {
        Storage::fake('local');
        $shipment = $this->makeShipment(); // canal default: Mercado Livre
        $rawPdf = self::minimalPdf();
        $this->mockDriver(['ready' => true, 'contents' => $rawPdf, 'content_type' => 'application/pdf']);

        $ready = app(LabelFetchService::class)->attempt($shipment->fresh());

        $this->assertTrue($ready);
        $labelContents = Storage::disk('local')->get($shipment->fresh()->label_path);

        $this->assertSame($rawPdf, $labelContents);
    }

    private static function buildZip(array $files): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'test_zip_').'.zip';

        $zip = new ZipArchive();
        $zip->open($tempPath, ZipArchive::CREATE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        $bytes = file_get_contents($tempPath);
        unlink($tempPath);

        return $bytes;
    }

    private static function minimalPdf(): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 288 432] /Resources << >> /Contents 4 0 R >>',
            4 => "<< /Length 9 >>\nstream\nBT ET\nendstream",
        ];

        $body = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $content) {
            $offsets[$id] = strlen($body);
            $body .= "{$id} 0 obj\n{$content}\nendobj\n";
        }

        $xrefOffset = strlen($body);
        $body .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($objects as $id => $content) {
            $body .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        $body .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $body;
    }
}
