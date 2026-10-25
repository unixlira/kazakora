<?php

namespace Tests\Feature\Checkout;

use App\Modules\Checkout\Models\LeadCheckout;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prazo de guarda do carrinho abandonado (decisão do Lira 2026-10-24): 3
 * meses (90 dias) desde a última interação; abertos e convertidos saem.
 */
class LimparLeadsDoCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function lead(string $status, int $diasSemMexer): LeadCheckout
    {
        $lead = LeadCheckout::create(['site' => 'kazakora', 'email' => uniqid().'@exemplo.com', 'itens' => [], 'status' => $status]);
        $quando = now()->subDays($diasSemMexer);
        // created_at antigo em todos: o corte é pela última interação.
        $lead->forceFill(['created_at' => now()->subDays(200), 'updated_at' => $quando])->saveQuietly();

        return $lead;
    }

    public function test_apaga_aberto_e_convertido_sem_interacao_ha_mais_de_90_dias(): void
    {
        $abertoVelho = $this->lead(LeadCheckout::STATUS_ABERTO, 91);
        $convertidoVelho = $this->lead(LeadCheckout::STATUS_CONVERTIDO, 120);
        $abertoNoPrazo = $this->lead(LeadCheckout::STATUS_ABERTO, 89);
        $convertidoNoPrazo = $this->lead(LeadCheckout::STATUS_CONVERTIDO, 10);

        $this->artisan('leads:limpar')->assertSuccessful();

        $this->assertModelMissing($abertoVelho);
        $this->assertModelMissing($convertidoVelho);
        $this->assertModelExists($abertoNoPrazo);
        $this->assertModelExists($convertidoNoPrazo);
    }

    public function test_simulacao_conta_e_nao_apaga(): void
    {
        $velho = $this->lead(LeadCheckout::STATUS_ABERTO, 100);
        $this->lead(LeadCheckout::STATUS_CONVERTIDO, 100);

        $this->artisan('leads:limpar --dry-run')
            ->expectsOutputToContain('apagaria 1 abertos e 1 convertidos')
            ->assertSuccessful();

        $this->assertModelExists($velho);
        $this->assertSame(2, LeadCheckout::count());
    }

    public function test_prazo_vem_da_config(): void
    {
        config(['loja.lead_checkout_dias_guarda' => 30]);
        $antigo = $this->lead(LeadCheckout::STATUS_ABERTO, 31);
        $recente = $this->lead(LeadCheckout::STATUS_ABERTO, 29);

        $this->artisan('leads:limpar')->assertSuccessful();

        $this->assertModelMissing($antigo);
        $this->assertModelExists($recente);
    }

    public function test_agendado_todo_dia(): void
    {
        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'leads:limpar'));

        $this->assertNotNull($evento);
        $this->assertSame('52 3 * * *', $evento->expression);
    }
}
