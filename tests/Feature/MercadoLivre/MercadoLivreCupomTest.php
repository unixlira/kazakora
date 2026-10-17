<?php

namespace Tests\Feature\MercadoLivre;

use App\Models\MercadoLivreToken;
use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MercadoLivreCupom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Tela Integrações › Mercado Livre › Criar cupom. A API do ML é SEMPRE
 * falsificada aqui (Http::fake + preventStrayRequests): nenhum teste pode
 * criar cupom de verdade.
 */
class MercadoLivreCupomTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/integracoes/mercado-livre/cupom';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Páginas ficam em resources/js/Modules, fora do caminho padrão do Inertia.
        config(['inertia.testing.ensure_pages_exist' => false]);
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function token(): void
    {
        MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(),
            'ml_user_id' => 123456789,
            'ml_nickname' => 'LOJA_KAZAKORA',
            'access_token' => 'valid-access-token',
            'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->addHours(6),
            'scopes' => ['offline_access', 'read', 'write'],
        ]);
    }

    private function pedidoMl(float $subtotal, string $status = Order::STATUS_COMPLETED, ?Carbon $quando = null): void
    {
        $order = Order::create([
            'origin' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'external_order_id' => 'ML-'.uniqid(),
            'status' => $status,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua A',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => $subtotal,
            'total' => $subtotal,
        ]);
        if ($quando) {
            $order->forceFill(['created_at' => $quando])->save();
        }
    }

    private function dadosValidos(array $extra = []): array
    {
        return array_merge([
            'nome' => 'KZ teste',
            'percentual' => 50,
            'desconto_maximo' => 6,
            'compra_minima' => 2,
            'orcamento' => 60,
            'inicio' => '2026-10-16',
            'fim' => '2026-11-14',
            'codigo' => '',
            'incluir_anuncios' => false,
        ], $extra);
    }

    public function test_tela_abre_com_modelo_preenchido_e_compra_minima_de_2_por_cento_do_ticket(): void
    {
        $this->pedidoMl(100);
        $this->pedidoMl(80, Order::STATUS_SHIPPED);
        $this->pedidoMl(500, Order::STATUS_CANCELLED);            // cancelado não entra
        $this->pedidoMl(900, Order::STATUS_COMPLETED, now()->subDays(45)); // fora da janela

        $this->actingAs($this->admin())->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Integracoes/MercadoLivre/Cupom')
                ->where('ticket.valor', 90)
                ->where('ticket.pedidos', 2)
                ->where('modelo.percentual', 50)
                ->where('modelo.desconto_maximo', 6)
                ->where('modelo.compra_minima_pct_ticket', 2)
                ->where('modelo.compra_minima', 1.8)
                ->where('modelo.inicio', '2026-10-16')
                ->where('modelo.fim', '2026-11-14')
                ->where('envioHabilitado', false));
    }

    public function test_envio_desligado_no_homolog_nao_chama_o_ml(): void
    {
        config(['app.url' => 'https://kazakora.devlira.com.br', 'mercadolivre.cupom.envio_habilitado' => null]);
        Http::fake();

        $this->actingAs($this->admin())->post(self::URL, $this->dadosValidos())
            ->assertRedirect()
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame(0, MercadoLivreCupom::count());
    }

    public function test_cria_cupom_com_payload_do_ml_e_inclui_anuncios_candidatos(): void
    {
        config(['app.url' => 'https://kazakora.com', 'mercadolivre.cupom.envio_habilitado' => null]);
        $this->token();

        Http::fake([
            'api.mercadolibre.com/seller-promotions/promotions?app_version=v2' => Http::response([
                'id' => 'C-MLB999', 'type' => 'SELLER_COUPON_CAMPAIGN', 'status' => 'pending',
            ], 201),
            'api.mercadolibre.com/seller-promotions/promotions/C-MLB999/items*' => Http::response([
                'results' => [
                    ['id' => 'MLB1', 'status' => 'candidate'],
                    ['id' => 'MLB2', 'status' => 'candidate'],
                    ['id' => 'MLB3', 'status' => 'started'],
                ],
                'paging' => ['total' => 3, 'limit' => 50],
            ]),
            'api.mercadolibre.com/seller-promotions/items/MLB1*' => Http::response(['price' => 0]),
            'api.mercadolibre.com/seller-promotions/items/MLB2*' => Http::response(['message' => 'item not allowed'], 400),
        ]);

        $admin = $this->admin();
        $this->actingAs($admin)->post(self::URL, $this->dadosValidos(['codigo' => 'kz6', 'incluir_anuncios' => true]))
            ->assertRedirect()
            ->assertSessionHas('warning');

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/seller-promotions/promotions?app_version=v2')
            && $r['promotion_type'] === 'SELLER_COUPON_CAMPAIGN'
            && $r['sub_type'] === 'FIXED_PERCENTAGE'
            && $r['fixed_percentage'] == 50
            && $r['max_purchase_amount'] == 6
            && $r['min_purchase_amount'] == 2
            && $r['budget'] == 60
            && $r['start_date'] === '2026-10-16T00:00:00'
            && $r['finish_date'] === '2026-11-14T00:00:00'
            && $r['partial_coupon_code'] === 'KZ6');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/seller-promotions/items/MLB1') && $r['promotion_id'] === 'C-MLB999');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/seller-promotions/items/MLB3'));

        $cupom = MercadoLivreCupom::sole();
        $this->assertSame('criado', $cupom->status);
        $this->assertSame('C-MLB999', $cupom->ml_promotion_id);
        $this->assertSame($admin->id, $cupom->user_id);
        $this->assertSame(1, $cupom->itens_adicionados);
        $this->assertSame(1, $cupom->itens_com_erro);
    }

    public function test_erro_do_ml_fica_registrado_e_nao_repete_a_criacao(): void
    {
        config(['mercadolivre.cupom.envio_habilitado' => true]);
        $this->token();
        Http::fake([
            'api.mercadolibre.com/*' => Http::response(['message' => 'the name already exists'], 400),
        ]);

        $this->actingAs($this->admin())->post(self::URL, $this->dadosValidos())
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'the name already exists'));

        Http::assertSentCount(1);
        $cupom = MercadoLivreCupom::sole();
        $this->assertSame('erro', $cupom->status);
        $this->assertSame('the name already exists', $cupom->erro);
    }

    public function test_erro_500_do_ml_nao_dispara_segunda_tentativa(): void
    {
        config(['mercadolivre.cupom.envio_habilitado' => true]);
        $this->token();
        Http::fake(['api.mercadolibre.com/*' => Http::response([], 500)]);

        $this->actingAs($this->admin())->post(self::URL, $this->dadosValidos())->assertSessionHas('error');

        Http::assertSentCount(1);
    }

    public function test_valida_regras_do_ml_antes_de_enviar(): void
    {
        config(['mercadolivre.cupom.envio_habilitado' => true]);
        Http::fake();
        $admin = $this->admin();

        // 50% de R$ 12 = R$ 6, igual ao teto: o ML recusa.
        $this->actingAs($admin)->post(self::URL, $this->dadosValidos(['compra_minima' => 12]))->assertSessionHasErrors('compra_minima');
        $this->actingAs($admin)->post(self::URL, $this->dadosValidos(['percentual' => 90]))->assertSessionHasErrors('percentual');
        $this->actingAs($admin)->post(self::URL, $this->dadosValidos(['desconto_maximo' => 5]))->assertSessionHasErrors('desconto_maximo');
        $this->actingAs($admin)->post(self::URL, $this->dadosValidos(['orcamento' => 6]))->assertSessionHasErrors('orcamento');
        $this->actingAs($admin)->post(self::URL, $this->dadosValidos(['fim' => '2026-11-16']))->assertSessionHasErrors('fim');
        $this->actingAs($admin)->post(self::URL, $this->dadosValidos(['inicio' => '2026-10-15']))->assertSessionHasErrors('inicio');
        $this->actingAs($admin)->post(self::URL, $this->dadosValidos(['codigo' => 'LONGO1']))->assertSessionHasErrors('codigo');

        Http::assertNothingSent();
    }

    public function test_so_admin_envia(): void
    {
        config(['mercadolivre.cupom.envio_habilitado' => true]);
        Http::fake();
        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($gerente)->post(self::URL, $this->dadosValidos())->assertStatus(403);

        Http::assertNothingSent();
    }
}
