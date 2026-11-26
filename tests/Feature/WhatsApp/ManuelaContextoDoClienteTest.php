<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Jobs\ReplyWithManuela;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\GeminiClient;
use App\Modules\WhatsApp\Services\ManuelaAgentClient;
use App\Modules\WhatsApp\Services\ManuelaAutoReplyService;
use App\Modules\WhatsApp\Services\WhatsAppMediaDownloader;
use App\Modules\WhatsApp\Services\WhatsAppOutbox;
use App\Modules\WhatsApp\Support\ContextoDoCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Caso real de 26/11/2026 (conversa 70, pedido 4562): lembrete de carrinho do
 * Microfone de Lapela às 10:20, pedido pago às 12:30, foto do comprovante e
 * "Feito" às 12:31. A Manuela pediu o nome do produto e listou 3 microfones.
 * Agora ela usa o contexto: agradece e confirma o pedido, sem pergunta.
 */
class ManuelaContextoDoClienteTest extends TestCase
{
    use RefreshDatabase;

    private const WA = '5511991234567';

    private const PRODUTO = 'Áudio Limpo nas Gravações: Microfone de Lapela Sem Fio';

    private function conversaComLembrete(?int $userId = 30): WhatsAppConversation
    {
        $carrinho = DB::table('adm_abandoned_carts')->insertGetId([
            'site' => 'kazakora', 'origem' => 'lead_checkout', 'origem_id' => 5,
            'user_id' => $userId, 'nome' => 'Cliente Teste da Silva', 'email' => 'cliente.teste@example.com',
            'telefone' => self::WA, 'produto_id' => 189, 'disparos_enviados' => 1,
            'itens' => json_encode([['product_id' => 189, 'nome' => self::PRODUTO, 'quantidade' => 1, 'subtotal' => 99.9]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $conversa = WhatsAppConversation::create(['wa_id' => self::WA, 'phone' => self::WA, 'ai_enabled' => true, 'loja' => 'kazakora']);
        $conversa->messages()->create([
            'direction' => 'outbound', 'type' => 'template', 'status' => 'sent', 'sent_by' => 'system',
            'body' => 'Carrinho abandonado (lembrete): '.self::PRODUTO.' https://kazakora.com/produtos/microfone-de-lapela?utm_source=whatsapp',
            'payload' => ['template' => 'carrinho_lembrete_kazakora', 'variaveis' => ['1' => 'Cliente', '2' => self::PRODUTO], 'carrinho_sequencia_id' => $carrinho, 'disparo' => 1],
            'created_at' => now()->subHours(2),
        ]);

        return $conversa;
    }

    private function usuario(): User
    {
        return User::factory()->create(['id' => 30, 'phone' => '(11) 99123-4567', 'email' => 'cliente.teste@example.com']);
    }

    private function pedido(array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Cliente Teste', 'shipping_phone' => '(11) 99123-4567', 'shipping_zip' => '01001000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 99.9, 'shipping_cost' => 0, 'total' => 99.9,
        ], $extra));
        $order->items()->create([
            'product_id' => null,
            'product_name' => 'Kit Microfone de Lapela Sem Fio para Gravação de Vídeos e Transmissões',
            'product_price' => 99.9, 'quantity' => 1, 'subtotal' => 99.9,
        ]);

        return $order->fresh();
    }

    /** Roda o job de cada mensagem; a IA (buildReply) não pode ser chamada quando $iaChamada = 0. */
    private function rodar(WhatsAppConversation $conversa, array $mensagens, WhatsAppOutbox $outbox, int $iaChamada = 0): void
    {
        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(false);
        $manuela = Mockery::mock(ManuelaAutoReplyService::class);
        $manuela->shouldReceive('buildReply')->times($iaChamada)->andReturn(['reply' => 'Oi!', 'source' => 'gemini']);
        $agent = Mockery::mock(ManuelaAgentClient::class);
        $agent->shouldReceive('isConfigured')->andReturn(true);

        foreach ($mensagens as $mensagem) {
            (new ReplyWithManuela($conversa->id, $mensagem->id))->handle($manuela, $outbox, $agent, $gemini, Mockery::mock(WhatsAppMediaDownloader::class));
        }
    }

    public function test_comprovante_e_feito_depois_do_pedido_pago_confirmam_o_pedido_sem_pergunta(): void
    {
        $this->usuario();
        $conversa = $this->conversaComLembrete();
        $order = $this->pedido(['user_id' => 30]);

        $foto = $conversa->messages()->create(['direction' => 'inbound', 'type' => 'image', 'status' => 'received', 'payload' => ['image' => ['id' => 'm1']]]);
        $feito = $conversa->messages()->create(['direction' => 'inbound', 'type' => 'text', 'body' => 'Feito', 'status' => 'received']);

        $enviado = null;
        $outbox = Mockery::mock(WhatsAppOutbox::class);
        $outbox->shouldReceive('sendText')->once()->withArgs(function ($c, $body, $quem, $user, $payload) use (&$enviado, $order) {
            $enviado = $body;

            return $quem === 'manuela' && ($payload['manuela_pedido_confirmado'] ?? null) === $order->id;
        })->andReturn(new WhatsAppMessage);

        $this->rodar($conversa, [$foto, $feito], $outbox);

        $this->assertStringContainsString($order->numero, $enviado);
        $this->assertStringContainsString('Kit Microfone de Lapela Sem Fio', $enviado);
        $this->assertStringContainsString('pago', $enviado);
        $this->assertStringContainsString('rastreio', $enviado);
        $this->assertStringStartsWith('Cliente,', $enviado);
        $this->assertStringNotContainsString('?', $enviado);
    }

    public function test_pedido_com_telefone_defeituoso_tambem_e_achado(): void
    {
        // "(55) 11991-2345": o 55 virou DDD e o número foi cortado; sem usuário nem e-mail.
        $conversa = $this->conversaComLembrete(null);
        DB::table('adm_abandoned_carts')->update(['email' => null]);
        $order = $this->pedido(['shipping_phone' => '(55) 11991-2345', 'shipping_whatsapp' => null, 'shipping_email' => null]);

        $contexto = app(ContextoDoCliente::class)->montar($conversa);

        $this->assertSame($order->id, $contexto['pedidos'][0]['id'] ?? null);
        $this->assertSame($order->id, ContextoDoCliente::pedidoRecente($contexto)['id'] ?? null);
    }

    public function test_variantes_do_numero(): void
    {
        $v = ContextoDoCliente::variantes(self::WA);

        foreach (['11991234567', '5511991234567', '1191234567', '551191234567', '55119912345'] as $forma) {
            $this->assertContains($forma, $v);
        }
    }

    public function test_segunda_foto_nao_repete_a_confirmacao(): void
    {
        $this->usuario();
        $conversa = $this->conversaComLembrete();
        $order = $this->pedido(['user_id' => 30]);
        $conversa->messages()->create(['direction' => 'inbound', 'type' => 'text', 'body' => 'Feito', 'status' => 'received']);
        $conversa->messages()->create([
            'direction' => 'outbound', 'type' => 'text', 'status' => 'sent', 'sent_by' => 'manuela',
            'body' => 'Cliente, recebi aqui, muito obrigada!', 'payload' => ['manuela_pedido_confirmado' => $order->id],
        ]);
        $foto = $conversa->messages()->create(['direction' => 'inbound', 'type' => 'image', 'status' => 'received', 'payload' => ['image' => ['id' => 'm2']]]);

        // Não manda a resposta pronta de novo: segue para a Manuela, com o pedido no contexto.
        $outbox = Mockery::mock(WhatsAppOutbox::class);
        $outbox->shouldReceive('sendText')->once()->withArgs(fn ($c, $body, $quem, $user, $payload) => ! isset($payload['manuela_pedido_confirmado']))->andReturn(new WhatsAppMessage);

        $this->rodar($conversa, [$foto], $outbox, 1);
    }

    public function test_feito_sem_pedido_recente_vai_para_a_manuela(): void
    {
        $conversa = $this->conversaComLembrete(null);
        $feito = $conversa->messages()->create(['direction' => 'inbound', 'type' => 'text', 'body' => 'Feito', 'status' => 'received']);

        $outbox = Mockery::mock(WhatsAppOutbox::class);
        $outbox->shouldReceive('sendText')->once()->andReturn(new WhatsAppMessage);

        $this->rodar($conversa, [$feito], $outbox, 1);
    }

    public function test_resposta_automatica_do_cliente_depois_do_lembrete_nao_e_respondida(): void
    {
        $conversa = $this->conversaComLembrete(null);
        $auto = $conversa->messages()->create(['direction' => 'inbound', 'type' => 'text', 'status' => 'received',
            'body' => 'Olá! Obrigado por entrar em contato. No momento não estamos disponíveis, responderemos em breve.']);

        $outbox = Mockery::mock(WhatsAppOutbox::class);
        $outbox->shouldNotReceive('sendText');

        $this->rodar($conversa, [$auto], $outbox);
    }

    public function test_prompt_traz_o_produto_do_lembrete_e_proibe_perguntar_qual_e(): void
    {
        $conversa = $this->conversaComLembrete(null);
        $conversa->messages()->create(['direction' => 'inbound', 'type' => 'text', 'body' => 'Quanto tempo dura a bateria?', 'status' => 'received']);

        $prompt = app(ManuelaAutoReplyService::class)->systemPrompt($conversa);

        $this->assertStringContainsString(self::PRODUTO, $prompt);
        $this->assertStringContainsString('id 189', $prompt);
        $this->assertStringContainsString('NÃO pergunte qual é o produto', $prompt);
        $this->assertStringContainsString('No máximo 1 pergunta', $prompt);
    }

    public function test_prompt_traz_o_pedido_pago(): void
    {
        $this->usuario();
        $conversa = $this->conversaComLembrete();
        $order = $this->pedido(['user_id' => 30]);

        $prompt = app(ManuelaAutoReplyService::class)->systemPrompt($conversa);

        $this->assertStringContainsString('Pedido '.$order->numero, $prompt);
        $this->assertStringContainsString('pago, aguardando envio', $prompt);
    }

    public function test_no_maximo_uma_pergunta(): void
    {
        $this->assertSame(
            'É o Áudio Limpo nas Gravações: Microfone de Lapela Sem Fio?',
            ContextoDoCliente::umaPerguntaSo('É o Áudio Limpo nas Gravações: Microfone de Lapela Sem Fio? Quer mais informações sobre ele?'),
        );
        $this->assertSame('Que bom! Posso ajudar em algo mais?', ContextoDoCliente::umaPerguntaSo('Que bom! Posso ajudar em algo mais?'));
    }

    public function test_aviso_de_pagamento(): void
    {
        foreach (['Feito', 'feito!', 'Paguei', 'já paguei', 'Fiz o pix', 'segue o comprovante', 'Pronto', 'tá pago'] as $t) {
            $this->assertTrue(ContextoDoCliente::avisoDePagamento($t), $t);
        }
        foreach (['Quanto custa o frete?', 'feito de quê?', 'ainda não paguei porque o pix deu erro'] as $t) {
            $this->assertFalse(ContextoDoCliente::avisoDePagamento($t), $t);
        }
    }
}
