<?php

namespace Tests\Unit\Marketplace;

use App\Modules\Marketplace\Support\Diagnostico\DiagnosticoShopee;
use PHPUnit\Framework\TestCase;

class AcoesSugeridasShopeeTest extends TestCase
{
    private function ad(array $extra = []): array
    {
        return $extra + ['ativo' => true, 'meta' => 0.0, 'roas' => 4.4, 'gasto' => 57.79, 'pedidos' => 3, 'conversao' => 1.63, 'lance' => 'auto'];
    }

    public function test_pausa_ads_que_gasta_sem_vender(): void
    {
        $acoes = DiagnosticoShopee::acoesSugeridas(['roas_empate' => 3.0, 'preco_minimo' => null], $this->ad(['roas' => 0.0, 'gasto' => 31.71, 'pedidos' => 0, 'conversao' => 0.0]), 79.79);

        $this->assertSame(['pausar_ads'], array_column($acoes, 'acao'));
    }

    public function test_pausa_quando_da_prejuizo_e_conversao_nao_aguenta_o_empate(): void
    {
        // Caminhão Unicórnio: ROAS 1,69, empate 7,2, conversão 0,23%.
        $acoes = DiagnosticoShopee::acoesSugeridas(['roas_empate' => 7.2, 'preco_minimo' => null], $this->ad(['roas' => 1.69, 'gasto' => 79.8, 'conversao' => 0.23]), 133.0);

        $this->assertSame(['pausar_ads'], array_column($acoes, 'acao'));
    }

    public function test_meta_de_roas_e_empate_mais_dois(): void
    {
        // Controle Xbox: conversão 1,63% aguenta empate abaixo de 5.
        $acoes = DiagnosticoShopee::acoesSugeridas(['roas_empate' => 4.9, 'preco_minimo' => null], $this->ad(['roas' => 5.2]), 63.42);

        $this->assertSame('meta_roas', $acoes[0]['acao']);
        $this->assertSame(6.9, $acoes[0]['valor']);
    }

    public function test_nao_sugere_meta_ja_configurada_acima(): void
    {
        $acoes = DiagnosticoShopee::acoesSugeridas(['roas_empate' => 4.9, 'preco_minimo' => null], $this->ad(['roas' => 9.0, 'meta' => 8.0]), 63.42);

        $this->assertSame([], $acoes);
    }

    public function test_preco_abaixo_do_minimo_sugere_preco_terminado_em_90(): void
    {
        $acoes = DiagnosticoShopee::acoesSugeridas(['roas_empate' => 10.9, 'preco_minimo' => 59.1], null, 50.93);

        $this->assertSame([['acao' => 'preco', 'valor' => 59.9, 'rotulo' => 'Preço R$ 59,90']], $acoes);
    }
}
