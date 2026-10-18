<?php

namespace Tests\Feature\Checkout;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Services\Correios\CorreiosTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Rastreio do site em texto da Manuela (2026-10-18): sem template aprovado,
 * cada etapa dos Correios vira uma mensagem de texto, uma vez por pedido.
 * Pacote que saiu para entrega é consultado a cada rodada (5 min); os
 * demais esperam 30 minutos. No homolog tudo vai para o número de teste.
 */
class RastreioTextoManuelaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.templates.pedido_enviado' => null,
            'services.whatsapp.templates.pedido_entregue' => null,
            'services.whatsapp.rastreio_texto.ativo' => true,
            'services.whatsapp.rastreio_texto.redirecionar_para' => null,
        ]);
        $this->mock(CorreiosTokenService::class, fn ($m) => $m->shouldReceive('tokenForPrecoPrazo')->andReturn('tok'));
    }

    private function pedido(): Order
    {
        $order = Order::create([
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Maria da Silva', 'shipping_phone' => '(11) 98888-7777', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ]);
        CorreiosPrePostagem::query()->forceCreate([
            'order_id' => $order->id, 'codigo_objeto' => 'AE000000000BR', 'status' => 'emitida',
            'customer_name' => 'Maria', 'zip' => '03187040', 'street' => 'Rua X', 'number' => '1', 'neighborhood' => 'Mooca',
            'city' => 'São Paulo', 'state' => 'SP', 'service_code' => '03220', 'weight_grams' => 500, 'content_items' => '[]',
        ]);

        return $order;
    }

    private function janelaAberta(): void
    {
        WhatsAppConversation::query()->create([
            'wa_id' => '5511988887777', 'phone' => '5511988887777', 'unread_count' => 0, 'last_customer_message_at' => now()->subHour(),
        ]);
    }

    /** @param  array<int, array>  $eventos  do mais recente para o mais antigo */
    private function rastro(array $eventos): void
    {
        Http::fake([
            'api.correios.com.br/*' => Http::response(['objetos' => [['codObjeto' => 'AE000000000BR', 'eventos' => $eventos]]]),
            'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]]),
        ]);
    }

    private function evento(string $codigo, int $horasAtras, array $extra = []): array
    {
        return array_merge(['codigo' => $codigo, 'tipo' => '01', 'descricao' => $codigo, 'dtHrCriado' => now()->subHours($horasAtras)->format('Y-m-d\TH:i:s')], $extra);
    }

    private function textos(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'graph.facebook.com'))
            ->map(fn ($par) => $par[0]['text']['body'] ?? null)->values()->all();
    }

    private function consultasCorreios(): int
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'api.correios.com.br'))->count();
    }

    public function test_saiu_para_entrega_manda_postado_e_saiu_uma_vez_e_reconsulta_toda_rodada(): void
    {
        $this->janelaAberta();
        $order = $this->pedido();
        $this->rastro([$this->evento('OEC', 1), $this->evento('RO', 20), $this->evento('PO', 30)]);

        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();
        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();

        $textos = $this->textos();
        $this->assertCount(2, $textos);
        $this->assertStringContainsString("pedido #{$order->numero} foi postado nos Correios", $textos[0]);
        $this->assertStringContainsString("pedido #{$order->numero} saiu para entrega", $textos[1]);
        $this->assertStringContainsString('/rastreio/'.$order->trackingRef(), $textos[1]);
        $this->assertSame(2, $this->consultasCorreios());
        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->whatsapp_saiu_entrega_em);
    }

    public function test_em_transito_espera_trinta_minutos_para_consultar_de_novo(): void
    {
        $this->janelaAberta();
        $this->pedido();
        $this->rastro([$this->evento('RO', 2), $this->evento('PO', 10)]);

        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();
        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();
        $this->assertSame(1, $this->consultasCorreios());

        $this->travel(31)->minutes();
        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();
        $this->assertSame(2, $this->consultasCorreios());
        $this->assertCount(1, $this->textos());
    }

    public function test_tentativa_sem_sucesso_encerra_saiu_para_entrega_e_aguardando_retirada_avisa(): void
    {
        $this->janelaAberta();
        $order = $this->pedido();
        $this->rastro([
            $this->evento('LDI', 1, ['unidade' => ['nome' => 'AC MOOCA', 'endereco' => ['cidade' => 'SAO PAULO']]]),
            $this->evento('BDE', 3, ['tipo' => '20']),
            $this->evento('PO', 30),
        ]);

        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();

        $textos = $this->textos();
        $this->assertCount(2, $textos);
        $this->assertStringContainsString('aguardando retirada em AC MOOCA, SAO PAULO', $textos[1]);
        $this->assertNull($order->fresh()->whatsapp_saiu_entrega_em);
        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
    }

    public function test_entregue_em_texto_conclui_o_pedido(): void
    {
        $this->janelaAberta();
        $order = $this->pedido();
        $this->rastro([$this->evento('BDE', 1), $this->evento('OEC', 4), $this->evento('PO', 30)]);

        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();

        // Já entregue: não manda "postado" nem "saiu para entrega" atrasados.
        $textos = $this->textos();
        $this->assertCount(1, $textos);
        $this->assertStringContainsString('registraram a entrega', $textos[0]);
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->whatsapp_entregue_em);
    }

    public function test_fora_da_janela_de_24h_nao_manda_texto(): void
    {
        $order = $this->pedido();
        $this->rastro([$this->evento('OEC', 1), $this->evento('PO', 30)]);

        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();

        $this->assertSame([], $this->textos());
        $this->assertNull($order->fresh()->whatsapp_saiu_entrega_em);
    }

    public function test_homolog_manda_so_para_o_numero_de_teste(): void
    {
        config(['services.whatsapp.rastreio_texto.redirecionar_para' => '5511990253050']);
        $order = $this->pedido();
        $this->rastro([$this->evento('OEC', 1), $this->evento('PO', 30)]);

        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();

        $enviados = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'graph.facebook.com'))->values();
        $this->assertCount(2, $enviados);
        $this->assertSame(['5511990253050', '5511990253050'], $enviados->map(fn ($p) => $p[0]['to'])->all());
        $this->assertStringStartsWith("[Teste do rastreio] Pedido #{$order->numero}, cliente Maria da Silva:", $enviados[1][0]['text']['body']);
    }
}
