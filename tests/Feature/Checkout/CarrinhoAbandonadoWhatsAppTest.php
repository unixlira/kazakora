<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Cart\Mail\SequenciaCarrinho;
use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\LeadCheckout;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Botões em Admin > Carrinhos abandonados (pedido do Lira 2026-11-23): antecipam
 * o disparo da sequência automática, que manda WhatsApp e e-mail e registra
 * tudo (histórico do carrinho e tela de Conversas). Cada carrinho recebe uma vez.
 */
class CarrinhoAbandonadoWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18 11:00', 'America/Sao_Paulo'));
        config([
            'loja.carrinho_sequencia_ativa' => true,
            'services.gemini.api_key' => null,
            'services.whatsapp.access_token' => 'token-teste',
            'services.whatsapp.phone_number_id' => '123456',
            'services.whatsapp.templates.carrinho_lembrete' => 'carrinho_lembrete_kazakora',
            'services.whatsapp.templates.carrinho_novidade' => 'carrinho_novidade_kazakora',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.teste']]])]);
        Mail::fake();
    }

    private function lead(array $extra = []): LeadCheckout
    {
        $produto = Product::factory()->create(['price' => 100, 'stock' => 10, 'is_active' => true]);
        $lead = LeadCheckout::create([
            'site' => 'kazakora', 'nome' => 'MARIA DA SILVA', 'email' => 'maria'.uniqid().'@exemplo.com', 'telefone' => '11999990000',
            'itens' => [['product_id' => $produto->id, 'nome' => $produto->name, 'quantidade' => 1, 'preco_unitario' => 100, 'subtotal' => 100]],
            'subtotal' => 100, 'status' => LeadCheckout::STATUS_ABERTO, 'etapa' => LeadCheckout::ETAPA_ENTREGA,
            ...$extra,
        ]);
        $lead->forceFill(['updated_at' => now()->subMinutes(90), 'created_at' => now()->subMinutes(90)])->saveQuietly();

        return $lead;
    }

    public function test_botao_manda_whatsapp_e_email_e_registra_tudo(): void
    {
        $lead = $this->lead();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post("/admin/carrinhos-abandonados/{$lead->id}/whatsapp")->assertSessionHas('success');

        Http::assertSent(fn (HttpRequest $r) => ($r['template']['name'] ?? null) === 'carrinho_lembrete_kazakora');
        Mail::assertSent(SequenciaCarrinho::class, 1);
        $this->assertNotNull($lead->fresh()->whatsapp_enviado_em);
        $registro = Registro::query()->where('origem', Registro::ORIGEM_LEAD)->where('origem_id', $lead->id)->firstOrFail();
        $this->assertSame(1, $registro->disparos_enviados);
        // Aparece na lista de Conversas do painel (precisa da prévia).
        $this->assertNotNull(WhatsAppConversation::query()->firstOrFail()->last_message_preview);

        // Segunda vez: não envia de novo.
        $this->actingAs($admin)->post("/admin/carrinhos-abandonados/{$lead->id}/whatsapp")->assertSessionHas('error');
        Http::assertSentCount(1);
    }

    public function test_disparar_para_todos_so_manda_para_abertos_que_nao_receberam(): void
    {
        $this->lead();
        $this->lead(['status' => LeadCheckout::STATUS_CONVERTIDO]);
        $this->lead(['whatsapp_enviado_em' => now()]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/carrinhos-abandonados')
            ->assertInertia(fn ($page) => $page->where('resumo.para_disparar', 1));

        $this->actingAs($admin)->post('/admin/carrinhos-abandonados/whatsapp-todos')->assertSessionHas('success');

        Http::assertSentCount(1);
        Mail::assertSent(SequenciaCarrinho::class, 1);
    }

    public function test_quem_nao_esta_logado_nao_dispara(): void
    {
        $lead = $this->lead();

        $this->post("/admin/carrinhos-abandonados/{$lead->id}/whatsapp")->assertRedirect();
        Http::assertNothingSent();
    }
}
