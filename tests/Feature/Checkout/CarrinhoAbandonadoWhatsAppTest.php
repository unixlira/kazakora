<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\LeadCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Botões de WhatsApp em Admin > Carrinhos abandonados (pedido do Lira 2026-11-23):
 * um carrinho por vez ou todos os abertos; cada carrinho recebe uma vez só.
 */
class CarrinhoAbandonadoWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.numeros.ferrakora.phone_number_id' => '456',
            'services.whatsapp.numeros.ferrakora.access_token' => 'ferra-token',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]])]);
    }

    private function lead(array $dados = []): LeadCheckout
    {
        $produto = Product::factory()->create(['slug' => 'livro-falante', 'name' => 'Livro Falante']);

        return LeadCheckout::create(array_merge([
            'site' => 'kazakora', 'nome' => 'ZILÁ Araújo', 'telefone' => '61992563064',
            'itens' => [['product_id' => $produto->id, 'nome' => 'Livro Falante', 'quantidade' => 1, 'preco_unitario' => 167.36]],
            'status' => LeadCheckout::STATUS_ABERTO, 'subtotal' => 167.36,
        ], $dados));
    }

    public function test_botao_envia_o_modelo_de_recuperacao_e_marca_o_carrinho(): void
    {
        $lead = $this->lead();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post("/admin/carrinhos-abandonados/{$lead->id}/whatsapp")->assertSessionHas('success');

        Http::assertSent(function (HttpRequest $r) {
            $t = $r['template'] ?? [];

            return $r['to'] === '5561992563064'
                && ($t['name'] ?? null) === 'recuperacao_carrinho_kazakora'
                && collect($t['components'] ?? [])->contains(fn ($c) => $c['type'] === 'body' && $c['parameters'][0]['text'] === 'Zilá' && $c['parameters'][1]['text'] === 'Livro Falante')
                && collect($t['components'] ?? [])->contains(fn ($c) => $c['type'] === 'button' && ($c['parameters'][0]['text'] ?? null) === 'livro-falante');
        });
        $this->assertNotNull($lead->fresh()->whatsapp_enviado_em);

        // Segunda vez: não envia de novo.
        $this->actingAs($admin)->post("/admin/carrinhos-abandonados/{$lead->id}/whatsapp")->assertSessionHas('error');
        Http::assertSentCount(1);
    }

    public function test_disparar_para_todos_so_manda_para_abertos_com_celular_que_nao_receberam(): void
    {
        $this->lead();
        $this->lead(['telefone' => null]);
        $this->lead(['status' => LeadCheckout::STATUS_CONVERTIDO]);
        $this->lead(['whatsapp_enviado_em' => now()]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/carrinhos-abandonados')
            ->assertInertia(fn ($page) => $page->where('resumo.para_disparar', 1));

        $this->actingAs($admin)->post('/admin/carrinhos-abandonados/whatsapp-todos')->assertSessionHas('success');

        Http::assertSentCount(1);
        $this->assertSame(2, LeadCheckout::query()->whereNotNull('whatsapp_enviado_em')->count());
    }

    public function test_quem_nao_esta_logado_nao_dispara(): void
    {
        $lead = $this->lead();

        $this->post("/admin/carrinhos-abandonados/{$lead->id}/whatsapp")->assertRedirect();
        Http::assertNothingSent();
    }
}
