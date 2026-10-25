<?php

namespace Tests\Unit\Support;

use App\Modules\Checkout\Support\AvaliacoesDoCheckout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Carrossel de depoimentos só com elogio claro (pedido 2026-10-25): nada de
 * ressalva, dúvida ou reclamação, mesmo com nota 5.
 */
class ElogioClaroTest extends TestCase
{
    #[DataProvider('elogios')]
    public function test_elogio_claro_entra(string $texto): void
    {
        $this->assertTrue(AvaliacoesDoCheckout::elogioClaro($texto));
    }

    #[DataProvider('duvidosas')]
    public function test_ressalva_duvida_ou_reclamacao_fica_fora(string $texto): void
    {
        $this->assertFalse(AvaliacoesDoCheckout::elogioClaro($texto));
    }

    public static function elogios(): array
    {
        return [
            ['Produto excelente, chegou rápido. Recomendo!'],
            ['Árvore perfeita, bem cheia, amei a minha compra.'],
            ['Super recomendo, vale a pena.'],
            ['Muito bom, pode comprar sem medo.'],
            ['Não decepciona, ótima qualidade.'],
        ];
    }

    public static function duvidosas(): array
    {
        return [
            'neutra' => ['Chegou conforme o anúncio.'],
            'parece' => ['Parece ser de boa qualidade, gostei.'],
            'não testou' => ['Chegou rápido, ainda não testei mas gostei.'],
            'mas' => ['Gostei muito, mas faltaram as buchas.'],
            '"mais" no lugar de "mas"' => ['Ótima qualidade, mais veio na cor errada.'],
            'só que' => ['Muito boa, só que tem que usar broca quadrada.'],
            'cor trocada' => ['Pedi branco e veio preto, material ótimo.'],
            'reclamação' => ['Comprei um detector de metais e me mandaram uma lâmpada'],
            'faltou peça' => ['Quantidade: era para ter 168 peças só tem 52 peças'],
            'pergunta' => ['Gostei, será que dura?'],
            'futuro' => ['Lindo, minha neta vai adorar.'],
            'nota de cautela' => ['Excelente, só é um pouco pesado.'],
            'texto longo cortado' => [str_repeat('Produto excelente, recomendo. ', 10)],
        ];
    }
}
