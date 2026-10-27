<?php

namespace Tests\Feature\Admin;

use App\Services\Meta\MetaAdsCrescimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Card de crescimento da tela Meta ADS (pedido do Lira em 2026-10-27): dia
 * 1º ao fim do mês, campanha principal subindo ou caindo, um botão por
 * campanha ativa com a mais nova à esquerda.
 */
class MetaAdsCrescimentoTest extends TestCase
{
    use RefreshDatabase;

    private function campanha(string $id, string $nome, string $status, string $criada): void
    {
        DB::table('meta_ads_dados_campanhas')->insert([
            'meta_id' => $id, 'nome' => $nome, 'status' => $status,
            'criada_na_meta' => $criada, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function dia(string $campanha, string $anuncio, string $data, float $gasto, int $compras, float $vendas): void
    {
        DB::table('meta_ads_dados_desempenho')->insert([
            'anuncio_meta_id' => $anuncio, 'conjunto_meta_id' => 's'.$campanha, 'campanha_meta_id' => $campanha,
            'data' => $data, 'gasto' => $gasto, 'compras' => $compras, 'vendas' => $vendas,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_principal_subindo_botoes_da_mais_nova_para_a_mais_antiga(): void
    {
        $this->campanha('100', 'Caixa 85', 'ACTIVE', '2026-10-05 10:00:00');
        $this->campanha('200', 'Bike', 'ACTIVE', '2026-10-20 10:00:00');
        $this->campanha('300', 'Árvore', 'DELETED', '2026-10-25 10:00:00');
        $this->campanha('400', 'Setembro', 'DELETED', '2026-09-01 10:00:00');

        // Caixa 85 gasta mais e vende cada dia mais (dois anúncios somam no dia).
        foreach ([1, 2, 3, 4] as $i) {
            $this->dia('100', 'a1', "2026-10-0{$i}", 10, $i, 50 * $i);
            $this->dia('100', 'a2', "2026-10-0{$i}", 5, 0, 0);
        }
        // Hoje (dia 5) pela metade: fica no gráfico, fora da tendência.
        $this->dia('100', 'a1', '2026-10-05', 1, 0, 0);
        // Bike vende cada dia menos.
        foreach ([1 => 90, 2 => 60, 3 => 30, 4 => 0] as $d => $v) {
            $this->dia('200', 'b1', "2026-10-0{$d}", 3, 0, $v);
        }
        // Excluída com dado no mês ganha botão; a sem dado no mês, não.
        $this->dia('300', 'c1', '2026-10-02', 9, 1, 99);
        $this->dia('400', 'd1', '2026-09-20', 99, 9, 999);

        $r = app(MetaAdsCrescimento::class)->montar(Carbon::parse('2026-10-05 15:00', 'America/Sao_Paulo'));

        $this->assertCount(31, $r['dias']);
        $this->assertSame('2026-10-01', $r['dias'][0]);
        $this->assertSame('2026-10-31', $r['dias'][30]);
        $this->assertSame('100', $r['principal']);
        // A mais nova primeiro.
        $this->assertSame(['300', '200', '100'], array_column($r['campanhas'], 'meta_id'));

        $caixa = $r['campanhas'][2];
        $this->assertSame([50.0, 100.0, 150.0, 200.0, 0.0, null], array_slice($caixa['serie']['vendas'], 0, 6));
        $this->assertSame(61.0, $caixa['totais']['gasto']);
        $this->assertSame('subindo', $caixa['tendencia']['direcao']);
        $this->assertSame(50.0, $caixa['tendencia']['inclinacao']);

        $this->assertSame('caindo', $r['campanhas'][1]['tendencia']['direcao']);
    }

    public function test_comeco_do_mes_e_cedo_para_dizer(): void
    {
        $this->campanha('100', 'Caixa 85', 'ACTIVE', '2026-10-01 10:00:00');
        $this->dia('100', 'a1', '2026-11-01', 10, 1, 50);

        $r = app(MetaAdsCrescimento::class)->montar(Carbon::parse('2026-11-02 09:00', 'America/Sao_Paulo'));

        $this->assertCount(30, $r['dias']);
        $this->assertSame('cedo', $r['campanhas'][0]['tendencia']['direcao']);
    }

    public function test_tela_gasto_e_resultados_manda_o_crescimento(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]));
        $this->campanha('100', 'Caixa 85', 'ACTIVE', '2026-10-01 10:00:00');
        config(['inertia.testing.ensure_pages_exist' => false]);

        $this->get('/admin/publicidade/meta-ads')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Publicidade/Plataforma', false)
                ->missing('crescimento')
                ->loadDeferredProps(fn ($recarga) => $recarga
                    ->where('crescimento.principal', '100')
                    ->where('crescimento.campanhas.0.nome', 'Caixa 85')));
    }
}
