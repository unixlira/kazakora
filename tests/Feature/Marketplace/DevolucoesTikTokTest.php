<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceReturn;
use App\Notifications\MarketplaceReturnAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Devoluções do TikTok Shop no controle de devoluções (pedido do Lira
 * 03/11/2026). Cartões reais da tela Pedidos › Devoluções do seller-br, lidos
 * em 03/11 (tests/fixtures/tiktok-devolucoes.json, sem as URLs de imagem).
 */
class DevolucoesTikTokTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-03 20:30', 'America/Sao_Paulo'));
        config(['services.tiktok_reports.token' => 'segredo-teste']);
        Notification::fake();

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function cartoes(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/fixtures/tiktok-devolucoes.json')), true)['cartoes'];
    }

    private function enviar(?array $cartoes = null, string $token = 'segredo-teste')
    {
        return $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/tiktok/devolucoes', ['cartoes' => $cartoes ?? $this->cartoes()]);
    }

    private function pedido(string $externo): Order
    {
        return Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_TIKTOK_SHOP, 'external_order_id' => $externo,
            'shipping_name' => 'Anna Cristina', 'shipping_phone' => '11999999999', 'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => 49.99, 'total' => 49.99,
        ]);
    }

    private function caso(string $devolucao): MarketplaceReturn
    {
        return MarketplaceReturn::query()->where('channel', Order::ORIGIN_TIKTOK_SHOP)->where('external_id', $devolucao)->firstOrFail();
    }

    public function test_token_errado_e_recusado(): void
    {
        $this->enviar(token: 'outro')->assertStatus(401);

        $this->assertSame(0, MarketplaceReturn::query()->count());
    }

    public function test_devolucao_em_transito_com_produto_motivo_e_observacao(): void
    {
        $pedido = $this->pedido('586734584491574900');

        $this->enviar()->assertOk()->assertJson(['ok' => true, 'recebidos' => 5, 'gravados' => 5]);

        $caso = $this->caso('4042860222953457268');
        $this->assertSame($pedido->id, $caso->order_id);
        $this->assertSame('586734584491574900', $caso->external_order_id);
        $this->assertSame(MarketplaceReturn::KIND_DEVOLUCAO, $caso->kind);
        $this->assertSame(MarketplaceReturn::EM_TRANSITO, $caso->situacao);
        $this->assertSame('shipped', $caso->return_status);
        $this->assertSame('refunded', $caso->money_status);
        $this->assertSame('Item com defeito', $caso->reason_label);
        $this->assertSame('O carregador funciona ate 15% e acaba a bateria', $caso->buyer_note);
        $this->assertStringContainsString('1x Mini Carregador Portátil Power Bank 10000mAh', $caso->product_label);
        $this->assertStringContainsString('(Preto) · SKU CAR-CAR-SEM-POW-PRE-0001', $caso->product_label);
        $this->assertSame('49.99', $caso->refund_amount);
        $this->assertSame('2026-10-29 09:44', $caso->opened_at->timezone('America/Sao_Paulo')->format('Y-m-d H:i'));
        $this->assertFalse($caso->manual);
        $this->assertContains('Aberta na plataforma: Item com defeito', $caso->events()->pluck('description')->all());

        Notification::assertSentTo($this->admin, MarketplaceReturnAlertNotification::class);
    }

    public function test_situacoes_do_tiktok_na_regua_do_controle(): void
    {
        $this->enviar()->assertOk();

        // Aguardando o cliente postar.
        $aguardando = $this->caso(MarketplaceReturn::query()->where('external_order_id', '586427725462013105')->value('external_id'));
        $this->assertSame(MarketplaceReturn::AGUARDANDO_ENVIO, $aguardando->situacao);
        $this->assertSame('pending', $aguardando->return_status);
        $this->assertNull($aguardando->order_id);
        $this->assertSame('29.99', $aguardando->refund_amount);

        // Apenas reembolso (pacote não recebido): sem produto voltando.
        $reembolso = MarketplaceReturn::query()->where('external_order_id', '586176586013246869')->firstOrFail();
        $this->assertSame(MarketplaceReturn::KIND_RECLAMACAO, $reembolso->kind);
        $this->assertSame(MarketplaceReturn::ENCERRADA, $reembolso->situacao);
        $this->assertNull($reembolso->return_status);
        $this->assertSame([], $reembolso->alertas());

        // Voltou e o TikTok já fechou: encerrada, com a entrega registrada.
        $entregue = MarketplaceReturn::query()->where('external_order_id', '586232590991656893')->firstOrFail();
        $this->assertSame(MarketplaceReturn::ENCERRADA, $entregue->situacao);
        $this->assertSame('delivered', $entregue->return_status);
        $this->assertNotNull($entregue->delivered_at);
        $this->assertNotNull($entregue->closed_at);
        $this->assertFalse($entregue->encerradaSemProduto());

        $encerrada = MarketplaceReturn::query()->where('external_order_id', '586323262291346609')->firstOrFail();
        $this->assertSame(MarketplaceReturn::ENCERRADA, $encerrada->situacao);
    }

    public function test_reenvio_nao_duplica_nem_repete_historico(): void
    {
        $this->enviar()->assertOk();
        $eventos = \App\Modules\Marketplace\Models\MarketplaceReturnEvent::query()->count();

        $this->enviar()->assertOk();

        $this->assertSame(5, MarketplaceReturn::query()->count());
        $this->assertSame($eventos, \App\Modules\Marketplace\Models\MarketplaceReturnEvent::query()->count());
    }

    public function test_tiktok_nao_prepara_nota_de_devolucao_aqui(): void
    {
        $this->pedido('586734584491574900');

        $this->enviar()->assertOk();

        $this->assertSame('pelo_canal', $this->caso('4042860222953457268')->fresh()->devolucao_fiscal_status);
    }

    public function test_aparece_no_painel_de_devolucoes_com_produto_e_observacao(): void
    {
        $this->enviar()->assertOk();

        $this->actingAs($this->admin)->get('/admin/devolucoes?canal=tiktok_shop')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('casos', fn ($casos) => collect($casos)->contains(fn ($c) => $c['pedidoExterno'] === '586734584491574900'
                    && str_contains((string) $c['produtos'], 'Power Bank 10000mAh')
                    && $c['observacaoCliente'] === 'O carregador funciona ate 15% e acaba a bateria'
                    && $c['situacao'] === MarketplaceReturn::EM_TRANSITO))
                ->where('casos', fn ($casos) => collect($casos)->contains(fn ($c) => $c['pedidoExterno'] === '586427725462013105' && $c['situacao'] === MarketplaceReturn::AGUARDANDO_ENVIO)));
    }

    public function test_comando_importa_a_resposta_crua_da_tela(): void
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'tt');
        file_put_contents($arquivo, json_encode(json_encode(['code' => 0, 'data' => ['cards' => $this->cartoes()]])));

        $this->artisan('devolucoes:tiktok-importar', ['arquivo' => $arquivo])->assertSuccessful();

        $this->assertSame(5, MarketplaceReturn::query()->where('channel', Order::ORIGIN_TIKTOK_SHOP)->count());
    }
}
