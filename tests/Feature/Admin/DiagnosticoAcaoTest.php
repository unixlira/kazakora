<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Support\Diagnostico\AcoesShopee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class DiagnosticoAcaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_aplica_acao_e_marca_como_feita(): void
    {
        DB::table('diagnosticos_canal')->insert(['canal' => 'shopee', 'dados' => json_encode(['alertas' => []]), 'gerado_em' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $acoes = Mockery::mock(AcoesShopee::class);
        $acoes->shouldReceive('executar')->once()->with('meta_roas', '58216933582', 7.1, null, Mockery::any())
            ->andReturn(['ok' => true, 'mensagem' => 'Meta de ROAS 7,1 gravada e conferida na Shopee.', 'antes' => [], 'depois' => []]);
        $this->app->instance(AcoesShopee::class, $acoes);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->from('/admin')
            ->post('/admin/diagnostico/shopee/acao', ['item_id' => '58216933582', 'acao' => 'meta_roas', 'valor' => 7.1])
            ->assertRedirect('/admin')
            ->assertSessionHas('success');

        $dados = json_decode(DB::table('diagnosticos_canal')->value('dados'), true);
        $this->assertSame(7.1, $dados['feitas']['58216933582']['meta_roas']['valor']);
    }

    public function test_acao_desconhecida_e_recusada(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->from('/admin')
            ->post('/admin/diagnostico/shopee/acao', ['item_id' => '1', 'acao' => 'delete'])
            ->assertSessionHasErrors('acao');
    }

    public function test_quem_nao_e_admin_nao_mexe_na_shopee(): void
    {
        $this->app->instance(AcoesShopee::class, Mockery::mock(AcoesShopee::class)->shouldNotReceive('executar')->getMock());
        $user = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $this->actingAs($user)
            ->post('/admin/diagnostico/shopee/acao', ['item_id' => '1', 'acao' => 'pausar_ads'])
            ->assertStatus(403);
    }
}
