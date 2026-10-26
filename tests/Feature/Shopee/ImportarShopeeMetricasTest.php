<?php

namespace Tests\Feature\Shopee;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * shopee:metricas-importar (2026-10-26): os três formatos de arquivo da
 * coleta da tela da Shopee entram em shopee_metricas sem duplicar.
 */
class ImportarShopeeMetricasTest extends TestCase
{
    use RefreshDatabase;

    private string $pasta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pasta = sys_get_temp_dir().'/shopee-metricas-'.uniqid();
        mkdir($this->pasta);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->pasta.'/*') ?: []);
        @rmdir($this->pasta);
        parent::tearDown();
    }

    private function arquivo(string $nome, array $json): string
    {
        file_put_contents($caminho = "{$this->pasta}/{$nome}", json_encode($json));

        return $caminho;
    }

    private function funil(string $coletado, int $impressoes): array
    {
        return [
            'fonte' => 'seller.shopee.com.br /api/mydata/v4/product/performance', 'periodo' => 'past7days',
            'inicio' => '2026-10-19', 'fim' => '2026-10-25', 'coletado_em' => $coletado, 'total' => 1,
            'itens' => [[
                'id' => 58213072403, 'name' => 'Ring Light', 'status' => 1, 'display_tag_label' => 'normal',
                'product_card_impressions' => $impressoes, 'product_card_clicks' => 817, 'ctr' => 0.0304, 'uv' => 660,
                'bounce_rate' => 0.2818, 'add_to_cart_buyers' => 161, 'placed_orders' => 45, 'placed_sales' => 2377.62,
                'average_days_to_repeat_placed_order' => 0,
                'models' => [['id' => 129841227781, 'name' => 'Branco', 'add_to_cart_units' => 6, 'placed_to_paid_buyers_rate' => 0.5]],
            ]],
        ];
    }

    private function valor(string $metrica, ?string $model = null): ?float
    {
        $v = DB::table('shopee_metricas')->where('item_id', '58213072403')->where('metrica', $metrica)
            ->when($model, fn ($q) => $q->where('model_id', $model), fn ($q) => $q->whereNull('model_id'))->value('valor');

        return $v === null ? null : (float) $v;
    }

    public function test_coleta_diaria_do_funil_vira_uma_linha_por_metrica_com_porcentagem_em_pontos(): void
    {
        $arquivo = $this->arquivo('funil-7d.json', $this->funil('2026-10-26T10:10:09.516Z', 26893));

        $this->artisan('shopee:metricas-importar', ['caminhos' => [$arquivo]])->assertSuccessful();

        $this->assertSame(3.04, $this->valor('ctr'));
        $this->assertSame(28.18, $this->valor('taxa_rejeicao'));
        $this->assertSame(26893.0, $this->valor('impressoes'));
        $this->assertSame(161.0, $this->valor('add_carrinho_compradores'));
        $this->assertSame(45.0, $this->valor('pedidos'));
        // Variação entra com model_id e o nome do anúncio junto.
        $this->assertSame(6.0, $this->valor('add_carrinho', '129841227781'));
        $this->assertSame(50.0, $this->valor('taxa_pagamento', '129841227781'));

        $linha = DB::table('shopee_metricas')->where('metrica', 'ctr')->first();
        $this->assertSame('produto', $linha->escopo);
        $this->assertSame('%', $linha->unidade);
        $this->assertSame('2026-10-19', substr($linha->periodo_inicio, 0, 10));
        $this->assertNull($linha->data);
        // 10:10 UTC = 07:10 em São Paulo.
        $this->assertSame('2026-10-26 07:10:09', substr((string) $linha->coletado_em, 0, 19));
        $this->assertSame('Ring Light · Branco', DB::table('shopee_metricas')->where('model_id', '129841227781')->value('nome'));
    }

    public function test_reimportar_nao_duplica_e_coleta_mais_velha_nao_sobrescreve(): void
    {
        $nova = $this->arquivo('nova.json', $this->funil('2026-10-26T10:10:00Z', 26893));
        $velha = $this->arquivo('velha.json', $this->funil('2026-10-25T10:10:00Z', 100));

        $this->artisan('shopee:metricas-importar', ['caminhos' => [$nova]])->assertSuccessful();
        $total = DB::table('shopee_metricas')->count();
        $this->artisan('shopee:metricas-importar', ['caminhos' => [$nova]])->assertSuccessful();
        $this->artisan('shopee:metricas-importar', ['caminhos' => [$velha]])->assertSuccessful();

        $this->assertSame($total, DB::table('shopee_metricas')->count());
        $this->assertSame(26893.0, $this->valor('impressoes'));
    }

    public function test_formato_da_tela_e_ads_da_coleta_sem_a_conta_de_margem(): void
    {
        $this->arquivo('2026-10-26-vendas-hora.json', [
            'coletado_em' => '2026-10-26T16:45:00-03:00', 'fonte' => 'tela', 'tela' => 'Vendas > Visão geral',
            'url' => 'https://seller.shopee.com.br/datacenter/sales/overview', 'periodo_inicio' => '2026-09-26', 'periodo_fim' => '2026-10-25',
            'linhas' => [
                ['escopo' => 'hora', 'item_id' => null, 'model_id' => null, 'nome' => null, 'data' => '2026-09-26', 'hora' => 0,
                    'metrica' => 'visitantes', 'rotulo' => 'Visitantes', 'valor' => 18, 'unidade' => '', 'texto_original' => '18'],
                ['escopo' => 'hora', 'data' => '2026-09-26', 'hora' => 1, 'metrica' => 'visitantes', 'valor' => 10],
                ['escopo' => 'inventado', 'metrica' => 'x', 'valor' => 1],
                ['escopo' => 'hora', 'metrica' => 'pedidos', 'valor' => 'sem número'],
            ],
        ]);
        $this->arquivo('ads-7d.json', [
            'inicio' => '2026-10-19', 'fim' => '2026-10-25', 'taxa_media' => 0.29,
            'ads' => ['58213072403' => ['ativo' => true, 'campanhas' => [['id' => 1]], 'meta' => 7.5, 'lance' => 'auto', 'orcamento' => 25,
                'gasto' => 120.5, 'gmv' => 600, 'pedidos' => 9, 'cliques' => 300, 'impressoes' => 9000]],
            'margem' => ['58213072403' => ['lucro_unidade' => 5.66, 'margem' => 10.2]],
        ]);

        // Pasta inteira: lê todos os .json de dentro.
        $this->artisan('shopee:metricas-importar', ['caminhos' => [$this->pasta]])->assertSuccessful();

        $this->assertSame(2, DB::table('shopee_metricas')->where('escopo', 'hora')->count());
        $this->assertSame(1, (int) DB::table('shopee_metricas')->where('escopo', 'hora')->where('hora', 1)->value('hora'));
        $this->assertSame(120.5, (float) DB::table('shopee_metricas')->where('metrica', 'gasto_ads')->value('valor'));
        $this->assertSame(1.0, (float) DB::table('shopee_metricas')->where('metrica', 'ads_ativo')->value('valor'));
        $this->assertSame('api', DB::table('shopee_metricas')->where('metrica', 'gasto_ads')->value('fonte'));
        // A conta de margem do arquivo não entra (lucro é só ContributionMargin).
        $this->assertFalse(DB::table('shopee_metricas')->whereIn('metrica', ['lucro_unidade', 'margem'])->exists());
    }

    public function test_simular_nao_grava(): void
    {
        $arquivo = $this->arquivo('funil-7d.json', $this->funil('2026-10-26T10:10:00Z', 1));

        $this->artisan('shopee:metricas-importar', ['caminhos' => [$arquivo], '--simular' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('shopee_metricas')->count());
    }
}
