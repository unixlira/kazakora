<?php

namespace Tests\Feature\Loja;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Support\GoogleAds;
use App\Support\Privacidade\Cookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tag do Google Ads: conversão "Compra" só com aceite de cookies (2026-11-19). */
class TagGoogleAdsTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_ads_tag.conversion_id' => 'AW-123456789',
            'services.google_ads_tag.purchase_label' => 'AbC-dEf_123',
            'services.google_ads_tag.enhanced_conversions' => false,
        ]);
    }

    private function pedidoPago(array $extra = [], string $email = ' Cliente.Teste@Example.com '): Order
    {
        $user = User::factory()->create(['email' => $email, 'phone' => '(11) 98888-7777']);
        $product = Product::factory()->create(['stock' => 10, 'is_active' => true]);

        $order = Order::create([
            'user_id' => $user->id,
            'origin' => Order::ORIGIN_STORE,
            'external_order_id' => '261119XYZ',
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'José da Silva',
            'shipping_phone' => '11988887777',
            'shipping_zip' => '01310-100',
            'shipping_street' => 'Av. Paulista',
            'shipping_number' => '1000',
            'shipping_neighborhood' => 'Bela Vista',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 189.9,
            'total' => 189.9,
            ...$extra,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 189.9,
            'quantity' => 1,
            'subtotal' => 189.9,
        ]);

        return $order;
    }

    public function test_id_e_rotulo_invalidos_desligam_a_tag(): void
    {
        $this->assertSame('AW-123456789', GoogleAds::id());

        config(['services.google_ads_tag.conversion_id' => 'G-ABC123']);
        $this->assertNull(GoogleAds::id());

        config(['services.google_ads_tag.conversion_id' => 'AW-123456789', 'services.google_ads_tag.purchase_label' => '<script>']);
        $this->assertNull(GoogleAds::rotuloCompra());
    }

    public function test_status_pago_com_aceite_entrega_a_compra_com_valor_e_numero_do_pedido(): void
    {
        $order = $this->pedidoPago();

        $this->actingAs($order->user)->withCredentials()->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO)
            ->getJson("/finalizacao/{$order->id}/status")
            ->assertOk()
            ->assertJsonPath('google_compra.id', 'AW-123456789')
            ->assertJsonPath('google_compra.send_to', 'AW-123456789/AbC-dEf_123')
            ->assertJsonPath('google_compra.value', 189.9)
            ->assertJsonPath('google_compra.currency', 'BRL')
            ->assertJsonPath('google_compra.transaction_id', '261119XYZ')
            ->assertJsonMissingPath('google_compra.user_data');
    }

    public function test_sem_aceite_ou_com_aceite_vencido_nao_entrega_a_compra(): void
    {
        $order = $this->pedidoPago();

        $this->actingAs($order->user)->withCredentials()->getJson("/finalizacao/{$order->id}/status")
            ->assertOk()->assertJsonPath('google_compra', null);

        $this->actingAs($order->user)->withCredentials()->withCookie(Cookies::COOKIE_OK, '2020-01-01')
            ->getJson("/finalizacao/{$order->id}/status")
            ->assertOk()->assertJsonPath('google_compra', null);
    }

    public function test_sem_tag_configurada_ou_pedido_nao_pago_nao_entrega_a_compra(): void
    {
        $naoPago = $this->pedidoPago(['status' => Order::STATUS_AWAITING_PAYMENT]);
        $this->actingAs($naoPago->user)->withCredentials()->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO)
            ->getJson("/finalizacao/{$naoPago->id}/status")
            ->assertOk()->assertJsonPath('google_compra', null);

        config(['services.google_ads_tag.purchase_label' => null]);
        $pago = $this->pedidoPago(['external_order_id' => '261119ZZZ'], 'outro.cliente@example.com');
        $this->actingAs($pago->user)->withCredentials()->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO)
            ->getJson("/finalizacao/{$pago->id}/status")
            ->assertOk()->assertJsonPath('google_compra', null);
    }

    public function test_pedido_de_outro_site_nao_vai_para_o_google(): void
    {
        $order = $this->pedidoPago(['site_origem' => Order::SITE_FERRAKORA]);

        $this->assertNull(GoogleAds::compra($order->fresh(), \Illuminate\Http\Request::create('/', 'GET', [], [Cookies::COOKIE_OK => Cookies::VERSAO])));
    }

    public function test_conversoes_otimizadas_mandam_email_e_celular_com_hash(): void
    {
        config(['services.google_ads_tag.enhanced_conversions' => true]);
        $order = $this->pedidoPago();

        $compra = GoogleAds::compra($order, \Illuminate\Http\Request::create('/', 'GET', [], [Cookies::COOKIE_OK => Cookies::VERSAO]));

        $this->assertSame(hash('sha256', 'cliente.teste@example.com'), $compra['user_data']['sha256_email_address']);
        $this->assertSame(hash('sha256', '+5511988887777'), $compra['user_data']['sha256_phone_number']);
    }

    public function test_confirmacao_paga_com_aceite_leva_a_compra(): void
    {
        $order = $this->pedidoPago();

        $this->actingAs($order->user)->withCredentials()->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO)
            ->get("/finalizacao/{$order->id}/confirmacao")
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Checkout/Confirmation', false)
                ->where('googleCompra.transaction_id', '261119XYZ')
                ->where('cookies.googleAds', 'AW-123456789'));
    }

    public function test_clique_do_google_fica_na_sessao_e_vira_gcl_aw_so_no_aceite(): void
    {
        config(['app.url' => 'https://kazakora.com']);

        $this->withHeader('User-Agent', self::NAVEGADOR)->get('https://kazakora.com/politica-de-entrega?gclid=Cj0KCQiAabcdefghij')
            ->assertSessionHas('google_gclid.valor', 'Cj0KCQiAabcdefghij')
            ->assertCookieMissing('_gcl_aw');

        $this->withHeader('User-Agent', self::NAVEGADOR)
            ->withSession(['google_gclid' => ['valor' => 'Cj0KCQiAabcdefghij', 'em' => 1795100000]])
            ->post('https://kazakora.com/cookies/aceitar')
            ->assertCookie('_gcl_aw', 'GCL.1795100000.Cj0KCQiAabcdefghij', false);
    }

    public function test_aceite_nao_troca_o_gcl_aw_que_a_tag_ja_criou(): void
    {
        $this->withHeader('User-Agent', self::NAVEGADOR)
            ->withUnencryptedCookie('_gcl_aw', 'GCL.1.da-tag')
            ->withSession(['google_gclid' => ['valor' => 'Cj0KCQiAabcdefghij', 'em' => 1795100000]])
            ->post('/cookies/aceitar')
            ->assertCookieMissing('_gcl_aw');
    }
}
