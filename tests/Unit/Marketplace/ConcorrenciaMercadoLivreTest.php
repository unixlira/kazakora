<?php

namespace Tests\Unit\Marketplace;

use App\Modules\Marketplace\Support\Oportunidades\MaisVendidosMercadoLivre as Ml;
use PHPUnit\Framework\TestCase;

/**
 * Regras de concorrência e margem do Radar no Mercado Livre (pedido do Lira
 * 2026-10-13): priorizar nicho pouco disputado e fugir de guerra de preço.
 */
class ConcorrenciaMercadoLivreTest extends TestCase
{
    private function linha(int $posicao, ?int $vendedores): array
    {
        return ['id' => "MLB{$posicao}", 'posicao' => $posicao, 'vendedores' => $vendedores];
    }

    public function test_faixas_de_concorrencia(): void
    {
        $this->assertNull(Ml::classificar($this->linha(1, null), [])['concorrencia']);
        $this->assertNull(Ml::classificar($this->linha(1, 0), [])['concorrencia']);
        $this->assertSame('baixa', Ml::classificar($this->linha(1, 1), [10])['concorrencia']);
        $this->assertSame('baixa', Ml::classificar($this->linha(1, 3), [10])['concorrencia']);
        $this->assertSame('media', Ml::classificar($this->linha(1, 4), [10])['concorrencia']);
        $this->assertSame('media', Ml::classificar($this->linha(1, 10), [10])['concorrencia']);
        $this->assertSame('alta', Ml::classificar($this->linha(1, 11), [10])['concorrencia']);
    }

    public function test_mediana_guerra_de_preco_e_custo_maximo(): void
    {
        // Mediana de 4 preços = média dos dois do meio: (100 + 100) / 2.
        $semGuerra = Ml::classificar($this->linha(1, 4), [100, 90, 100, 110]);
        $this->assertSame(100.0, $semGuerra['preco_mediana']);
        $this->assertFalse($semGuerra['guerra_preco']);
        $this->assertSame(56.0, $semGuerra['custo_maximo']);

        // 79 < 80% de 100: alguém está derrubando o preço.
        $guerra = Ml::classificar($this->linha(1, 3), [79, 100, 100]);
        $this->assertTrue($guerra['guerra_preco']);
        $this->assertFalse($guerra['oportunidade_nicho']);

        // Exatamente 80% ainda não é guerra.
        $this->assertFalse(Ml::classificar($this->linha(1, 3), [80, 100, 100])['guerra_preco']);

        $semPreco = Ml::classificar($this->linha(1, 2), []);
        $this->assertNull($semPreco['preco_mediana']);
        $this->assertNull($semPreco['custo_maximo']);
        $this->assertFalse($semPreco['guerra_preco']);
    }

    public function test_margem_so_com_custo(): void
    {
        $sem = Ml::classificar($this->linha(1, 2), [100]);
        $this->assertNull($sem['custo']);
        $this->assertNull($sem['margem_estimada']);
        $this->assertTrue($sem['oportunidade_nicho']);

        // (100 × 0,56 − 40) / 100 + 0,15 = 0,31.
        $com = Ml::classificar($this->linha(1, 2), [100], 40.0);
        $this->assertSame(40.0, $com['custo']);
        $this->assertEqualsWithDelta(0.31, $com['margem_estimada'], 0.0001);

        // No custo máximo a margem é exatamente os 15% da regra.
        $this->assertEqualsWithDelta(0.15, Ml::classificar($this->linha(1, 2), [100], 56.0)['margem_estimada'], 0.0001);
    }

    public function test_ordem_nicho_depois_media_sem_guerra_depois_o_resto(): void
    {
        $produtos = [
            Ml::classificar($this->linha(1, 50), [100]),        // alta
            Ml::classificar($this->linha(2, 5), [50, 100, 100]), // média com guerra
            Ml::classificar($this->linha(3, 6), [100]),         // média sem guerra
            Ml::classificar($this->linha(4, 2), [100]),         // nicho
            Ml::classificar($this->linha(5, null), []),         // sem dado
            Ml::classificar($this->linha(6, 1), [100]),         // nicho
        ];

        $this->assertSame([4, 6, 3, 1, 2, 5], array_column(Ml::ordenar($produtos), 'posicao'));
    }
}
