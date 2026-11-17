<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Checkout\Jobs\ProcessarCancelamentoDoPedidoJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Checkout\Support\PostagemDoPedido;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\MelhorEnvioEnvio;
use App\Modules\Suporte\Models\Chamado;
use App\Modules\Suporte\Models\MensagemSuporte;
use App\Modules\Suporte\Models\SolicitacaoCancelamento;
use App\Modules\Suporte\Services\ManuelaSuporte;
use App\Modules\Suporte\Services\SolicitacoesDeCancelamento;
use App\Modules\WhatsApp\Services\GeminiClient;
use App\Notifications\CancelamentoSolicitadoAposPostagemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

/**
 * Cancelamento depois da postagem (Lira, 2026-11-17): com o pacote já
 * postado, o botão "Cancelar pedido" de Meus pedidos registra uma
 * solicitação (chamado + Atendimento > Cancelamentos) em vez de cancelar.
 */
class CancelamentoAposPostagemTest extends TestCase
{
    use RefreshDatabase;

    private function compra(array $extra = []): Order
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $order = Order::create(array_merge([
            'user_id' => $cliente->id,
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ], $extra));
        $order->items()->create(['product_name' => 'Organizador', 'product_price' => 50, 'quantity' => 2, 'subtotal' => 100, 'item_type' => OrderItem::TYPE_PRODUCT]);

        return $order;
    }

    private function preCorreios(Order $order, array $extra = []): CorreiosPrePostagem
    {
        return CorreiosPrePostagem::create(array_merge([
            'order_id' => $order->id,
            'customer_name' => 'Maria',
            'origin' => Order::ORIGIN_STORE,
            'zip' => '03187040', 'street' => 'Rua X', 'number' => '1', 'neighborhood' => 'Mooca', 'city' => 'São Paulo', 'state' => 'SP',
            'service_code' => '03298', 'service_label' => 'PAC (contrato)', 'weight_grams' => 300,
            'content_items' => [['conteudo' => 'Organizador', 'quantidade' => 1, 'valor' => 50]],
            'status' => CorreiosPrePostagem::STATUS_GERADA,
            'codigo_objeto' => 'AP123456789BR',
        ], $extra));
    }

    public function test_postado_no_melhor_envio_vira_solicitacao_e_nao_cancela(): void
    {
        Queue::fake();
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->compra();
        MelhorEnvioEnvio::create(['order_id' => $order->id, 'status' => MelhorEnvioEnvio::STATUS_GERADA, 'rastreio' => 'ME123BR', 'postada_em' => now()->subDay()]);

        $this->actingAs($order->user)
            ->get("/pedidos/{$order->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pedido.cancelamento.pode', true)
                ->where('pedido.cancelamento.postado', true)
                ->where('pedido.cancelamento.solicitacao', null)
                ->etc());

        $this->actingAs($order->user)
            ->post("/pedidos/{$order->id}/cancelar", ['motivo' => 'Comprei errado'])
            ->assertRedirect()
            ->assertSessionHas('success', SolicitacoesDeCancelamento::MENSAGEM_AO_CLIENTE);

        $order->refresh();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNull($order->cancelado_em);
        Queue::assertNotPushed(ProcessarCancelamentoDoPedidoJob::class);

        $solicitacao = SolicitacaoCancelamento::sole();
        $this->assertSame(SolicitacaoCancelamento::PENDENTE, $solicitacao->status);
        $this->assertSame('Comprei errado', $solicitacao->motivo);
        $this->assertSame('ME123BR', $solicitacao->rastreio);
        $this->assertSame('Melhor Envio', $solicitacao->postagem_fonte);
        $this->assertNotNull($solicitacao->postado_em);

        $chamado = $solicitacao->chamado;
        $this->assertSame($order->id, $chamado->order_id);
        $this->assertSame(Chamado::ABERTO, $chamado->status);
        $this->assertTrue($chamado->humano_ativo);
        $this->assertStringContainsString('Comprei errado', (string) $chamado->mensagens()->where('autor', MensagemSuporte::CLIENTE)->value('conteudo'));
        $this->assertSame(SolicitacoesDeCancelamento::MENSAGEM_AO_CLIENTE, $chamado->mensagens()->where('autor', MensagemSuporte::SISTEMA)->value('conteudo'));

        Notification::assertSentTo($admin, CancelamentoSolicitadoAposPostagemNotification::class,
            fn ($aviso) => str_contains($aviso->toArray($admin)['body'], 'NÃO foi cancelado'));

        $this->actingAs($order->user)
            ->get("/pedidos/{$order->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pedido.cancelamento.solicitacao.status', SolicitacaoCancelamento::PENDENTE)
                ->etc());
    }

    public function test_clique_repetido_nao_duplica_a_solicitacao(): void
    {
        Queue::fake();
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->compra();
        ChannelShipment::create(['order_id' => $order->id, 'channel' => Order::ORIGIN_STORE, 'tracking_code' => 'BR999', 'channel_shipped_at' => now()]);

        $this->actingAs($order->user)->post("/pedidos/{$order->id}/cancelar", ['motivo' => 'Desisti da compra'])
            ->assertSessionHas('success', SolicitacoesDeCancelamento::MENSAGEM_AO_CLIENTE);
        $this->actingAs($order->user)->post("/pedidos/{$order->id}/cancelar", ['motivo' => 'Desisti da compra'])
            ->assertSessionHas('success', SolicitacoesDeCancelamento::MENSAGEM_REPETIDA);

        $this->assertDatabaseCount('cancelamento_solicitacoes', 1);
        $this->assertDatabaseCount('suporte_chamados', 1);
        $this->assertSame(1, MensagemSuporte::query()->where('autor', MensagemSuporte::CLIENTE)->count());
        $this->assertSame('BR999', SolicitacaoCancelamento::sole()->rastreio);
        Notification::assertSentToTimes($admin, CancelamentoSolicitadoAposPostagemNotification::class, 1);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_usa_a_conversa_ja_aberta_do_pedido(): void
    {
        Notification::fake();
        $order = $this->compra(['status' => Order::STATUS_SHIPPED]);
        $conversa = Chamado::create(['numero' => Chamado::gerarNumero(), 'user_id' => $order->user_id, 'order_id' => $order->id, 'status' => Chamado::CONVERSA, 'aberto_por' => 'cliente']);

        $this->actingAs($order->user)->post("/pedidos/{$order->id}/cancelar", ['motivo' => 'Outro motivo'])->assertSessionHas('success');

        $this->assertDatabaseCount('suporte_chamados', 1);
        $this->assertSame($conversa->id, SolicitacaoCancelamento::sole()->chamado_id);
        $this->assertSame(Chamado::ABERTO, $conversa->fresh()->status);
    }

    public function test_quando_conta_como_postado(): void
    {
        $postagem = app(PostagemDoPedido::class);

        $semNada = $this->compra();
        $this->assertNull($postagem->encontrar($semNada));

        // Só gerada (pré-postada) ou expirada nos Correios: não passou pela agência.
        $preGerada = $this->compra();
        $this->preCorreios($preGerada);
        $this->assertNull($postagem->encontrar($preGerada));

        $expirada = $this->compra();
        $this->preCorreios($expirada, ['rastreio_situacao' => CorreiosPrePostagem::SITUACAO_EXPIRADA, 'expirada_em' => now()]);
        $this->assertNull($postagem->encontrar($expirada));

        $postadaCorreios = $this->compra();
        $this->preCorreios($postadaCorreios, ['rastreio_situacao' => CorreiosPrePostagem::SITUACAO_POSTADA, 'postada_em' => now()->subHours(3)]);
        $achou = $postagem->encontrar($postadaCorreios);
        $this->assertSame('Correios', $achou['fonte']);
        $this->assertSame('AP123456789BR', $achou['rastreio']);

        // Etiqueta do Melhor Envio só comprada: ainda não.
        $meGerada = $this->compra();
        MelhorEnvioEnvio::create(['order_id' => $meGerada->id, 'status' => MelhorEnvioEnvio::STATUS_GERADA]);
        $this->assertNull($postagem->encontrar($meGerada));

        $meRecebida = $this->compra();
        MelhorEnvioEnvio::create(['order_id' => $meRecebida->id, 'status' => MelhorEnvioEnvio::STATUS_GERADA, 'situacao' => 'posted']);
        $this->assertSame('Melhor Envio', $postagem->encontrar($meRecebida)['fonte']);

        $canal = $this->compra();
        ChannelShipment::create(['order_id' => $canal->id, 'channel' => Order::ORIGIN_STORE, 'channel_shipped_at' => now()]);
        $this->assertSame('Envio do canal', $postagem->encontrar($canal)['fonte']);

        $this->assertSame('Status do pedido', $postagem->encontrar($this->compra(['status' => Order::STATUS_COMPLETED]))['fonte']);
    }

    public function test_pre_postagem_expirada_ainda_cancela_na_hora(): void
    {
        Queue::fake();
        $order = $this->compra();
        $this->preCorreios($order, ['rastreio_situacao' => CorreiosPrePostagem::SITUACAO_EXPIRADA, 'expirada_em' => now()]);

        $this->actingAs($order->user)->post("/pedidos/{$order->id}/cancelar", ['motivo' => 'Desisti da compra'])
            ->assertSessionHas('success', fn ($texto) => str_contains($texto, 'sendo processado'));

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertDatabaseCount('cancelamento_solicitacoes', 0);
        Queue::assertPushed(ProcessarCancelamentoDoPedidoJob::class, 1);
    }

    public function test_nao_postado_cancela_como_antes(): void
    {
        Queue::fake();
        $order = $this->compra();

        $this->actingAs($order->user)
            ->get("/pedidos/{$order->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pedido.cancelamento.pode', true)
                ->where('pedido.cancelamento.postado', false)
                ->etc());

        $this->actingAs($order->user)->post("/pedidos/{$order->id}/cancelar", ['motivo' => 'Desisti da compra'])
            ->assertSessionHas('success', fn ($texto) => str_contains($texto, 'sendo processado'));

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertDatabaseCount('cancelamento_solicitacoes', 0);
        Queue::assertPushed(ProcessarCancelamentoDoPedidoJob::class, 1);
    }

    public function test_admin_lista_e_marca_como_resolvida(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->compra(['status' => Order::STATUS_SHIPPED]);
        $this->actingAs($order->user)->post("/pedidos/{$order->id}/cancelar", ['motivo' => 'Comprei errado']);
        $solicitacao = SolicitacaoCancelamento::sole();

        $this->actingAs($admin)->get('/admin/cancelamentos')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Suporte/Cancelamentos', false)
                ->has('solicitacoes', 1)
                ->where('solicitacoes.0.pedido.id', $order->id)
                ->where('solicitacoes.0.motivo', 'Comprei errado')
                ->where('solicitacoes.0.chamado.id', $solicitacao->chamado_id)
                ->where('solicitacoes.0.status', SolicitacaoCancelamento::PENDENTE)
                ->etc());

        $this->actingAs($admin)->post("/admin/cancelamentos/{$solicitacao->id}/resolver")->assertRedirect();

        $solicitacao->refresh();
        $this->assertSame(SolicitacaoCancelamento::RESOLVIDO, $solicitacao->status);
        $this->assertSame($admin->id, (int) $solicitacao->resolvido_por);
        $this->assertNotNull($solicitacao->resolvido_em);
        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);

        $this->actingAs($admin)->get('/admin/cancelamentos')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('solicitacoes', 0)->etc());

        $this->actingAs($order->user)->get('/admin/cancelamentos')->assertForbidden();
    }

    public function test_manuela_nao_diz_que_cancela_na_hora_quando_ja_foi_postado(): void
    {
        $mock = Mockery::mock(GeminiClient::class);
        $mock->shouldReceive('isConfigured')->andReturn(false);
        $this->app->instance(GeminiClient::class, $mock);

        $order = $this->compra(['status' => Order::STATUS_SHIPPED]);
        $chamado = Chamado::create(['numero' => Chamado::gerarNumero(), 'user_id' => $order->user_id, 'order_id' => $order->id, 'status' => Chamado::CONVERSA, 'aberto_por' => 'cliente']);
        $chamado->registrar(MensagemSuporte::CLIENTE, 'Quero cancelar meu pedido');

        app(ManuelaSuporte::class)->responder($chamado);

        $resposta = (string) $chamado->mensagens()->where('autor', MensagemSuporte::MANUELA)->value('conteudo');
        $this->assertSame(ManuelaSuporte::CANCELAR_POSTADO, $resposta);
        $this->assertStringNotContainsString('na hora', $resposta);
    }
}
