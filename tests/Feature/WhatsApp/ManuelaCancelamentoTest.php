<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Jobs\ReplyWithManuela;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Services\GeminiClient;
use App\Modules\WhatsApp\Services\ManuelaAgentClient;
use App\Modules\WhatsApp\Services\ManuelaAutoReplyService;
use App\Modules\WhatsApp\Services\WhatsAppMediaDownloader;
use App\Modules\WhatsApp\Services\WhatsAppOutbox;
use App\Modules\WhatsApp\Support\ManuelaCancelamento;
use App\Notifications\ManuelaFichaAtendimentoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

/**
 * Pedido do Lira 2026-11-14: a Manuela não cancela pedido, mas conversa,
 * entende o motivo e deixa a ficha pronta para o atendente. Achado do teste
 * dele: ela pediu o número de um pedido que a conversa já tinha e parou.
 */
class ManuelaCancelamentoTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(array $dados = []): Order
    {
        $order = Order::create($dados + [
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_PAID,
            'external_order_id' => '2611140H03TANE',
            'shipping_name' => 'Caleb Ryan da Luz', 'shipping_phone' => '(11) 99025-3050', 'shipping_zip' => '77553970',
            'shipping_street' => 'Avenida Santo Antônio', 'shipping_number' => '70', 'shipping_neighborhood' => 'Setor Central',
            'shipping_city' => 'Ipueiras', 'shipping_state' => 'TO',
            'subtotal' => 136.56, 'shipping_cost' => 0, 'total' => 6.83,
        ]);
        $order->items()->create(['product_name' => 'Parafusadeira Furadeira 48V', 'product_price' => 136.56, 'quantity' => 1, 'subtotal' => 136.56]);

        return $order;
    }

    public function test_prompt_das_duas_lojas_traz_os_pedidos_do_cliente_e_a_regra_da_ficha(): void
    {
        $this->pedido();

        foreach (['kazakora', 'ferrakora'] as $loja) {
            $conversa = WhatsAppConversation::create(['wa_id' => '551190253050', 'phone' => '551190253050', 'loja' => $loja, 'ai_enabled' => true]);
            $prompt = app(ManuelaAutoReplyService::class)->systemPrompt($conversa);

            $this->assertStringContainsString('Pedidos deste cliente', $prompt);
            $this->assertStringContainsString('Pedido 2611140H03TANE', $prompt);
            $this->assertStringContainsString('pago, ainda não enviado', $prompt);
            $this->assertStringContainsString('Parafusadeira Furadeira 48V', $prompt);
            // Sem valor em dinheiro no prompt: a Manuela não fala preço.
            $this->assertStringNotContainsString('6,83', $prompt);
            $this->assertStringNotContainsString('136', $prompt);
            $this->assertStringContainsString(ManuelaCancelamento::FICHA_TAG, $prompt);
            $this->assertStringContainsString('Você não cancela', $prompt);
            $this->assertStringContainsString('não pergunte', $prompt);
            $conversa->delete();
        }
    }

    public function test_sem_pedido_o_prompt_diz_que_nao_achou_e_ela_pode_pedir_o_numero(): void
    {
        $prompt = app(ManuelaAutoReplyService::class)->systemPrompt(WhatsAppConversation::make(['loja' => 'ferrakora']));

        $this->assertStringContainsString('Pedidos deste cliente: nenhum encontrado', $prompt);
        $this->assertStringContainsString('Só peça o número se a lista estiver vazia', $prompt);
    }

    public function test_ficha_sai_do_texto_do_cliente(): void
    {
        [$texto, $ficha] = ManuelaCancelamento::separar("[HUMANO] Entendi, Caleb. Pode me contar o motivo?\n\n[FICHA] Pedido: 2611140H03TANE; Situação: pago, ainda não enviado;\nMotivo: não informado");

        $this->assertSame('[HUMANO] Entendi, Caleb. Pode me contar o motivo?', $texto);
        $this->assertSame('Pedido: 2611140H03TANE; Situação: pago, ainda não enviado; Motivo: não informado', $ficha);
        $this->assertSame(['Oi, tudo certo.', ''], ManuelaCancelamento::separar('Oi, tudo certo.'));
    }

    public function test_ficha_fica_na_conversa_chama_o_time_uma_vez_e_o_cliente_so_recebe_o_texto(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $conversa = WhatsAppConversation::create(['wa_id' => '551190253050', 'phone' => '551190253050', 'loja' => 'ferrakora', 'ai_enabled' => true]);

        $respostas = [
            ['reply' => 'Entendi. É o pedido 2611140H03TANE, da parafusadeira? Pode me contar o motivo?', 'ficha' => 'Pedido: 2611140H03TANE; Motivo: não informado'],
            ['reply' => 'Anotei tudo. Uma pessoa do time vai concluir com você.', 'ficha' => 'Pedido: 2611140H03TANE; Motivo: comprou errado; Cliente quer: dinheiro de volta'],
        ];

        foreach ($respostas as $i => $resposta) {
            $inbound = $conversa->messages()->create(['direction' => 'inbound', 'type' => 'text', 'body' => $i === 0 ? 'Me arrependi da compra, quero cancelar' : 'Comprei errado', 'status' => 'received']);

            $gemini = Mockery::mock(GeminiClient::class);
            $gemini->shouldReceive('isConfigured')->andReturn(true);
            $agent = Mockery::mock(ManuelaAgentClient::class);
            $agent->shouldReceive('isConfigured')->andReturn(true);
            $manuela = Mockery::mock(ManuelaAutoReplyService::class);
            $manuela->shouldReceive('buildReply')->andReturn($resposta + ['needs_human' => true, 'alerta_atendimento' => false, 'rascunho' => '', 'source' => 'gemini']);
            $outbox = Mockery::mock(WhatsAppOutbox::class);
            $outbox->shouldReceive('sendText')->once()->withArgs(fn ($c, $texto) => $texto === $resposta['reply'] && ! str_contains($texto, 'FICHA'));

            (new ReplyWithManuela($conversa->id, $inbound->id))->handle($manuela, $outbox, $agent, $gemini, Mockery::mock(WhatsAppMediaDownloader::class));
        }

        $conversa->refresh();
        $this->assertTrue($conversa->needs_human);
        $this->assertSame('Pedido: 2611140H03TANE; Motivo: comprou errado; Cliente quer: dinheiro de volta', $conversa->metadata[ManuelaCancelamento::FICHA_KEY]['texto']);
        Notification::assertSentToTimes($admin, ManuelaFichaAtendimentoNotification::class, 1);
    }
}
