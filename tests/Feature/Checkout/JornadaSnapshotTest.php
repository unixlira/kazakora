<?php

namespace Tests\Feature\Checkout;

use App\Modules\Analytics\Models\CheckoutEvento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JornadaSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app.url' => 'http://localhost', 'services.ferrakora.origens' => 'ferrakora.devlira.com.br']);
    }

    private function evento(): array
    {
        return ['evento' => 'carrinho_quantidade', 'jornada' => [
            'id' => str_repeat('a', 32), 'sessao' => str_repeat('b', 32),
            'estado' => 'observado', 'motivo' => 'quantidade',
            'antes' => ['currency' => 'BRL', 'subtotal' => 10000, 'discount' => 0, 'shipping' => 0, 'interest' => 0, 'total' => 10000,
                'items' => [['product_id' => 101, 'quantity' => 1, 'unit_price' => 10000]]],
            'depois' => ['currency' => 'BRL', 'subtotal' => 20000, 'discount' => 0, 'shipping' => 0, 'interest' => 0, 'total' => 20000,
                'items' => [['product_id' => 101, 'quantity' => 2, 'unit_price' => 10000]]],
        ]];
    }

    private function enviar(array $evento, bool $ferra = false)
    {
        return $this->call('POST', $ferra ? '/api/ferrakora/checkout/eventos' : '/finalizacao/eventos', [], [], [], [
            'CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/130.0',
            'HTTP_ORIGIN' => $ferra ? 'https://ferrakora.devlira.com.br' : 'http://localhost',
        ], json_encode(['pagina' => 'abc123def456', 'eventos' => [$evento]]));
    }

    public function test_persiste_snapshots_sem_duplicar_retry_e_separa_marcas(): void
    {
        $evento = $this->evento();
        $this->enviar($evento)->assertNoContent();
        $this->enviar($evento)->assertNoContent();
        $this->enviar($evento, true)->assertNoContent();
        $this->assertSame(2, CheckoutEvento::count());
        $linha = CheckoutEvento::whereNull('site')->sole();
        $this->assertSame(20000, $linha->jornada['depois']['total']);
        $this->assertSame('testing', $linha->jornada['ambiente']);
        // Retry conflitante não reescreve a linha original.
        $evento['jornada']['depois']['total'] = 123;
        $this->enviar($evento)->assertNoContent();
        $this->assertSame(20000, $linha->fresh()->jornada['depois']['total']);
        $this->assertArrayNotHasKey('ip', $linha->getAttributes());
        Http::assertNothingSent();
    }

    public function test_custos_desconhecidos_e_intencao_segura_nao_sao_confirmacao(): void
    {
        $e = $this->evento();
        $e['evento'] = 'pagamento_intencao';
        $e['jornada']['estado'] = 'intent';
        $e['jornada']['motivo'] = 'pix';
        $e['jornada']['depois']['shipping'] = null;
        $this->enviar($e)->assertNoContent();
        $this->assertNull(CheckoutEvento::sole()->jornada['depois']['shipping']);
        $e['jornada']['estado'] = 'observado';
        $this->enviar($e)->assertStatus(422);
    }

    public function test_olho_retorna_jornada_por_sessao_sem_pii(): void
    {
        $this->travelTo('2026-11-15 15:00:00');
        $e = $this->evento();
        $e['evento'] = 'carrinho_adicionar';
        $this->enviar($e)->assertNoContent();
        $visitor = CheckoutEvento::sole()->visitor_id;
        \App\Modules\Analytics\Models\SiteVisit::create(['site' => 'kazakora', 'visitor_id' => $visitor,
            'ip' => '192.0.2.10', 'path' => '/carrinho', 'consentiu' => false]);
        $admin = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);
        $this->actingAs($admin)->getJson('/admin/visitas-site/visitante?de=2026-11-15&ate=2026-11-15&ip=192.0.2.10&todos=1')
            ->assertOk()->assertJsonPath('jornada.sessoes.0.adicoes', 1)
            ->assertJsonPath('jornada.timeline.0.jornada.depois.total', 20000);
        $customer = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_CUSTOMER]);
        $this->actingAs($customer)->getJson('/admin/visitas-site/visitante?de=2026-11-15&ate=2026-11-15&ip=192.0.2.10&todos=1')->assertForbidden();
    }

    public function test_recusa_pii_e_valores_nao_limitados(): void
    {
        foreach ([['email' => 'fixture@example.invalid'], ['fbclid' => 'proibido'], ['estado' => 'confirmado'], ['motivo' => 'texto livre']] as $extra) {
            $e = $this->evento();
            $e['jornada'] = [...$e['jornada'], ...$extra];
            $this->enviar($e)->assertStatus(422);
        }
        $e = $this->evento();
        $e['jornada']['depois']['items'][0]['cpf'] = 'proibido';
        $this->enviar($e)->assertStatus(422);
        $e = $this->evento();
        $e['jornada']['depois']['total'] = -1;
        $this->enviar($e)->assertStatus(422);
        $e = $this->evento();
        $e['evento'] = 'pagamento_confirmado';
        $this->enviar($e)->assertStatus(422);
        $this->assertSame(0, CheckoutEvento::count());
    }
}
