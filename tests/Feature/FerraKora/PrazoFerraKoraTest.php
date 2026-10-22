<?php

namespace Tests\Feature\FerraKora;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Services\CorreiosFreightQuoteService;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** Prazo pelo CEP no site FerraKora: Full, Flex (até 3 dias úteis) ou sem selo, igual ao KazaKora. */
class PrazoFerraKoraTest extends TestCase
{
    use RefreshDatabase;

    private function correios(array $dias): void
    {
        $mock = Mockery::mock(CorreiosFreightQuoteService::class);
        $mock->shouldReceive('quote')->andReturn(collect($dias)->map(fn ($d) => ['estimated_days' => $d])->all());
        $this->app->instance(CorreiosFreightQuoteService::class, $mock);
    }

    private function produtoNoSite(): Product
    {
        $pai = Product::factory()->create(['sku' => 'FER-TRE-5M', 'slug' => 'trena-5m', 'is_active' => true, 'stock' => 5]);
        FerraKoraProduto::query()->create(['product_id' => $pai->id, 'categoria' => 'Trenas', 'ordem' => 1, 'ativo' => true]);

        return $pai;
    }

    public function test_flex_ate_3_dias_e_sem_selo_depois(): void
    {
        $pai = $this->produtoNoSite();

        $this->correios([5, 2]);
        $this->getJson("/api/ferrakora/produtos/kz-{$pai->id}/prazo?cep=13990-000")
            ->assertOk()->assertJson(['prazo_dias' => 2, 'modalidade' => 'flex']);

        $this->correios([6, 9]);
        $this->getJson("/api/ferrakora/produtos/kz-{$pai->id}/prazo?cep=69900-000")
            ->assertOk()->assertJson(['prazo_dias' => 6, 'modalidade' => null]);
    }

    public function test_full_na_area_expressa_sem_consultar_correios(): void
    {
        $pai = $this->produtoNoSite();
        config(['entrega_expressa.faixas' => [['inicio' => '01000000', 'fim' => '05999999', 'local' => 'São Paulo']]]);

        $this->getJson("/api/ferrakora/produtos/kz-{$pai->id}/prazo?cep=01310-100")
            ->assertOk()->assertJson(['modalidade' => 'full', 'entrega_expressa' => ['local' => 'São Paulo']]);
    }

    public function test_cep_invalido_e_produto_fora_do_site(): void
    {
        $pai = $this->produtoNoSite();

        $this->getJson("/api/ferrakora/produtos/kz-{$pai->id}/prazo?cep=123")->assertStatus(422);
        $this->getJson('/api/ferrakora/produtos/kz-999999/prazo?cep=13990-000')->assertStatus(404);
    }
}
