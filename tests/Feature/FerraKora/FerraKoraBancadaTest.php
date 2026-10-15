<?php

namespace Tests\Feature\FerraKora;

use App\Modules\Catalog\Models\Product;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Minha Bancada do FerraKora (favoritos, pedido 2026-10-15) pela API
 * /api/ferrakora/bancada, só com login.
 */
class FerraKoraBancadaTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $email = 'joao@exemplo.com'): string
    {
        return $this->postJson('/api/ferrakora/cadastro', ['name' => 'João', 'email' => $email, 'password' => 'segredo123'])
            ->assertCreated()->json('token');
    }

    private function noSite(bool $ativo = true): Product
    {
        $produto = Product::factory()->create(['is_active' => true]);
        FerraKoraProduto::query()->create(['product_id' => $produto->id, 'ativo' => $ativo]);

        return $produto;
    }

    public function test_sem_login_nao_entra(): void
    {
        $this->getJson('/api/ferrakora/bancada')->assertUnauthorized();
        $this->postJson('/api/ferrakora/bancada', ['produtos' => ['kz-1']])->assertUnauthorized();
    }

    public function test_guarda_lista_e_tira_sem_duplicar(): void
    {
        $token = $this->token();
        [$serra, $trena] = [$this->noSite(), $this->noSite()];

        $this->withToken($token)->getJson('/api/ferrakora/bancada')->assertOk()->assertExactJson(['produtos' => []]);

        $this->withToken($token)->postJson('/api/ferrakora/bancada', ['produtos' => ['kz-'.$serra->id]])
            ->assertOk()->assertExactJson(['produtos' => ['kz-'.$serra->id]]);

        // O mais recente vem primeiro; repetido não duplica.
        $this->travel(5)->seconds();
        $this->withToken($token)->postJson('/api/ferrakora/bancada', ['produtos' => ['kz-'.$trena->id, 'kz-'.$serra->id]])
            ->assertOk()->assertExactJson(['produtos' => ['kz-'.$trena->id, 'kz-'.$serra->id]]);
        $this->assertDatabaseCount('ferrakora_bancada', 2);

        $this->withToken($token)->deleteJson('/api/ferrakora/bancada/kz-'.$trena->id)
            ->assertOk()->assertExactJson(['produtos' => ['kz-'.$serra->id]]);
    }

    public function test_produto_fora_do_site_e_ignorado_e_id_invalido_recusado(): void
    {
        $token = $this->token();
        $fora = $this->noSite(false);
        $semCadastro = Product::factory()->create();

        $this->withToken($token)->postJson('/api/ferrakora/bancada', ['produtos' => ['kz-'.$fora->id, 'kz-'.$semCadastro->id]])
            ->assertOk()->assertExactJson(['produtos' => []]);

        $this->withToken($token)->postJson('/api/ferrakora/bancada', ['produtos' => ['kzv-3']])
            ->assertStatus(422)->assertJsonValidationErrors('produtos.0');
    }

    public function test_bancada_de_um_cliente_nao_aparece_para_outro(): void
    {
        $produto = $this->noSite();
        $this->withToken($this->token())->postJson('/api/ferrakora/bancada', ['produtos' => ['kz-'.$produto->id]])->assertOk();

        $this->withToken($this->token('maria@exemplo.com'))->getJson('/api/ferrakora/bancada')
            ->assertOk()->assertExactJson(['produtos' => []]);
    }
}
