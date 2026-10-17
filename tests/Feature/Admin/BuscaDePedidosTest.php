<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\ChannelShipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Busca da tela Pedidos do admin (2026-10-16): cada critério que o operador
 * digita devolve o pedido certo e não traz pedido que só "tem o número".
 */
class BuscaDePedidosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function pedido(array $extra = []): Order
    {
        $order = Order::create([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente Padrão',
            'shipping_phone' => '21900000000',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
            ...collect($extra)->except('created_at')->all(),
        ]);

        if (isset($extra['created_at'])) {
            DB::table('orders')->where('id', $order->id)->update(['created_at' => $extra['created_at']]);
        }

        return $order;
    }

    /** @return list<int> */
    private function ids(string $query): array
    {
        $ids = [];
        $this->actingAs($this->admin)->get('/admin/pedidos?'.$query)->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$ids) {
                $ids = collect($page->toArray()['props']['orders']['data'])->pluck('id')->all();
            });

        return $ids;
    }

    private function buscar(string $termo): array
    {
        return $this->ids(http_build_query(['search' => $termo]));
    }

    public function test_numero_interno_com_e_sem_cerquilha(): void
    {
        $alvo = $this->pedido();
        // Ruído: pedidos que só "contêm" o número.
        $this->pedido(['external_order_id' => '2000019000'.$alvo->id.'0', 'origin' => Order::ORIGIN_MERCADO_LIVRE]);
        $this->pedido(['shipping_phone' => '119'.$alvo->id.'7777']);

        $this->assertSame([$alvo->id], $this->buscar('#'.$alvo->id));
        $this->assertSame([$alvo->id], $this->buscar('# '.$alvo->id));
        $this->assertSame([$alvo->id], $this->buscar((string) $alvo->id));
        $this->assertSame([$alvo->id], $this->buscar('  '.$alvo->id.'  '));
    }

    public function test_numero_de_cada_marketplace(): void
    {
        $shopee = $this->pedido(['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => '261016PWVAA8JX']);
        $outraShopee = $this->pedido(['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => '261016XYZW12AB']);
        $ml = $this->pedido(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => '2000019008909826', 'channel_pack_id' => '2000014759217073']);
        $tiktok = $this->pedido(['origin' => Order::ORIGIN_TIKTOK_SHOP, 'external_order_id' => '586613517174670962']);
        $amazon = $this->pedido(['origin' => Order::ORIGIN_AMAZON, 'external_order_id' => '701-1167157-6796210']);

        $this->assertSame([$shopee->id], $this->buscar('261016PWVAA8JX'));
        $this->assertSame([$shopee->id], $this->buscar('261016pwvaa8jx'));
        $this->assertSame([$ml->id], $this->buscar('2000019008909826'));
        $this->assertSame([$ml->id], $this->buscar('2000014759217073'), 'número do pacote do ML');
        $this->assertSame([$tiktok->id], $this->buscar('586613517174670962'));
        $this->assertSame([$amazon->id], $this->buscar('701-1167157-6796210'));
        $this->assertSame([$amazon->id], $this->buscar('7011167157'), 'Amazon sem traço');
        $this->assertEqualsCanonicalizing([$shopee->id, $outraShopee->id], $this->buscar('261016'));
    }

    public function test_nome_parcial_maiuscula_e_apelido(): void
    {
        $maria = $this->pedido(['shipping_name' => 'Maria Aparecida Souza']);
        $dest = $this->pedido(['shipping_name' => 'Comprador', 'shipping_recipient_name' => 'Rachel Lima', 'channel_buyer_nickname' => 'RACHELZINHA22']);
        $this->pedido(['shipping_name' => 'Outro Cliente']);

        $this->assertSame([$maria->id], $this->buscar('aparecida'));
        $this->assertSame([$maria->id], $this->buscar('MARIA APARECIDA'));
        $this->assertSame([$maria->id], $this->buscar('  maria   aparecida '), 'espaços extras');
        $this->assertSame([$dest->id], $this->buscar('rachel lima'));
        $this->assertSame([$dest->id], $this->buscar('rachelzinha22'));
    }

    public function test_cpf_cnpj_e_telefone_com_e_sem_pontuacao(): void
    {
        $cpfLimpo = $this->pedido(['buyer_document' => '52998224725']);
        $cpfFormatado = $this->pedido(['buyer_document' => '123.456.789-09']);
        $cnpj = $this->pedido(['buyer_document' => '11222333000181']);
        $foneFormatado = $this->pedido(['shipping_phone' => '(11) 98765-4321']);
        $foneLimpo = $this->pedido(['shipping_phone' => '31988887777']);

        $this->assertSame([$cpfLimpo->id], $this->buscar('529.982.247-25'));
        $this->assertSame([$cpfLimpo->id], $this->buscar('52998224725'));
        $this->assertSame([$cpfFormatado->id], $this->buscar('12345678909'));
        $this->assertSame([$cpfFormatado->id], $this->buscar('123.456.789-09'));
        $this->assertSame([$cnpj->id], $this->buscar('11.222.333/0001-81'));
        $this->assertSame([$foneFormatado->id], $this->buscar('11987654321'));
        $this->assertSame([$foneFormatado->id], $this->buscar('(11) 98765-4321'));
        $this->assertSame([$foneFormatado->id], $this->buscar('+55 11 98765-4321'));
        $this->assertSame([$foneLimpo->id], $this->buscar('(31) 98888-7777'));
    }

    public function test_email_com_numero_nao_traz_pedido_pelo_numero(): void
    {
        $alvo = $this->pedido(['shipping_email' => 'joao123@gmail.com']);
        $porUsuario = $this->pedido(['user_id' => User::factory()->create(['email' => 'cliente.site@exemplo.com', 'name' => 'Cliente Site'])->id]);
        // Antes: "joao123@..." virava busca pelo pedido 123 e telefone com 123.
        $this->pedido(['shipping_phone' => '11912345678']);
        $this->pedido(['external_order_id' => '2000000123000']);

        $this->assertSame([$alvo->id], $this->buscar('joao123@gmail.com'));
        $this->assertSame([$alvo->id], $this->buscar('JOAO123@GMAIL.COM'));
        $this->assertSame([$porUsuario->id], $this->buscar('cliente.site@exemplo.com'));
    }

    public function test_rastreio_nfe_e_produto(): void
    {
        $comRastreio = $this->pedido(['origin' => Order::ORIGIN_AMAZON]);
        ChannelShipment::create(['order_id' => $comRastreio->id, 'channel' => 'amazon', 'tracking_code' => 'AN123456789BR']);

        $comNota = $this->pedido();
        Invoice::create(['order_id' => $comNota->id, 'status' => 'authorized', 'numero' => 524, 'serie' => 2, 'chave_acesso' => '35261012345678000199550020000005241000005240']);
        $notaParecida = $this->pedido();
        Invoice::create(['order_id' => $notaParecida->id, 'status' => 'authorized', 'numero' => 1524, 'serie' => 2]);

        $produto = Product::factory()->create(['sku' => 'KZ-LUMI-01', 'name' => 'Luminária Lua']);
        $comProduto = $this->pedido();
        $comProduto->items()->create(['product_id' => $produto->id, 'product_name' => 'Luminária Lua 3D', 'product_price' => 50, 'quantity' => 1, 'subtotal' => 50]);

        $this->assertSame([$comRastreio->id], $this->buscar('an123456789br'));
        $this->assertSame([$comNota->id], $this->buscar('524'), 'NF-e 524 exata, não a 1524');
        $this->assertSame([$comNota->id], $this->buscar('35261012345678000199550020000005241000005240'));
        $this->assertSame([$comProduto->id], $this->buscar('kz-lumi'));
        $this->assertSame([$comProduto->id], $this->buscar('luminária lua'));
    }

    public function test_data(): void
    {
        $dia = $this->pedido(['created_at' => '2026-08-13 15:00:00']);
        $this->pedido(['created_at' => '2026-08-14 15:00:00']);

        $this->assertSame([$dia->id], $this->buscar('13/08/2026'));
        $this->assertSame([$dia->id], $this->buscar('2026-08-13'));
    }

    public function test_busca_combina_com_filtros_e_paginacao_mantem_o_termo(): void
    {
        $mariaShopee = $this->pedido(['shipping_name' => 'Maria Shopee', 'origin' => Order::ORIGIN_SHOPEE, 'status' => Order::STATUS_SHIPPED, 'created_at' => '2026-10-10 10:00:00']);
        $mariaMl = $this->pedido(['shipping_name' => 'Maria ML', 'origin' => Order::ORIGIN_MERCADO_LIVRE, 'status' => Order::STATUS_PAID, 'created_at' => '2026-10-12 10:00:00']);
        $this->pedido(['shipping_name' => 'João Shopee', 'origin' => Order::ORIGIN_SHOPEE]);

        $this->assertSame([$mariaShopee->id], $this->ids('search=maria&origin=shopee'));
        $this->assertSame([$mariaMl->id], $this->ids('search=maria&status=paid'));
        $this->assertSame([$mariaMl->id], $this->ids('search=maria&de=2026-10-11&ate=2026-10-31'));
        $this->assertSame([$mariaShopee->id], $this->ids('search=maria&de=2026-10-10&ate=2026-10-10'));
        $this->assertSame([], $this->ids('search=maria&origin=amazon'));
        $this->assertEqualsCanonicalizing([$mariaShopee->id, $mariaMl->id], $this->ids('search=maria&status=qualquer&de=lixo'));

        for ($i = 0; $i < 51; $i++) {
            $this->pedido(['shipping_name' => 'Lote Paginação']);
        }

        $this->actingAs($this->admin)->get('/admin/pedidos?search=lote&origin=loja')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.total', 51)
                ->where('filters.search', 'lote')
                ->where('orders.next_page_url', fn ($url) => str_contains($url, 'search=lote') && str_contains($url, 'origin=loja')));
    }

    public function test_termo_estranho_nao_quebra(): void
    {
        $this->pedido();

        foreach (['%', '_', "'", '"', '\\', '#', '()', '13/13', 'a'] as $termo) {
            $this->actingAs($this->admin)->get('/admin/pedidos?'.http_build_query(['search' => $termo]))->assertOk();
        }
        $this->actingAs($this->admin)->get('/admin/pedidos?search[]=x')->assertOk();
    }
}
