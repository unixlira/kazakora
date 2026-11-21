<?php

namespace Tests\Feature\MelhorEnvio;

use App\Models\MelhorEnvioToken;
use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\LojaShippingDriver;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MelhorEnvioEnvio;
use App\Modules\Marketplace\Support\EtiquetaOcr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

/**
 * A etiqueta do Melhor Envio fica guardada como veio dele e o pedido abre as
 * duas: a da loja e a original (pedido do Lira em 14/11, #4172).
 */
class EtiquetaOriginalNoPedidoTest extends TestCase
{
    use RefreshDatabase;

    private const ME_ID = 'a339de4d-9dc4-43e2-b940-eea3e4c1c0d4';

    private function pdf(): string
    {
        $pdf = new Fpdi('P', 'pt', [577.5, 813]);
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Text(50, 50, 'etiqueta do melhor envio');

        return $pdf->Output('S');
    }

    private function pedido(): Order
    {
        $order = Order::create([
            'user_id' => User::factory()->create()->id, 'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente', 'shipping_phone' => '13999990000', 'shipping_zip' => '11360-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '56', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Vicente', 'shipping_state' => 'SP', 'shipping_cost' => 0, 'subtotal' => 180, 'total' => 180,
        ]);
        MelhorEnvioEnvio::create([
            'order_id' => $order->id, 'status' => MelhorEnvioEnvio::STATUS_GERADA, 'me_order_id' => self::ME_ID,
            'servico' => 'Correios SEDEX', 'prazo_dias' => 2, 'gerada_em' => now(),
        ]);

        return $order;
    }

    /** Conferência do logo: false = sem marca do Melhor Envio; null = não deu para conferir. */
    private function conferencia(?bool $temMarca): void
    {
        $this->app->instance(EtiquetaOcr::class, new class($temMarca) extends EtiquetaOcr
        {
            public function __construct(private ?bool $resposta) {}

            public function palavras(string $pdf): ?array
            {
                return null;
            }

            public function temMarcaDoMelhorEnvio(string $pdf): ?bool
            {
                return $this->resposta;
            }
        });
    }

    private function etiquetaDoMelhorEnvio(): string
    {
        config(['services.melhorenvio.api_base_url' => 'https://me.test/api/v2']);
        MelhorEnvioToken::create([
            'account_label' => 'KazaKora ME', 'access_token' => 'acesso', 'refresh_token' => 'r1',
            'token_expires_at' => now()->addDays(20), 'scopes' => [],
        ]);
        $original = $this->pdf();
        Http::fake([
            'me.test/*' => Http::response(['url' => 'https://arquivos.test/etiqueta.pdf']),
            'arquivos.test/*' => Http::response($original, 200, ['Content-Type' => 'application/pdf']),
        ]);

        return $original;
    }

    public function test_sem_conferencia_do_logo_a_etiqueta_nao_imprime(): void
    {
        // #4356 (21/11/2026): o OCR travou e a etiqueta saiu com o logo do Melhor Envio.
        $this->etiquetaDoMelhorEnvio();
        $this->conferencia(null);
        $this->assertFalse(app(LojaShippingDriver::class)->fetchLabel($this->pedido())['ready']);
    }

    public function test_com_a_marca_do_melhor_envio_a_etiqueta_nao_imprime(): void
    {
        $this->etiquetaDoMelhorEnvio();
        $this->conferencia(true);
        $this->assertFalse(app(LojaShippingDriver::class)->fetchLabel($this->pedido())['ready']);
    }

    public function test_driver_devolve_a_etiqueta_da_loja_e_a_original_junto(): void
    {
        $this->conferencia(false);
        config(['services.melhorenvio.api_base_url' => 'https://me.test/api/v2']);
        MelhorEnvioToken::create([
            'account_label' => 'KazaKora ME', 'access_token' => 'acesso', 'refresh_token' => 'r1',
            'token_expires_at' => now()->addDays(20), 'scopes' => [],
        ]);
        $original = $this->pdf();
        Http::fake([
            'me.test/*' => Http::response(['url' => 'https://arquivos.test/etiqueta.pdf']),
            'arquivos.test/*' => Http::response($original, 200, ['Content-Type' => 'application/pdf']),
        ]);

        $etiqueta = app(LojaShippingDriver::class)->fetchLabel($this->pedido());

        $this->assertTrue($etiqueta['ready']);
        $this->assertSame($original, $etiqueta['original']);
        $this->assertNotSame($original, $etiqueta['contents']);
        $this->assertStringStartsWith('%PDF', $etiqueta['contents']);
    }

    public function test_pedido_abre_a_etiqueta_da_loja_e_a_original(): void
    {
        Storage::fake('local');
        $order = $this->pedido();
        ChannelShipment::create([
            'order_id' => $order->id, 'channel' => Order::ORIGIN_STORE, 'tracking_code' => 'AE155114115BR', 'status' => 'label_ready',
            'label_path' => "labels/{$order->id}/etiqueta-1.pdf", 'raw_label_path' => "labels/{$order->id}/raw-1.pdf",
        ]);
        Storage::disk('local')->put("labels/{$order->id}/etiqueta-1.pdf", '%PDF-da-loja');
        Storage::disk('local')->put("labels/{$order->id}/raw-1.pdf", '%PDF-original');

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get("/admin/pedidos/{$order->id}/etiqueta/imprimir")->assertOk()->assertSee('%PDF-da-loja', false);
        $this->actingAs($admin)->get("/admin/pedidos/{$order->id}/etiqueta/imprimir?original=1")->assertOk()->assertSee('%PDF-original', false);
    }
}
