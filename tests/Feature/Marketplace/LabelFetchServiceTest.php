<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\MarketplaceChannelDriver;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Drivers\ShopeeDriver;
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

    private function makeShipment(string $channel = MarketplaceAccount::CHANNEL_MERCADO_LIVRE, ?\Illuminate\Support\Carbon $scheduledFor = null): ChannelShipment
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
            'scheduled_for' => $scheduledFor,
        ]);
    }

    private function mockDriver(array $fetchLabelResult, string $channel = MarketplaceAccount::CHANNEL_MERCADO_LIVRE): void
    {
        $driver = Mockery::mock(MarketplaceChannelDriver::class);
        $driver->shouldReceive('fetchLabel')->once()->andReturn($fetchLabelResult);

        $manager = Mockery::mock(MarketplaceDriverManager::class);
        // ->once() de propósito — LabelFetchService::attempt() resolve o
        // driver UMA vez só e reaproveita (bug real corrigido 2026-08-21:
        // chamava driver() 2x por tentativa, uma pra etiqueta e outra pra
        // ShopeeDriver::fetchContentDeclaration()).
        $manager->shouldReceive('driver')->with($channel)->once()->andReturn($driver);

        $this->app->instance(MarketplaceDriverManager::class, $manager);
    }

    /**
     * Igual a mockDriver(), mas com um mock CONCRETO de ShopeeDriver (não a
     * interface MarketplaceChannelDriver) — precisa ser a classe real pro
     * `instanceof ShopeeDriver` em LabelFetchService::attempt() reconhecer o
     * driver e chamar fetchContentDeclaration(). $declarationPdf null
     * simula falha no fetch (best effort, ver ShopeeDriver).
     */
    private function mockShopeeDriverWithDeclaration(array $fetchLabelResult, ?string $declarationPdf): void
    {
        $driver = Mockery::mock(ShopeeDriver::class);
        $driver->shouldReceive('fetchLabel')->once()->andReturn($fetchLabelResult);
        $driver->shouldReceive('fetchContentDeclaration')->once()->andReturn($declarationPdf);

        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->with(MarketplaceAccount::CHANNEL_SHOPEE)->once()->andReturn($driver);

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
     * Pedido explícito 2026-08-21 (versão final do dia, seguindo o exemplo
     * de etiqueta física fornecido pelo usuário): a declaração REAL da
     * Shopee, baixada separada via ShopeeDriver::fetchContentDeclaration(),
     * vai na metade direita — sem faixa de SKU/QTD por cima (o documento
     * real já lista os produtos).
     */
    public function test_attempt_shows_the_real_shopee_declaration_on_the_right_half(): void
    {
        Storage::fake('local');
        $shipment = $this->makeShipment(MarketplaceAccount::CHANNEL_SHOPEE);
        $declarationPdf = self::minimalPdf();
        $this->mockShopeeDriverWithDeclaration(
            ['ready' => true, 'contents' => self::minimalPdf(), 'content_type' => 'application/pdf'],
            $declarationPdf,
        );

        $ready = app(LabelFetchService::class)->attempt($shipment->fresh());

        $this->assertTrue($ready);
        $labelContents = Storage::disk('local')->get($shipment->fresh()->label_path);

        $this->assertStringNotContainsString('QTD:', $labelContents, 'documento real já traz os produtos, sem faixa sobreposta');

        $tempPath = tempnam(sys_get_temp_dir(), 'label_result_').'.pdf';
        file_put_contents($tempPath, $labelContents);

        try {
            $this->assertSame(1, (new \setasign\Fpdi\Fpdi)->setSourceFile($tempPath), 'sempre 1 etiqueta física só');
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * ShopeeDriver::fetchContentDeclaration() é best-effort (nunca lança) —
     * quando devolve null (documento não disponível, erro da API etc.),
     * cai pro painel de declaração desenhado localmente (mesmo fallback de
     * antes), em vez de travar a etiqueta inteira.
     */
    public function test_attempt_falls_back_to_the_declaration_panel_when_shopee_document_fetch_fails(): void
    {
        Storage::fake('local');
        $shipment = $this->makeShipment(MarketplaceAccount::CHANNEL_SHOPEE);
        $this->mockShopeeDriverWithDeclaration(
            ['ready' => true, 'contents' => self::minimalPdf(), 'content_type' => 'application/pdf'],
            null,
        );

        $ready = app(LabelFetchService::class)->attempt($shipment->fresh());

        $this->assertTrue($ready);
        $labelContents = Storage::disk('local')->get($shipment->fresh()->label_path);

        $this->assertStringContainsString('Produto teste | QTD: 01', $labelContents);
    }

    public function test_attempt_uses_the_product_sku_when_one_is_linked(): void
    {
        Storage::fake('local');
        $shipment = $this->makeShipment(); // canal default: Mercado Livre
        $product = \App\Modules\Catalog\Models\Product::factory()->create(['sku' => 'ORG-KIT-BEGE-0001']);
        $shipment->order->items()->first()->update(['product_id' => $product->id, 'quantity' => 3]);

        $this->mockDriver(['ready' => true, 'contents' => self::minimalPdf(), 'content_type' => 'application/pdf']);

        $ready = app(LabelFetchService::class)->attempt($shipment->fresh());

        $this->assertTrue($ready);
        $labelContents = Storage::disk('local')->get($shipment->fresh()->label_path);

        $this->assertStringContainsString('ORG-KIT-BEGE-0001 | QTD: 03', $labelContents);
    }

    /**
     * BUG REAL 2026-08-21 (etiqueta real do Mercado Livre): a etiqueta real
     * dele sempre vem com uma DANFE simplificada numa 2ª página, e a
     * declaração de conteúdo passou a valer pra esse canal também (não só
     * pra venda agendada, ver teste abaixo). raw_label_path continua
     * guardando a etiqueta original intacta (com a DANFE) à parte — só a
     * versão física impressa (label_path) muda.
     *
     * Este teste usa um mock de 1 página só (sem DANFE) — cobre o
     * fallback pro painel de declaração cheio (composeSideBySideLabel()
     * com $rightSide='danfe' mas $pageCount<2). O caso real (2 páginas,
     * DANFE de verdade na direita) é o teste seguinte.
     */
    public function test_attempt_adds_the_declaration_panel_for_mercado_livre(): void
    {
        Storage::fake('local');
        $shipment = $this->makeShipment(); // canal default: Mercado Livre
        $rawPdf = self::minimalPdf();
        $this->mockDriver(['ready' => true, 'contents' => $rawPdf, 'content_type' => 'application/pdf']);

        $ready = app(LabelFetchService::class)->attempt($shipment->fresh());

        $this->assertTrue($ready);
        $labelContents = Storage::disk('local')->get($shipment->fresh()->label_path);

        $this->assertNotSame($rawPdf, $labelContents);
        $this->assertStringContainsString('Produto teste | QTD: 01', $labelContents);

        // A etiqueta original intacta (com a eventual DANFE) continua
        // arquivada à parte, sem passar por nenhum processamento.
        $rawContents = Storage::disk('local')->get($shipment->fresh()->raw_label_path);
        $this->assertSame($rawPdf, $rawContents);
    }

    /**
     * BUG REAL 2026-08-21 (feedback do usuário vendo a etiqueta impressa):
     * a metade direita do Mercado Livre tem que ser a DANFE de verdade (2ª
     * página original, com a chave de acesso) — não o painel de texto
     * genérico, que é só o fallback pra quando não existe DANFE nenhuma
     * (ver teste acima). A etiqueta real do ML sempre vem em 2 páginas —
     * este é o caso que acontece de verdade em produção.
     */
    public function test_attempt_shows_the_real_danfe_page_on_the_right_half_for_mercado_livre(): void
    {
        Storage::fake('local');
        $shipment = $this->makeShipment(); // canal default: Mercado Livre
        $this->mockDriver(['ready' => true, 'contents' => self::minimalTwoPagePdf(), 'content_type' => 'application/pdf']);

        $ready = app(LabelFetchService::class)->attempt($shipment->fresh());

        $this->assertTrue($ready);
        $labelContents = Storage::disk('local')->get($shipment->fresh()->label_path);

        // Declaração ainda entra (faixa fina no rodapé da metade direita,
        // não o painel cheio) e a etiqueta continua sendo 1 página física
        // só, mesmo com 2 páginas de origem.
        $this->assertStringContainsString('Produto teste | QTD: 01', $labelContents);
        $this->assertStringNotContainsString('DECLARA', $labelContents, 'não usa o painel cheio quando tem DANFE de verdade');

        $tempPath = tempnam(sys_get_temp_dir(), 'label_result_').'.pdf';
        file_put_contents($tempPath, $labelContents);

        try {
            $this->assertSame(1, (new \setasign\Fpdi\Fpdi)->setSourceFile($tempPath));
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Pedido explícito 2026-08-17: entrega programada (Mercado Livre
     * "Coleta/Places" agendado, scheduled_for preenchido) ganha MAIS uma
     * 2ª linha na declaração ("Pedido agendado dia dd/mm/yyyy | Pedido nº
     * X"), pra quem embala identificar de cara que aquele pedido é um
     * agendado.
     */
    public function test_attempt_adds_the_scheduled_declaration_for_a_scheduled_mercado_livre_shipment(): void
    {
        Storage::fake('local');
        $scheduledFor = now()->addDays(3)->setTime(0, 0);
        $shipment = $this->makeShipment(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, $scheduledFor);
        $this->mockDriver(['ready' => true, 'contents' => self::minimalPdf(), 'content_type' => 'application/pdf']);

        $ready = app(LabelFetchService::class)->attempt($shipment->fresh());

        $this->assertTrue($ready);
        $labelContents = Storage::disk('local')->get($shipment->fresh()->label_path);

        $this->assertStringContainsString('Produto teste | QTD: 01', $labelContents);
        // "nº" tem "º" (ordinal), que sai convertido pro Latin-1 do FPDF —
        // checa o texto ao redor da data/nº sem depender do byte exato do
        // símbolo.
        $this->assertStringContainsString('Pedido agendado dia '.$scheduledFor->format('d/m/Y'), $labelContents);
        $this->assertStringContainsString((string) $shipment->order_id, $labelContents);

        // Sobreposição na mesma página, nunca página extra — mesma garantia
        // já exigida pro caso Shopee.
        $tempPath = tempnam(sys_get_temp_dir(), 'label_result_').'.pdf';
        file_put_contents($tempPath, $labelContents);

        try {
            $this->assertSame(1, (new \setasign\Fpdi\Fpdi)->setSourceFile($tempPath));
        } finally {
            @unlink($tempPath);
        }
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

    private static function minimalTwoPagePdf(): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 288 432] /Resources << >> /Contents 4 0 R >>',
            4 => "<< /Length 9 >>\nstream\nBT ET\nendstream",
            5 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 288 432] /Resources << >> /Contents 6 0 R >>',
            6 => "<< /Length 9 >>\nstream\nBT ET\nendstream",
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
