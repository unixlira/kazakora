<?php

namespace Tests\Feature\Marketplace;

use App\Models\MercadoLivreToken;
use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\FlexPickupReceipt;
use App\Modules\Marketplace\Models\MarketplaceClaim;
use App\Modules\Marketplace\Support\FlexControlService;
use App\Notifications\FlexShipmentAlertNotification;
use App\Services\MercadoLivre\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Envios Flex (2026-09-11): o espelho do status do ML, os alertas e a
 * guarda do comprovante como prova.
 */
class EnviosFlexControlTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** Resposta atual do ML por envio. Http::fake repetido não troca a 1ª resposta registrada. */
    private array $respostasMl = [];

    protected function setUp(): void
    {
        parent::setUp();

        MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(),
            'ml_user_id' => 123456789,
            'ml_nickname' => 'LOJA_KAZAKORA',
            'access_token' => 'valid-access-token',
            'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->addHours(6),
            'scopes' => ['offline_access', 'read', 'write'],
        ]);

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Http::fake(function ($request) {
            foreach ($this->respostasMl as $envio => $corpo) {
                if (str_ends_with($request->url(), "/shipments/{$envio}")) {
                    return Http::response($corpo);
                }
            }

            return Http::response([], 404);
        });
    }

    private function envioFlex(string $envio, array $pedido = []): ChannelShipment
    {
        $order = Order::create(array_merge([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => 'VENDA-'.$envio,
            'shipping_name' => 'Cliente Flex',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '06010170',
            'shipping_street' => 'Rua Teste',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'Osasco',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ], array_diff_key($pedido, array_flip(['packed_at', 'collected_at', 'ready_for_pickup_at', 'stock_restored_at', 'created_at']))));

        $order->forceFill(array_intersect_key($pedido, array_flip(['packed_at', 'collected_at', 'ready_for_pickup_at', 'stock_restored_at', 'created_at'])))->save();

        $order->items()->create(['product_name' => 'Bicicleta Ergométrica', 'product_price' => 100, 'quantity' => 1, 'subtotal' => 100]);

        return ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => 'mercado_livre',
            'external_shipment_id' => $envio,
            'shipping_method' => ChannelShipment::METHOD_FLEX,
            'status' => ChannelShipment::STATUS_LABEL_DOWNLOADED,
        ]);
    }

    private function mlResponde(string $envio, string $status, array $historico = [], ?string $substatus = null): void
    {
        $this->respostasMl[$envio] = [
            'id' => (int) $envio,
            'status' => $status,
            'substatus' => $substatus,
            'status_history' => array_merge([
                'date_shipped' => null, 'date_returned' => null, 'date_delivered' => null, 'date_first_visit' => null,
                'date_not_delivered' => null, 'date_cancelled' => null,
            ], $historico),
        ];
    }

    /**
     * O caso da bicicleta (#1384): separada, venda cancelada, o ML nunca
     * registrou a rota — e o estoque voltou sozinho como se ela estivesse
     * aqui. Tem que virar alerta, uma vez só na sineta.
     */
    public function test_a_sale_cancelled_after_packing_with_no_route_raises_the_alert_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-04 20:00:00'));

        $envio = $this->envioFlex('47932374885', [
            'status' => Order::STATUS_CANCELLED,
            'packed_at' => Carbon::parse('2026-09-04 12:22:06'),
            'stock_restored_at' => Carbon::parse('2026-09-04 19:35:05'),
        ]);

        $this->mlResponde('47932374885', 'cancelled', ['date_cancelled' => '2026-09-04T18:32:37.022-04:00']);

        app(ShipmentService::class)->syncOrderStatusFromShipment($envio);

        $envio->refresh();

        $this->assertSame('cancelled', $envio->channel_status);
        $this->assertSame('2026-09-04 19:32', $envio->channel_cancelled_at->format('Y-m-d H:i'), 'data do ML convertida pro fuso da loja');
        $this->assertNull($envio->channel_shipped_at);
        $this->assertSame(['cancelada_apos_separar'], $envio->flex_alerts);

        app(FlexControlService::class)->reavaliar($envio->refresh());

        $this->assertSame(1, $this->admin->notifications()->where('type', FlexShipmentAlertNotification::class)->count(), 'não repete o aviso');
    }

    /** Entregador levou e não iniciou a rota: alerta depois da tolerância, some quando a rota começa. */
    public function test_a_carrier_that_did_not_start_the_route_is_flagged_until_the_channel_shows_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 14:00:00'));

        $envio = $this->envioFlex('5001', ['collected_at' => Carbon::parse('2026-09-11 13:00:00')]);
        $this->mlResponde('5001', 'ready_to_ship', [], 'printed');

        app(ShipmentService::class)->syncOrderStatusFromShipment($envio);
        $this->assertNull($envio->refresh()->flex_alerts, 'dentro da tolerância de 2h ainda não é alerta');

        Carbon::setTestNow(Carbon::parse('2026-09-11 16:30:00'));
        app(FlexControlService::class)->reavaliar($envio->refresh());
        $this->assertSame(['rota_nao_iniciada'], $envio->refresh()->flex_alerts);

        $this->mlResponde('5001', 'shipped', ['date_shipped' => '2026-09-11T15:40:00.000-04:00']);
        app(ShipmentService::class)->syncOrderStatusFromShipment($envio->refresh());

        $this->assertNull($envio->refresh()->flex_alerts);
        $this->assertSame(Order::STATUS_SHIPPED, $envio->order->refresh()->status);
    }

    /**
     * Conferir resolve o que estava aberto — mas uma reclamação que abre
     * DEPOIS volta a alertar, em vez de ficar escondida.
     */
    public function test_resolving_closes_current_alerts_but_a_later_claim_opens_again(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00'));

        $envio = $this->envioFlex('6001', ['status' => Order::STATUS_CANCELLED]);
        $envio->forceFill(['channel_status' => 'delivered', 'channel_delivered_at' => Carbon::parse('2026-09-01 16:45:00')])->save();

        app(FlexControlService::class)->reavaliar($envio);
        $this->assertSame(['cancelada_apos_entrega'], $envio->refresh()->flex_alerts);

        $this->actingAs($this->admin)
            ->post("/admin/envios-flex/{$envio->id}/resolver", ['tipo' => 'voltou', 'nota' => 'Voltou com o entregador, sem avaria.'])
            ->assertRedirect();

        $envio->refresh();
        $this->assertNull($envio->flex_alerts);
        $this->assertSame('voltou', $envio->return_resolution);
        $this->assertSame($this->admin->id, $envio->return_resolved_by);
        $this->assertTrue(AuditLog::query()->where('action', 'flex_resolver')->exists());

        MarketplaceClaim::create([
            'order_id' => $envio->order_id,
            'channel' => 'mercado_livre',
            'external_claim_id' => '555',
            'type' => 'returns',
            'stage' => 'claim',
            'status' => 'opened',
            'claim_created_at' => now(),
        ]);

        app(FlexControlService::class)->reavaliar($envio->refresh());

        $this->assertSame(['reclamacao_555'], $envio->refresh()->flex_alerts);
    }

    /** A tela abre, e ver a foto do entregador fica registrado na auditoria. */
    public function test_the_page_lists_alerts_and_every_image_view_is_audited(): void
    {
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-09-11 18:00:00'));

        $envio = $this->envioFlex('7001', ['collected_at' => Carbon::parse('2026-09-11 13:00:00')]);
        Storage::disk('local')->put('flex/recibos/1/foto.jpg', "\xFF\xD8\xFFfoto");

        $recibo = FlexPickupReceipt::create([
            'carrier_name' => 'João',
            'collected_at' => Carbon::parse('2026-09-11 13:00:00'),
            'consented_at' => Carbon::parse('2026-09-11 13:00:00'),
            'photo_path' => 'flex/recibos/1/foto.jpg',
            'photo_sha256' => hash('sha256', "\xFF\xD8\xFFfoto"),
            'order_ids' => [$envio->order_id],
            'orders_count' => 1,
        ]);
        $envio->order->forceFill(['pickup_receipt_id' => $recibo->id])->save();

        $this->actingAs($this->admin)
            ->get('/admin/envios-flex')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/EnviosFlex/Index', false)
                ->where('filtros.aba', 'alertas')
                ->where('linhas.0.pedido', $envio->order_id)
                ->where('linhas.0.alertas.0.codigo', 'rota_nao_iniciada'));

        $this->actingAs($this->admin)->get("/admin/envios-flex/recibos/{$recibo->id}/foto")->assertOk();

        $this->assertTrue(AuditLog::query()->where('action', 'flex_ver_imagem')->where('entity_id', $recibo->id)->exists());
    }

    /** Foto de pessoa não sai pra quem não está logado na equipe. */
    public function test_receipt_images_require_a_logged_in_staff_member(): void
    {
        $recibo = FlexPickupReceipt::create([
            'collected_at' => now(),
            'photo_path' => 'flex/recibos/9/foto.jpg',
            'order_ids' => [],
            'orders_count' => 0,
        ]);

        $this->get("/admin/envios-flex/recibos/{$recibo->id}/foto")->assertRedirect();

        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->actingAs($cliente)->get("/admin/envios-flex/recibos/{$recibo->id}/foto")->assertStatus(403);
    }

    /** Retenção: recibo retido ou em disputa não perde a foto; o resto sim. */
    public function test_the_retention_purge_keeps_receipts_that_are_evidence(): void
    {
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-09-11 10:00:00'));

        $velho = Carbon::parse('2026-01-01 10:00:00');

        $recibo = function (array $pedidos, array $extra = []) use ($velho) {
            $r = FlexPickupReceipt::create(array_merge([
                'collected_at' => $velho,
                'photo_path' => 'flex/recibos/'.uniqid().'/foto.jpg',
                'order_ids' => $pedidos,
                'orders_count' => count($pedidos),
            ], $extra));
            Storage::disk('local')->put($r->photo_path, "\xFF\xD8\xFF");

            return $r;
        };

        $limpo = $recibo([$this->envioFlex('8001', ['status' => Order::STATUS_COMPLETED])->order_id]);
        $retido = $recibo([$this->envioFlex('8002', ['status' => Order::STATUS_COMPLETED])->order_id], ['legal_hold_at' => now(), 'legal_hold_reason' => 'processo']);
        $emDisputa = $recibo([$this->envioFlex('8003', ['status' => Order::STATUS_CANCELLED, 'collected_at' => $velho])->order_id]);

        $this->artisan('koraflex:limpar-recibos')->assertSuccessful();

        $this->assertNull($limpo->refresh()->photo_path);
        $this->assertNotNull($retido->refresh()->photo_path);
        $this->assertNotNull($emDisputa->refresh()->photo_path);
    }
}
