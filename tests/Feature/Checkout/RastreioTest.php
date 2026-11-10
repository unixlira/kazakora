<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\MelhorEnvioEnvio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Rastrear pedido (pedido 2026-10-10) pelo número ou pelo link assinado
 * /rastreio/{id}-{assinatura}; só com login, do dono ou de admin (2026-10-17).
 */
class RastreioTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(array $extra = []): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'maria@exemplo.com', 'cpf' => '12345678909']);
        $order = Order::create(array_merge([
            'user_id' => $user->id,
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ], $extra));
        $order->items()->create(['product_name' => 'Organizador', 'product_price' => 100, 'quantity' => 1, 'subtotal' => 100, 'item_type' => OrderItem::TYPE_PRODUCT]);

        return $order;
    }

    public function test_visitante_vai_para_o_login_e_volta_ao_link(): void
    {
        $order = $this->pedido();
        $link = route('rastreio.ver', $order->trackingRef());

        $this->get('/rastreio')->assertRedirect(route('entrar'));
        $this->post('/rastreio', ['pedido' => (string) $order->id])->assertRedirect(route('entrar'));
        $this->get($link)->assertRedirect(route('entrar'));

        $this->post('/entrar', ['email' => 'maria@exemplo.com', 'password' => 'password'])->assertRedirect($link);
    }

    public function test_dono_busca_pelo_numero_e_ve_o_pedido(): void
    {
        $order = $this->pedido();

        $this->actingAs($order->user)->post('/rastreio', ['pedido' => '#'.$order->id])
            ->assertRedirect(route('rastreio.ver', $order->trackingRef()));
        $this->actingAs($order->user)->get('/rastreio/'.$order->trackingRef())->assertOk();
    }

    public function test_outro_cliente_nao_ve_o_pedido(): void
    {
        $order = $this->pedido();
        $outro = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($outro)->get('/rastreio/'.$order->trackingRef())->assertForbidden();
        $this->actingAs($outro)->from('/rastreio')->post('/rastreio', ['pedido' => (string) $order->id])
            ->assertRedirect('/rastreio')
            ->assertSessionHasErrors('pedido');
    }

    public function test_admin_ve_qualquer_pedido(): void
    {
        $order = $this->pedido();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/rastreio/'.$order->trackingRef())->assertOk();
        $this->actingAs($admin)->post('/rastreio', ['pedido' => (string) $order->id])
            ->assertRedirect(route('rastreio.ver', $order->trackingRef()));
    }

    public function test_link_com_assinatura_errada_da_404(): void
    {
        $order = $this->pedido();

        $this->actingAs($order->user)->get('/rastreio/'.$order->id.'-0000000000')->assertNotFound();
        $this->actingAs($order->user)->get('/rastreio/'.$order->id)->assertNotFound();
    }

    public function test_pagina_mostra_etapas_e_codigo_dos_correios(): void
    {
        $order = $this->pedido();
        CorreiosPrePostagem::query()->forceCreate([
            'order_id' => $order->id, 'codigo_objeto' => 'AB123456789BR', 'customer_name' => 'Maria',
            'zip' => '03187040', 'street' => 'Rua X', 'number' => '1', 'neighborhood' => 'Mooca', 'city' => 'São Paulo',
            'state' => 'SP', 'service_code' => '03298', 'weight_grams' => 300, 'content_items' => json_encode([]),
        ]);

        $this->actingAs($order->user)->get('/rastreio/'.$order->trackingRef())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Checkout/Rastreio', false)
                ->where('pedido.id', $order->id)
                ->where('pedido.codigo_rastreio', 'AB123456789BR')
                ->where('pedido.etapas.1.feito', true)
                ->where('pedido.etapas.3.titulo', 'Em preparação')
                ->where('pedido.etapas.3.feito', true)
                ->where('pedido.etapas.4.feito', true)
                ->where('pedido.etapas.5.feito', false)
                ->where('pedido.itens.0.nome', 'Organizador')
                ->where('pedido.rastreio_correios', true)
                ->where('pedido.rastreio_url', 'https://rastreamento.correios.com.br/app/index.php?objetos=AB123456789BR'));
    }

    public function test_etiqueta_da_jt_pelo_melhor_envio_leva_ao_melhor_rastreio(): void
    {
        // ACHADO REAL 2026-11-09: o #3978 saiu pela J&T (888031040957845) e a
        // tela mandava o cliente para os Correios, com "Frete Grátis" como transportadora.
        Http::fake(['melhor-rastreio-api.melhorrastreio.com.br/*' => Http::response(['data' => ['result' => null]])]);
        $order = $this->pedido(['shipping_carrier_name' => 'Frete Grátis']);
        ChannelShipment::create(['order_id' => $order->id, 'channel' => Order::ORIGIN_STORE, 'tracking_code' => '888031040957845', 'status' => 'label_ready']);
        MelhorEnvioEnvio::create(['order_id' => $order->id, 'status' => MelhorEnvioEnvio::STATUS_GERADA, 'servico' => 'JeT Standard', 'rastreio' => '888031040957845']);

        $this->actingAs($order->user)->get('/rastreio/'.$order->trackingRef())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pedido.codigo_rastreio', '888031040957845')
                ->where('pedido.transportadora', 'JeT Standard')
                ->where('pedido.rastreio_correios', false)
                ->where('pedido.rastreio_url', 'https://www.melhorrastreio.com.br/rastreio/888031040957845'));
    }

    public function test_etapas_da_transportadora_e_previsao_aparecem_abaixo_de_pedido_enviado(): void
    {
        // Resposta real do Melhor Rastreio para o #3978 (J&T) em 2026-11-09:
        // a hora vem com "Z" mas é a de Brasília.
        Http::fake(['melhor-rastreio-api.melhorrastreio.com.br/*' => Http::response(['data' => ['result' => [
            'id' => 'me-logistic:888031040957845', 'lastStatus' => 'POSTED',
            'trackingEvents' => [
                ['createdAt' => '2026-11-09T12:26:42.000Z', 'title' => 'Seu pacote foi coletado pela transportadora', 'description' => 'Seu pacote já está com a transportadora', 'location' => ['city' => 'São Paulo', 'state' => 'SP']],
                ['createdAt' => '2026-11-09T16:52:44.000Z', 'title' => 'Seu pacote foi transferido para outra unidade', 'description' => 'Seu pacote seguirá trajeto em breve', 'location' => ['city' => 'São Paulo', 'state' => 'SP']],
                ['createdAt' => '2026-11-09T11:21:41.273Z', 'title' => 'Adicionado no sistema', 'description' => null, 'location' => null],
            ],
        ]]])]);
        $order = $this->pedido(['status' => Order::STATUS_SHIPPED]);
        ChannelShipment::create(['order_id' => $order->id, 'channel' => Order::ORIGIN_STORE, 'tracking_code' => '888031040957845', 'status' => 'label_ready']);
        MelhorEnvioEnvio::create([
            'order_id' => $order->id, 'status' => MelhorEnvioEnvio::STATUS_GERADA, 'servico' => 'JeT Standard', 'rastreio' => '888031040957845',
            'postada_em' => '2026-11-09 12:55:53', 'resposta' => ['delivery_min' => 5, 'delivery_max' => 7],
        ]);

        $this->actingAs($order->user)->get('/rastreio/'.$order->trackingRef())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('pedido.eventos_transportadora', 3)
                ->where('pedido.eventos_transportadora.0.titulo', 'Seu pacote foi transferido para outra unidade')
                ->where('pedido.eventos_transportadora.0.quando', '2026-11-09T16:52:44-03:00')
                ->where('pedido.eventos_transportadora.0.local', 'São Paulo/SP')
                ->where('pedido.eventos_transportadora.2.titulo', 'Adicionado no sistema')
                // Postado segunda 09/11 + 7 dias úteis = quarta 18/11.
                ->where('pedido.previsao_entrega.entregue', false)
                ->where('pedido.previsao_entrega.data', fn ($d) => str_starts_with($d, '2026-11-18')));
    }

    public function test_melhor_rastreio_fora_do_ar_nao_quebra_a_tela(): void
    {
        Http::fake(['melhor-rastreio-api.melhorrastreio.com.br/*' => Http::response('erro', 500)]);
        $order = $this->pedido();
        ChannelShipment::create(['order_id' => $order->id, 'channel' => Order::ORIGIN_STORE, 'tracking_code' => '888031040957845', 'status' => 'label_ready']);
        MelhorEnvioEnvio::create(['order_id' => $order->id, 'status' => MelhorEnvioEnvio::STATUS_GERADA, 'servico' => 'JeT Standard', 'rastreio' => '888031040957845']);

        $this->actingAs($order->user)->get('/rastreio/'.$order->trackingRef())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pedido.eventos_transportadora', [])
                ->where('pedido.previsao_entrega', null)
                ->where('pedido.rastreio_url', 'https://www.melhorrastreio.com.br/rastreio/888031040957845'));
    }

    public function test_protocolo_do_melhor_envio_nao_aparece_como_rastreio(): void
    {
        $order = $this->pedido();
        ChannelShipment::create(['order_id' => $order->id, 'channel' => Order::ORIGIN_STORE, 'tracking_code' => 'ORD-202611147516417', 'status' => 'label_ready']);

        $this->actingAs($order->user)->get('/rastreio/'.$order->trackingRef())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pedido.codigo_rastreio', null)
                ->where('pedido.rastreio_url', null));
    }

    public function test_meus_pedidos_traz_o_link_de_rastreio(): void
    {
        $order = $this->pedido();

        $this->actingAs($order->user)->get('/pedidos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.data.0.tracking_ref', $order->trackingRef()));
    }

    public function test_depois_de_3_erros_pede_captcha(): void
    {
        RateLimiter::clear('rastreio-falhas:127.0.0.1');
        $order = $this->pedido();
        $user = $order->user;

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->get('/rastreio')->assertInertia(fn (AssertableInertia $page) => $page->where('captcha', false));
            $this->actingAs($user)->from('/rastreio')->post('/rastreio', ['pedido' => '999999'])->assertSessionHasErrors('pedido');
        }

        $this->actingAs($user)->get('/rastreio')->assertInertia(fn (AssertableInertia $page) => $page->where('captcha', true));

        // Sem o código, nem o pedido certo passa.
        $this->actingAs($user)->from('/rastreio')->post('/rastreio', ['pedido' => (string) $order->id])
            ->assertRedirect('/rastreio')
            ->assertSessionHasErrors('captcha');

        // Código errado também não, e o código vale uma tentativa só.
        $this->actingAs($user)->withSession(['rastreio_captcha' => ['codigo' => 'ACDEF', 'expira' => time() + 600]])
            ->from('/rastreio')->post('/rastreio', ['pedido' => (string) $order->id, 'captcha' => 'XXXXX'])
            ->assertSessionHasErrors('captcha');
        $this->assertNull(session('rastreio_captcha'));

        // Código vencido não vale.
        $this->actingAs($user)->withSession(['rastreio_captcha' => ['codigo' => 'ACDEF', 'expira' => time() - 1]])
            ->from('/rastreio')->post('/rastreio', ['pedido' => (string) $order->id, 'captcha' => 'ACDEF'])
            ->assertSessionHasErrors('captcha');

        // Código certo (minúsculo e com espaço vale) passa e zera a contagem.
        $this->actingAs($user)->withSession(['rastreio_captcha' => ['codigo' => 'ACDEF', 'expira' => time() + 600]])
            ->post('/rastreio', ['pedido' => (string) $order->id, 'captcha' => ' acd ef'])
            ->assertRedirect(route('rastreio.ver', $order->trackingRef()));
        $this->actingAs($user)->get('/rastreio')->assertInertia(fn (AssertableInertia $page) => $page->where('captcha', false));
    }

    public function test_imagem_do_captcha_guarda_o_codigo_na_sessao(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('Sem a extensão GD.');
        }
        $order = $this->pedido();

        $resposta = $this->actingAs($order->user)->get('/rastreio/captcha')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith("\x89PNG", $resposta->getContent());
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{5}$/', session('rastreio_captcha.codigo'));
    }
}
