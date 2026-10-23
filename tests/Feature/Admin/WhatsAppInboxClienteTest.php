<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Conversas do WhatsApp com o cliente e a venda (pedido do Lira 2026-10-23):
 * ao abrir a conversa, a tela diz quem é o cliente e de qual venda ele é.
 */
class WhatsAppInboxClienteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function pedido(array $dados = []): Order
    {
        return Order::create($dados + [
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_CANCELLED,
            'shipping_name' => 'Ademir oliveira', 'shipping_phone' => '(11) 98296-0015', 'shipping_zip' => '13203320',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'Jundiaí', 'shipping_state' => 'SP',
            'subtotal' => 576.86, 'shipping_cost' => 0, 'total' => 548.02,
        ]);
    }

    private function conversa(string $waId = '5511982960015', string $nome = 'Ade'): WhatsAppConversation
    {
        return WhatsAppConversation::create([
            'wa_id' => $waId, 'phone' => $waId, 'profile_name' => $nome,
            'last_message_at' => now(), 'last_message_preview' => 'Oi', 'unread_count' => 0,
        ]);
    }

    public function test_acha_a_venda_pelo_telefone_mesmo_sem_o_nono_digito(): void
    {
        $cliente = User::factory()->create(['name' => 'Ademir Oliveira', 'phone' => '(11) 98296-0015']);
        $pedido = $this->pedido(['user_id' => $cliente->id]);
        $this->pedido(['shipping_phone' => '(11) 97777-0015']); // mesmo final, outro número
        $conversa = $this->conversa('551182960015'); // a Meta manda sem o 9

        $resposta = $this->actingAs($this->admin())
            ->getJson("/admin/whatsapp/conversas/{$conversa->id}/mensagens")
            ->assertOk();

        $resposta->assertJsonPath('conversation.cliente.nome', 'Ademir Oliveira')
            ->assertJsonPath('conversation.cliente.userId', $cliente->id)
            ->assertJsonCount(1, 'conversation.cliente.pedidos')
            ->assertJsonPath('conversation.cliente.pedidos.0.id', $pedido->id)
            ->assertJsonPath('conversation.cliente.pedidos.0.status', Order::STATUS_CANCELLED)
            ->assertJsonPath('conversation.pedidoId', $pedido->id)
            ->assertJsonPath('conversation.clienteNome', 'Ademir Oliveira');

        // A lista passa a mostrar a venda sem abrir a conversa de novo.
        $this->assertSame($pedido->id, $conversa->fresh()->metadata['pedido_id']);
    }

    public function test_venda_citada_na_mensagem_vem_primeiro(): void
    {
        $antigo = $this->pedido(['status' => Order::STATUS_COMPLETED]);
        $citado = $this->pedido(['shipping_phone' => '(41) 99898-2973', 'shipping_name' => 'Vilson Bazestão']);
        $conversa = $this->conversa();
        WhatsAppMessage::create([
            'conversation_id' => $conversa->id, 'direction' => 'outbound', 'type' => 'template',
            'body' => 'Recuperação', 'status' => 'sent', 'sent_by' => 'manuela',
            'payload' => ['origem' => 'recuperacao-pix-'.$citado->id, 'order_id' => $citado->id], 'sent_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->getJson("/admin/whatsapp/conversas/{$conversa->id}/mensagens")
            ->assertOk()
            ->assertJsonPath('conversation.cliente.pedidos.0.id', $citado->id)
            ->assertJsonPath('conversation.cliente.pedidos.1.id', $antigo->id)
            ->assertJsonPath('conversation.pedidoId', $citado->id);
    }

    public function test_numero_sem_cadastro_nem_venda(): void
    {
        $conversa = $this->conversa('5521912345678', 'Desconhecido');

        $this->actingAs($this->admin())
            ->getJson("/admin/whatsapp/conversas/{$conversa->id}/mensagens")
            ->assertOk()
            ->assertJsonPath('conversation.cliente', null)
            ->assertJsonPath('conversation.pedidoId', null);
    }
}
