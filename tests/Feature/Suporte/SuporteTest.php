<?php

namespace Tests\Feature\Suporte;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Suporte\Jobs\ResponderComManuela;
use App\Modules\Suporte\Models\Chamado;
use App\Modules\Suporte\Models\MensagemSuporte;
use App\Modules\Suporte\Services\ManuelaSuporte;
use App\Modules\WhatsApp\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

/**
 * Meus Pedidos, detalhe da compra e suporte com a Manuela (pedido 2026-10-11).
 */
class SuporteTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(?User $user = null, array $extra = []): Order
    {
        $user ??= User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $order = Order::create(array_merge([
            'user_id' => $user->id,
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ], $extra));
        $order->items()->create(['product_name' => 'Organizador', 'product_price' => 50, 'quantity' => 2, 'subtotal' => 100, 'item_type' => OrderItem::TYPE_PRODUCT]);

        return $order;
    }

    private function gemini(?string $resposta): void
    {
        $mock = Mockery::mock(GeminiClient::class);
        $mock->shouldReceive('isConfigured')->andReturn($resposta !== null);
        if ($resposta !== null) {
            $mock->shouldReceive('generate')->andReturn($resposta);
        }
        $this->app->instance(GeminiClient::class, $mock);
    }

    public function test_meus_pedidos_esconde_os_que_aguardam_pagamento(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $pago = $this->pedido($user);
        $this->pedido($user, ['status' => Order::STATUS_AWAITING_PAYMENT]);

        $this->actingAs($user)->get('/pedidos')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $pago->id)
                ->where('orders.data.0.itens.0.nome', 'Organizador'));
    }

    public function test_detalhe_da_compra_so_para_o_dono(): void
    {
        $order = $this->pedido();

        $this->actingAs($order->user)->get("/pedidos/{$order->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Checkout/OrderDetail', false)
                ->where('pedido.id', $order->id)
                ->where('pedido.itens.0.quantidade', 2)
                ->has('pedido.etapas', 6));

        $outro = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->actingAs($outro)->get("/pedidos/{$order->id}")->assertForbidden();
        $this->actingAs($outro)->getJson("/pedidos/{$order->id}/suporte")->assertForbidden();
        $this->actingAs($outro)->postJson("/pedidos/{$order->id}/suporte", ['mensagem' => 'oi'])->assertForbidden();
    }

    public function test_cliente_abre_conversa_e_a_manuela_entra_na_fila(): void
    {
        Queue::fake();
        $order = $this->pedido();

        $this->actingAs($order->user)->postJson("/pedidos/{$order->id}/suporte", ['mensagem' => 'Quando chega?'])
            ->assertOk()
            ->assertJsonPath('chamado.status', Chamado::CONVERSA)
            ->assertJsonPath('mensagens.0.autor', MensagemSuporte::CLIENTE);

        Queue::assertPushed(ResponderComManuela::class);
        $this->assertSame(1, Chamado::first()->nao_lidas_admin);
    }

    public function test_manuela_responde_e_abre_chamado_quando_o_caso_e_serio(): void
    {
        $this->gemini(json_encode([
            'resposta' => 'Sinto muito! Já abri um chamado para a troca.',
            'abrir_chamado' => true, 'assunto' => 'Produto com defeito', 'prioridade' => 'alta', 'resumo' => 'Cliente recebeu peça quebrada.',
        ]));
        $order = $this->pedido();
        $chamado = Chamado::create(['numero' => Chamado::gerarNumero(), 'user_id' => $order->user_id, 'order_id' => $order->id, 'status' => Chamado::CONVERSA, 'aberto_por' => 'cliente']);
        $chamado->registrar(MensagemSuporte::CLIENTE, 'Chegou quebrado');

        app(ManuelaSuporte::class)->responder($chamado);

        $chamado->refresh();
        $this->assertSame(Chamado::ABERTO, $chamado->status);
        $this->assertSame('alta', $chamado->prioridade);
        $this->assertSame('Produto com defeito', $chamado->assunto);
        $this->assertSame(
            [MensagemSuporte::CLIENTE, MensagemSuporte::MANUELA, MensagemSuporte::SISTEMA],
            $chamado->mensagens()->pluck('autor')->all(),
        );
    }

    public function test_manuela_manda_para_o_botao_quando_o_cliente_ainda_pode_cancelar(): void
    {
        $instrucoes = [];
        $mock = Mockery::mock(GeminiClient::class);
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('generate')->andReturnUsing(function ($modelo, $historico, $sistema) use (&$instrucoes) {
            $instrucoes[] = $sistema;

            return json_encode(['resposta' => 'ok', 'abrir_chamado' => false]);
        });
        $this->app->instance(GeminiClient::class, $mock);

        foreach ([Order::STATUS_PAID, Order::STATUS_SHIPPED] as $status) {
            $order = $this->pedido(null, ['status' => $status, 'origin' => Order::ORIGIN_STORE]);
            $chamado = Chamado::create(['numero' => Chamado::gerarNumero(), 'user_id' => $order->user_id, 'order_id' => $order->id, 'status' => Chamado::CONVERSA, 'aberto_por' => 'cliente']);
            $chamado->registrar(MensagemSuporte::CLIENTE, 'Desisti, como faço para não receber?');
            app(ManuelaSuporte::class)->responder($chamado);
        }

        $this->assertStringContainsString('botão "Cancelar Pedido"', $instrucoes[0]);
        $this->assertStringContainsString('NÃO abra chamado para isso', $instrucoes[0]);
        $this->assertStringContainsString('não está disponível', $instrucoes[1]);
    }

    public function test_pedido_de_cancelamento_aponta_o_botao_sem_abrir_chamado_mesmo_sem_ia(): void
    {
        $this->gemini(null);
        $order = $this->pedido(null, ['origin' => Order::ORIGIN_STORE]);
        $chamado = Chamado::create(['numero' => Chamado::gerarNumero(), 'user_id' => $order->user_id, 'order_id' => $order->id, 'status' => Chamado::CONVERSA, 'aberto_por' => 'cliente']);
        $chamado->registrar(MensagemSuporte::CLIENTE, 'Quero cancelar meu pedido');

        app(ManuelaSuporte::class)->responder($chamado);

        $this->assertSame(Chamado::CONVERSA, $chamado->fresh()->status);
        $this->assertStringContainsString('Cancelar Pedido', (string) $chamado->mensagens()->where('autor', MensagemSuporte::MANUELA)->value('conteudo'));
    }

    public function test_sem_ia_responde_padrao_e_abre_chamado(): void
    {
        $this->gemini(null);
        $order = $this->pedido();
        $chamado = Chamado::create(['numero' => Chamado::gerarNumero(), 'user_id' => $order->user_id, 'order_id' => $order->id, 'status' => Chamado::CONVERSA, 'aberto_por' => 'cliente']);
        $chamado->registrar(MensagemSuporte::CLIENTE, 'Oi');

        app(ManuelaSuporte::class)->responder($chamado);

        $this->assertSame(Chamado::ABERTO, $chamado->fresh()->status);
        $this->assertSame(ManuelaSuporte::SEM_IA, $chamado->mensagens()->where('autor', MensagemSuporte::MANUELA)->value('conteudo'));
    }

    public function test_admin_so_responde_com_atendimento_humano_ligado(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->pedido();
        $chamado = Chamado::create(['numero' => Chamado::gerarNumero(), 'user_id' => $order->user_id, 'order_id' => $order->id, 'status' => Chamado::ABERTO, 'aberto_por' => 'manuela']);
        $chamado->registrar(MensagemSuporte::CLIENTE, 'Preciso de ajuda');

        $this->actingAs($admin)->get('/admin/suporte')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Suporte/Index', false)->has('chamados', 1));

        $this->actingAs($admin)->postJson("/admin/suporte/{$chamado->id}/mensagens", ['mensagem' => 'Olá!'])->assertStatus(422);

        $this->actingAs($admin)->postJson("/admin/suporte/{$chamado->id}/humano")->assertJsonPath('chamado.humano_ativo', true);
        $this->actingAs($admin)->postJson("/admin/suporte/{$chamado->id}/mensagens", ['mensagem' => 'Olá!'])
            ->assertOk()
            ->assertJsonPath('chamado.status', Chamado::EM_ATENDIMENTO);

        // Cliente escreve com humano ligado: a Manuela não entra.
        $this->actingAs($order->user)->postJson("/pedidos/{$order->id}/suporte", ['mensagem' => 'Obrigada'])->assertOk();
        Queue::assertNotPushed(ResponderComManuela::class);

        // Desligou o humano e a última é do cliente: a Manuela volta.
        $this->actingAs($admin)->postJson("/admin/suporte/{$chamado->id}/humano")->assertJsonPath('chamado.humano_ativo', false);
        Queue::assertPushed(ResponderComManuela::class);

        $this->actingAs($admin)->patchJson("/admin/suporte/{$chamado->id}", ['status' => Chamado::RESOLVIDO, 'prioridade' => 'urgente'])
            ->assertJsonPath('chamado.status', Chamado::RESOLVIDO);
        $this->assertNotNull($chamado->fresh()->fechado_em);

        $this->actingAs($admin)->deleteJson("/admin/suporte/{$chamado->id}")->assertOk();
        $this->assertDatabaseCount('suporte_chamados', 0);
        $this->assertDatabaseCount('suporte_mensagens', 0);
    }

    public function test_admin_cria_chamado_pelo_email_e_numero_do_pedido(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->pedido();

        $this->actingAs($admin)->post('/admin/suporte', [
            'email' => $order->user->email, 'pedido' => $order->numero, 'assunto' => 'Troca', 'mensagem' => 'Oi, vamos resolver.', 'prioridade' => 'normal',
        ])->assertRedirect();

        $chamado = Chamado::first();
        $this->assertSame($order->id, $chamado->order_id);
        $this->assertTrue($chamado->humano_ativo);
    }

    public function test_cliente_nao_acessa_admin_suporte(): void
    {
        $order = $this->pedido();
        $this->actingAs($order->user)->get('/admin/suporte')->assertForbidden();
    }
}
