<?php

namespace Tests\Feature\MelhorEnvio;

use App\Modules\Marketplace\Support\EtiquetaOcr;
use Tests\TestCase;

/**
 * Etiqueta da Loggi do #4198 (15/11/2026), palavras como o tesseract leu na
 * VPS: o logo do Melhor Envio sai, os dados oficiais ficam.
 */
class EtiquetaOcrTest extends TestCase
{
    private function p(string $texto, float $x0, float $y0, float $x1, float $y1, string $linha): array
    {
        return compact('texto', 'x0', 'y0', 'x1', 'y1', 'linha');
    }

    private function loggi(): array
    {
        return [
            $this->p('melhor', 0.683, 0.059, 0.907, 0.103, '1'),
            $this->p('Loggi', 0.104, 0.059, 0.317, 0.124, '2'),
            $this->p('Ponto', 0.139, 0.134, 0.215, 0.149, '3'),
            $this->p('envio', 0.764, 0.108, 0.943, 0.136, '4'),
            $this->p('NF:', 0.037, 0.175, 0.076, 0.188, '5'),
            $this->p('melhorenvio.com', 0.767, 0.177, 0.963, 0.19, '6'),
            $this->p('Peso', 0.79, 0.201, 0.848, 0.214, '8'),
            $this->p('2170', 0.907, 0.201, 0.963, 0.214, '8'),
            $this->p('ME262G564U5BR', 0.288, 0.243, 0.714, 0.27, '9'),
            $this->p('REMETENTE', 0.039, 0.577, 0.212, 0.592, '20'),
            $this->p('Kazakora', 0.039, 0.603, 0.155, 0.618, '21'),
            $this->p('03187-040', 0.037, 0.679, 0.173, 0.694, '24'),
        ];
    }

    public function test_cobre_o_logo_e_o_endereco_do_melhor_envio_sem_apagar_o_peso(): void
    {
        $layout = EtiquetaOcr::layout($this->loggi());

        $this->assertNotNull($layout['logo']);
        [$x0, $y0, $x1, $y1] = $layout['logo'];
        $this->assertLessThanOrEqual(0.683, $x0);
        $this->assertGreaterThanOrEqual(0.136, $y1);
        $this->assertLessThan(0.982 + 1e-9, $x1);

        // Cada caixa coberta para antes do "Peso (g)" e não encosta no "Loggi".
        foreach ($layout['cobrir'] as [$cx0, $cy0, $cx1, $cy1]) {
            $this->assertFalse($cx0 < 0.848 && $cx1 > 0.79 && $cy0 < 0.214 && $cy1 > 0.201, 'cobriu o peso');
            $this->assertGreaterThan(0.317, $cx0);
        }
        $this->assertTrue(collect($layout['cobrir'])->contains(fn ($c) => $c[0] <= 0.767 && $c[2] >= 0.963 && $c[1] <= 0.177 && $c[3] >= 0.19), 'melhorenvio.com ficou');
    }

    public function test_reescreve_a_linha_do_nome_do_remetente_e_acha_espaco_para_o_sku(): void
    {
        $layout = EtiquetaOcr::layout($this->loggi());

        [, $ry0, , $ry1, , $comRotulo] = $layout['remetente'];
        $this->assertFalse($comRotulo);
        $this->assertLessThanOrEqual(0.603, $ry0);
        $this->assertGreaterThanOrEqual(0.618, $ry1);
        $this->assertLessThan(0.679, $ry1);

        $this->assertNotNull($layout['itens']);
        $this->assertGreaterThan(0.694, $layout['itens'][1]);
    }

    public function test_envio_solto_longe_do_logo_nao_e_marca(): void
    {
        $layout = EtiquetaOcr::layout([$this->p('Data', 0.1, 0.5, 0.2, 0.52, '1'), $this->p('envio', 0.22, 0.5, 0.32, 0.52, '1')]);

        $this->assertSame([], $layout['cobrir']);
        $this->assertNull($layout['logo']);
    }
}
