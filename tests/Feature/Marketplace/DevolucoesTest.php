<?php

namespace Tests\Feature\Marketplace;

use App\Models\MercadoLivreToken;
use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceReturn;
use App\Modules\Marketplace\Support\ReturnsSyncService;
use App\Notifications\MarketplaceReturnAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Controle de devoluções (pedido do usuário 2026-10-06): abertura, decisão
 * da plataforma, envio de volta, conferência com veredito, prazos e o caso
 * que já deu prejuízo — encerrada sem o produto voltar. Respostas das APIs
 * no formato real conferido em produção.
 */
class DevolucoesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(), 'ml_user_id' => 3283064948, 'ml_nickname' => 'KAZAKORA',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHours(6),
            'scopes' => ['offline_access', 'read', 'write'],
        ]);
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE, 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123', 'access_token' => 'token', 'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHours(4), 'connected_at' => now(),
        ]);
    }

    private function pedido(string $canal, string $externo): Order
    {
        return Order::create([
            'status' => Order::STATUS_COMPLETED, 'origin' => $canal, 'external_order_id' => $externo,
            'shipping_name' => 'Maria Compradora', 'shipping_phone' => '11999999999', 'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => 50, 'total' => 50,
        ]);
    }

    private function fakeMercadoLivre(array $claim, ?array $devolucao): void
    {
        Http::fake([
            'https://api.mercadolibre.com/post-purchase/v1/claims/search*' => Http::response(['paging' => ['total' => 0], 'data' => []]),
            'https://api.mercadolibre.com/post-purchase/v1/claims/reasons/*' => Http::response(['name' => 'not_working_item', 'detail' => 'Chegou bem']),
            'https://api.mercadolibre.com/post-purchase/v2/claims/*' => $devolucao ? Http::response($devolucao) : Http::response(['message' => 'not found'], 404),
            'https://api.mercadolibre.com/post-purchase/v1/claims/*' => Http::response($claim),
        ]);
    }

    public function test_ml_claim_waiting_for_our_answer_gets_the_deadline_and_notifies(): void
    {
        Notification::fake();
        $order = $this->pedido(Order::ORIGIN_MERCADO_LIVRE, '2000018300341628');
        $prazo = now()->addHours(10)->toIso8601String();

        $this->fakeMercadoLivre([
            'id' => 5583787739, 'type' => 'returns', 'stage' => 'claim', 'status' => 'opened', 'reason_id' => 'PDD9949',
            'resource' => 'order', 'resource_id' => 2000018300341628, 'date_created' => now()->subDay()->toIso8601String(),
            'players' => [['role' => 'respondent', 'available_actions' => [['action' => 'refund', 'due_date' => $prazo, 'mandatory' => false]]]],
            'resolution' => null,
        ], ['status' => 'label_generated', 'status_money' => 'retained', 'refund_at' => 'delivered', 'shipments' => [['type' => 'return', 'status' => 'ready_to_ship', 'tracking_number' => 'AP546831992BR']]]);

        $caso = app(ReturnsSyncService::class)->sincronizarClaimMercadoLivre('5583787739');

        $this->assertSame(MarketplaceReturn::AGUARDANDO_RESPOSTA, $caso->situacao);
        $this->assertSame($order->id, $caso->order_id);
        $this->assertSame('Produto não funciona', $caso->reason_label);
        $this->assertNotNull($caso->respond_due_at);
        $this->assertSame('AP546831992BR', $caso->tracking_number);
        $this->assertContains('prazo_24h', collect($caso->alertas())->pluck('chave')->all());
        $this->assertGreaterThan(0, $caso->events()->count());
        Notification::assertSentTo($this->admin, MarketplaceReturnAlertNotification::class);
    }

    /** O caso que já deu prejuízo: estorno na postagem, plataforma encerra, produto não volta. */
    public function test_ml_closed_while_the_product_never_arrived_is_flagged(): void
    {
        Notification::fake();
        $this->fakeMercadoLivre([
            'id' => 1, 'type' => 'returns', 'stage' => 'claim', 'status' => 'closed', 'reason_id' => 'PDD9949',
            'resource' => 'order', 'resource_id' => 99, 'date_created' => now()->subDays(10)->toIso8601String(), 'players' => [],
            'resolution' => ['reason' => 'item_returned', 'benefited' => ['complainant'], 'date_created' => now()->subDay()->toIso8601String()],
        ], ['status' => 'shipped', 'status_money' => 'refunded', 'refund_at' => 'shipped', 'shipments' => [['type' => 'return', 'status' => 'shipped', 'tracking_number' => 'AP1BR']]]);

        $caso = app(ReturnsSyncService::class)->sincronizarClaimMercadoLivre('1');

        $this->assertSame(MarketplaceReturn::ENCERRADA, $caso->situacao);
        $this->assertTrue($caso->encerradaSemProduto());
        $this->assertContains('sem_produto', collect($caso->alertas())->pluck('chave')->all());
    }

    public function test_shopee_return_delivered_with_check_deadline_passed(): void
    {
        Notification::fake();
        Http::fake([
            '*/api/v2/returns/get_return_list*' => Http::sequence()
                ->push(['response' => ['more' => false, 'return' => [[
                    'return_sn' => '26092603UUD7UEE', 'order_sn' => '260923SG6QBAPX', 'status' => 'ACCEPTED', 'reason' => 'FUNCTIONAL_DMG',
                    'refund_amount' => 39.99, 'tracking_number' => 'BR265316558530G', 'due_date' => now()->subDays(8)->timestamp,
                    'return_seller_due_date' => now()->subDay()->timestamp, 'create_time' => now()->subDays(10)->timestamp, 'update_time' => now()->timestamp,
                ]]]])
                ->whenEmpty(Http::response(['response' => ['more' => false, 'return' => []]])),
            '*/api/v2/returns/get_return_detail*' => Http::response(['response' => ['logistics_status' => 'LOGISTICS_DELIVERY_DONE']]),
        ]);

        app(ReturnsSyncService::class)->sincronizarShopee();
        $caso = MarketplaceReturn::query()->where('external_id', '26092603UUD7UEE')->firstOrFail();

        $this->assertSame(MarketplaceReturn::ENTREGUE, $caso->situacao);
        $this->assertSame('Produto com defeito', $caso->reason_label);
        $this->assertSame(['chave' => 'conferir', 'nivel' => 'erro', 'texto' => 'Entregue — prazo pra conferir acabando'], $caso->alertas()[0]);
    }

    public function test_team_registers_a_tiktok_return_and_gives_the_verdict(): void
    {
        $order = $this->pedido(Order::ORIGIN_TIKTOK_SHOP, '5864336603');

        $this->actingAs($this->admin)->post('/admin/devolucoes', [
            'channel' => 'tiktok_shop', 'pedido' => '5864336603', 'kind' => 'devolucao', 'reason_label' => 'Faltando cabo',
            'situacao' => 'em_transito', 'tracking_number' => 'BR123',
        ])->assertRedirect();

        $caso = MarketplaceReturn::query()->firstOrFail();
        $this->assertTrue($caso->manual);
        $this->assertSame($order->id, $caso->order_id);

        $this->actingAs($this->admin)->post("/admin/devolucoes/{$caso->id}", ['acao' => 'receber'])->assertRedirect();
        $this->actingAs($this->admin)->post("/admin/devolucoes/{$caso->id}", ['acao' => 'veredito', 'verdict' => 'faltando_pecas', 'verdict_note' => 'Sem o cabo USB-C'])->assertRedirect();

        $caso->refresh();
        $this->assertSame(MarketplaceReturn::CONFERIDA, $caso->situacao);
        $this->assertSame('faltando_pecas', $caso->verdict);
        $this->assertNotNull($caso->received_at);
        $this->assertSame(3, $caso->events()->count());

        $this->actingAs($this->admin)->get('/admin/devolucoes?aba=todas')->assertOk()
            ->assertInertia(fn ($page) => $page->where('casos.0.veredito', 'faltando_pecas'));
    }

    /** A plataforma mudar o status depois não apaga o que a equipe conferiu. */
    public function test_platform_update_never_overwrites_the_team_verdict(): void
    {
        Notification::fake();
        MarketplaceReturn::create([
            'channel' => 'mercado_livre', 'external_id' => '7', 'kind' => 'devolucao', 'situacao' => MarketplaceReturn::CONFERIDA,
            'verdict' => 'mau_uso', 'received_at' => now(), 'opened_at' => now()->subDays(3),
        ]);
        $this->fakeMercadoLivre([
            'id' => 7, 'type' => 'returns', 'stage' => 'dispute', 'status' => 'opened', 'reason_id' => 'PDD9949',
            'resource' => 'order', 'resource_id' => 1, 'date_created' => now()->subDays(3)->toIso8601String(), 'players' => [], 'resolution' => null,
        ], ['status' => 'delivered', 'status_money' => 'retained', 'shipments' => []]);

        $caso = app(ReturnsSyncService::class)->sincronizarClaimMercadoLivre('7');

        $this->assertSame(MarketplaceReturn::CONFERIDA, $caso->situacao);
        $this->assertSame('mau_uso', $caso->verdict);
    }
}
