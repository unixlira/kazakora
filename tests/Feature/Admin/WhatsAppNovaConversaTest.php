<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nova conversa pelo "+" da tela de Conversas (pedido do Lira 2026-10-23):
 * busca por telefone ou número do pedido, mostra o cliente e a loja dele
 * (kazakora.com ou ferrakora.devlira.com.br) e abre a conversa.
 */
class WhatsAppNovaConversaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ferrakora.url' => 'https://ferrakora.devlira.com.br']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function pedido(array $dados = []): Order
    {
        return Order::create($dados + [
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Ademir Oliveira', 'shipping_phone' => '(11) 98296-0015', 'shipping_zip' => '13203320',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'Jundiaí', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ]);
    }

    public function test_busca_pelo_numero_do_pedido_mostra_cliente_e_loja_ferrakora(): void
    {
        $pedido = $this->pedido(['site_origem' => Order::SITE_FERRAKORA, 'shipping_name' => 'Joana Ferreira', 'shipping_phone' => '(41) 99898-2973']);

        $this->actingAs($this->admin())
            ->getJson('/admin/whatsapp/conversas/nova/buscar?q=%23'.$pedido->id)
            ->assertOk()
            ->assertJsonCount(1, 'resultados')
            ->assertJsonPath('resultados.0.telefone', '5541998982973')
            ->assertJsonPath('resultados.0.pedidoId', $pedido->id)
            ->assertJsonPath('resultados.0.cliente.nome', 'Joana Ferreira')
            ->assertJsonPath('resultados.0.cliente.loja.chave', 'ferrakora')
            ->assertJsonPath('resultados.0.cliente.loja.site', 'ferrakora.devlira.com.br')
            ->assertJsonPath('resultados.0.cliente.pedidos.0.id', $pedido->id)
            ->assertJsonPath('resultados.0.conversaId', null);
    }

    public function test_busca_pelo_telefone_acha_cliente_kazakora_e_conversa_sem_o_nono_digito(): void
    {
        $pedido = $this->pedido();
        $conversa = WhatsAppConversation::create([
            'wa_id' => '551182960015', 'phone' => '551182960015', 'profile_name' => 'Ade',
            'last_message_at' => now(), 'last_message_preview' => 'Oi', 'unread_count' => 0,
        ]);

        $this->actingAs($this->admin())
            ->getJson('/admin/whatsapp/conversas/nova/buscar?q='.urlencode('(11) 98296-0015'))
            ->assertOk()
            ->assertJsonCount(1, 'resultados')
            ->assertJsonPath('resultados.0.telefone', '5511982960015')
            ->assertJsonPath('resultados.0.cliente.loja.chave', 'kazakora')
            ->assertJsonPath('resultados.0.cliente.loja.site', 'kazakora.com')
            ->assertJsonPath('resultados.0.cliente.pedidos.0.id', $pedido->id)
            ->assertJsonPath('resultados.0.conversaId', $conversa->id);
    }

    public function test_telefone_sem_cadastro_ainda_pode_iniciar(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/admin/whatsapp/conversas/nova/buscar?q=21912345678')
            ->assertOk()
            ->assertJsonCount(1, 'resultados')
            ->assertJsonPath('resultados.0.telefone', '5521912345678')
            ->assertJsonPath('resultados.0.cliente', null)
            ->assertJsonPath('resultados.0.numeroLoja', 'kazakora');
    }

    public function test_iniciar_cria_conversa_com_humanos_e_guarda_venda_e_loja(): void
    {
        $pedido = $this->pedido(['site_origem' => Order::SITE_FERRAKORA]);
        $this->pedido(); // venda mais nova do mesmo telefone, no kazakora.com

        $resposta = $this->actingAs($this->admin())
            ->postJson('/admin/whatsapp/conversas/nova', ['telefone' => '11 98296-0015', 'pedido_id' => $pedido->id])
            ->assertOk()
            ->assertJsonPath('conversation.phone', '5511982960015')
            ->assertJsonPath('conversation.aiEnabled', false)
            ->assertJsonPath('conversation.pedidoId', $pedido->id)
            ->assertJsonPath('conversation.clienteLoja.chave', 'ferrakora')
            ->assertJsonPath('conversation.cliente.loja.site', 'ferrakora.devlira.com.br');

        $conversa = WhatsAppConversation::findOrFail($resposta->json('conversation.id'));

        // Ao reabrir, a venda escolhida continua na frente.
        $this->actingAs($this->admin())
            ->getJson("/admin/whatsapp/conversas/{$conversa->id}/mensagens")
            ->assertOk()
            ->assertJsonPath('conversation.pedidoId', $pedido->id)
            ->assertJsonPath('conversation.clienteLoja.nome', 'FerraKora');
    }

    public function test_iniciar_reaproveita_conversa_existente(): void
    {
        $conversa = WhatsAppConversation::create([
            'wa_id' => '551182960015', 'phone' => '551182960015', 'profile_name' => 'Ade',
            'last_message_at' => now(), 'last_message_preview' => 'Oi', 'unread_count' => 0,
        ]);

        $this->actingAs($this->admin())
            ->postJson('/admin/whatsapp/conversas/nova', ['telefone' => '5511982960015'])
            ->assertOk()
            ->assertJsonPath('conversation.id', $conversa->id);

        $this->assertSame(1, WhatsAppConversation::count());
    }

    public function test_iniciar_recusa_telefone_invalido(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/whatsapp/conversas/nova', ['telefone' => '1234'])
            ->assertStatus(422);
    }
}
