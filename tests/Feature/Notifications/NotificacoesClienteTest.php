<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Notifications\Cliente\PedidoConfirmado;
use App\Notifications\Cliente\SenhaTemporariaEnviada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Notificações do cliente no sino da loja (2026-10-11). */
class NotificacoesClienteTest extends TestCase
{
    use RefreshDatabase;

    public function test_tela_da_notificacao_marca_lida_e_leva_ao_pedido(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $order = Order::create([
            'user_id' => $user->id, 'status' => Order::STATUS_PAID, 'shipping_name' => 'M', 'shipping_phone' => '1',
            'shipping_zip' => '01001000', 'shipping_street' => 'R', 'shipping_number' => '1', 'shipping_neighborhood' => 'B',
            'shipping_city' => 'SP', 'shipping_state' => 'SP', 'subtotal' => 50, 'total' => 50,
        ]);
        $user->notify(new PedidoConfirmado($order));
        $user->notify(new SenhaTemporariaEnviada);

        $notificacao = $user->notifications()->where('type', PedidoConfirmado::class)->first();

        $this->actingAs($user)->get("/notificacoes/{$notificacao->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Notifications/Show', false)
                ->where('notificacao.link', "/pedidos/{$order->id}")
                ->where('notificacao.acao', 'Ver minha compra'));

        $this->assertNotNull($notificacao->fresh()->read_at);
        $this->assertSame('/perfil', $user->notifications()->where('type', SenhaTemporariaEnviada::class)->first()->data['link']);
    }

    public function test_nao_abre_notificacao_de_outro_cliente(): void
    {
        $dono = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $dono->notify(new SenhaTemporariaEnviada);
        $outro = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($outro)->get('/notificacoes/'.$dono->notifications()->first()->id)->assertNotFound();
    }
}
