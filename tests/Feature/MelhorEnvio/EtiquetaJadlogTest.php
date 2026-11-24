<?php

namespace Tests\Feature\MelhorEnvio;

use App\Modules\Marketplace\Support\EtiquetaMelhorEnvioComMarca;
use App\Modules\Marketplace\Support\EtiquetaOcr;
use App\Support\SiteAtual;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

/**
 * Etiqueta da Jadlog (pedido do Lira em 24/11/2026): troca de lado (KazaKora
 * no quadro grande, logo da Jadlog no quadro do Melhor Envio), posições
 * medidas sem OCR e SKU embaixo do código de barras do rodapé.
 */
class EtiquetaJadlogTest extends TestCase
{
    /** Desenho do modelo Jadlog (só as linhas dos quadros, em px da imagem de 770 x 1084), sem dado de cliente. */
    private function pdf(): string
    {
        $pdf = new Fpdi('P', 'pt', [577.5, 813]);
        $pdf->AddPage();
        $px = fn (float $v) => $v * 0.75;
        $pdf->SetLineWidth(1.5);
        $pdf->Rect($px(10), $px(40), $px(749), $px(1033));
        $pdf->Line($px(526), $px(40), $px(526), $px(709));
        $pdf->Line($px(526), $px(166), $px(759), $px(166));
        $pdf->Line($px(10), $px(202), $px(526), $px(202));
        $pdf->Line($px(10), $px(708), $px(759), $px(708));
        $pdf->Line($px(10), $px(942), $px(759), $px(942));
        $pdf->SetFont('Helvetica', 'B', 18);
        $pdf->Text($px(60), $px(120), 'jadlog');
        $pdf->Text($px(580), $px(110), 'melhor envio');
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Text($px(28), $px(181), 'ORD-000000000000');
        $pdf->SetFillColor(0, 0, 0);
        $pdf->Rect($px(224), $px(982), $px(321), $px(47), 'F');

        return $pdf->Output('S');
    }

    private function semOcr(): void
    {
        // Posição medida: a Jadlog não passa pelo OCR.
        $this->mock(EtiquetaOcr::class, fn ($m) => $m->shouldNotReceive('palavras'));
    }

    public function test_servico_da_jadlog_vira_modelo_jadlog(): void
    {
        $this->assertSame('jadlog', EtiquetaMelhorEnvioComMarca::modelo('Jadlog .Package'));
        $this->assertSame('jadlog', EtiquetaMelhorEnvioComMarca::modelo('JADLOG .Package Centralizado'));
        $this->assertSame('jadlog', EtiquetaMelhorEnvioComMarca::modelo('.Com (Jadlog)'));
        $this->assertSame('correios', EtiquetaMelhorEnvioComMarca::modelo('Correios SEDEX'));
        $this->assertSame('jet', EtiquetaMelhorEnvioComMarca::modelo('JeT Standard'));
    }

    public function test_troca_de_lado_sem_ocr_com_os_dois_logos(): void
    {
        $this->semOcr();
        $original = $this->pdf();
        $novo = app(EtiquetaMelhorEnvioComMarca::class)->aplicar($original, 'Jadlog .Package', 1);

        $this->assertNotSame($original, $novo);
        $this->assertStringContainsString('577.50 813.00', $novo);
        // Logo da Jadlog (cinza, 1 canal) + símbolo e palavra da KazaKora (RGB).
        $this->assertStringContainsString('/DeviceGray', $novo);
        $this->assertStringContainsString('/DeviceRGB', $novo);

        $layout = EtiquetaMelhorEnvioComMarca::layoutJadlog();
        $this->assertSame(EtiquetaMelhorEnvioComMarca::JADLOG['logo_grande'], $layout['logo']);
        $this->assertSame(EtiquetaMelhorEnvioComMarca::LOGO_JADLOG, $layout['transportadora'][4]);
        $this->assertCount(2, $layout['cobrir']);
        $this->assertNull($layout['remetente'], 'Jadlog não imprime telefone do remetente');
    }

    /** Tudo por dentro das linhas dos quadros e sem cobrir o ORD nem os códigos de barras. */
    public function test_posicoes_medidas_ficam_dentro_dos_quadros(): void
    {
        $fx = fn (float $px) => $px / 770;
        $fy = fn (float $px) => $px / 1084;
        $j = EtiquetaMelhorEnvioComMarca::JADLOG;

        // Quadro grande: linhas em x 10-11 / 526-527 e y 40-41 / 202-203; ORD começa em 169.
        foreach (['cobrir_grande', 'logo_grande'] as $k) {
            [$x0, $y0, $x1, $y1] = $j[$k];
            $this->assertGreaterThan($fx(11), $x0, $k);
            $this->assertLessThan($fx(526), $x1, $k);
            $this->assertGreaterThan($fy(41), $y0, $k);
            $this->assertLessThan($fy(169), $y1, "{$k} cobre o ORD");
        }
        // Margem da KazaKora: pelo menos 10 px de cada linha e do ORD.
        [$x0, $y0, $x1, $y1] = $j['logo_grande'];
        $this->assertGreaterThanOrEqual($fx(21), $x0);
        $this->assertGreaterThanOrEqual($fy(51), $y0);
        $this->assertLessThanOrEqual($fy(159), $y1);

        // Quadro pequeno: linhas em x 526-527 / 758-759 e y 40-41 / 166-167.
        foreach (['cobrir_pequeno', 'logo_pequeno'] as $k) {
            [$x0, $y0, $x1, $y1] = $j[$k];
            $this->assertGreaterThan($fx(527), $x0, $k);
            $this->assertLessThan($fx(758), $x1, $k);
            $this->assertGreaterThan($fy(41), $y0, $k);
            $this->assertLessThan($fy(166), $y1, $k);
        }
        // O logo do Melhor Envio (579-704 x 74-132) fica todo coberto.
        [$x0, $y0, $x1, $y1] = $j['cobrir_pequeno'];
        $this->assertTrue($x0 < $fx(579) && $x1 > $fx(704) && $y0 < $fy(74) && $y1 > $fy(132));

        // SKU: base entre o fim das barras do rodapé (1029) e a linha do quadro (1072),
        // com espaço para a letra de 14 pt (~13 px acima da base, ~4 px abaixo).
        [, $linha, , $base, $limite] = EtiquetaMelhorEnvioComMarca::ITENS['jadlog'];
        $this->assertNull($linha);
        $this->assertSame($base, $limite, 'uma linha só');
        $this->assertGreaterThan($fy(1029 + 13 + 4), $base);
        $this->assertLessThan($fy(1072 - 4 - 4), $base);

        // Duas linhas de 12 pt quando não cabe numa: a 1ª com a letra (~11 px)
        // abaixo das barras, a 2ª com a perna (~3 px) acima da linha do quadro.
        [$b1, $b2] = EtiquetaMelhorEnvioComMarca::ITENS['jadlog'][5];
        $this->assertGreaterThan($fy(1029 + 11 + 2), $b1);
        $this->assertGreaterThan($b1 + $fy(11 + 3 + 2), $b2, 'linhas encavaladas');
        $this->assertLessThan($fy(1072 - 3 - 2), $b2);
    }

    /** O texto do SKU nunca passa da largura do quadro: quebra em 2 linhas e termina em "+ N itens". */
    public function test_sku_nunca_sai_do_quadro(): void
    {
        $fpdi = new class('P', 'pt', [577.5, 813]) extends Fpdi
        {
            /** @var list<array{0: float, 1: float, 2: string, 3: float}> */
            public array $textos = [];

            public function Text($x, $y, $txt)
            {
                $this->textos[] = [(float) $x, (float) $y, (string) $txt, $this->GetStringWidth((string) $txt)];
            }
        };
        $fpdi->AddPage();
        [$ix0, , $ix1, $base, , [$b1, $b2]] = EtiquetaMelhorEnvioComMarca::ITENS['jadlog'];
        [$x0, $x1] = [$ix0 * 577.5, $ix1 * 577.5];
        $metodo = new \ReflectionMethod(EtiquetaMelhorEnvioComMarca::class, 'itensNoRodape');
        $escrever = function (array $itens) use ($fpdi, $metodo, $x0, $x1, $base, $b1, $b2) {
            $fpdi->textos = [];
            $metodo->invoke(new EtiquetaMelhorEnvioComMarca, $fpdi, $itens, $x0, $x1, $base * 813, [$b1 * 813, $b2 * 813]);

            return $fpdi->textos;
        };
        $dentro = function (array $textos) use ($x0, $x1) {
            foreach ($textos as [$x, , $txt, $largura]) {
                $this->assertGreaterThanOrEqual($x0 - 0.01, $x, $txt);
                $this->assertLessThanOrEqual($x1 + 0.01, $x + $largura, "passou da borda: {$txt}");
            }
        };

        // 1 item: SKU à esquerda e Qtd à direita, na linha única.
        $um = $escrever([['sku' => 'KZ-TRAV-CERV-01', 'quantidade' => 1]]);
        $dentro($um);
        $this->assertSame([$base * 813], array_values(array_unique(array_map(fn ($t) => $t[1], $um))));

        // 1 item com SKU enorme: cortado com "...".
        $enorme = $escrever([['sku' => str_repeat('MUITO-LONGO-', 20), 'quantidade' => 3]]);
        $dentro($enorme);
        $this->assertStringEndsWith('...', collect($enorme)->first(fn ($t) => str_starts_with($t[2], 'MUITO'))[2]);

        // 3 itens: duas linhas.
        $tres = $escrever([
            ['sku' => 'KZ-TRAV-CERV-01', 'quantidade' => 1],
            ['sku' => 'KZ-ARVORE-NATAL-120', 'quantidade' => 1],
            ['sku' => 'FK-COMP-PF36V-2B', 'quantidade' => 1],
        ]);
        $dentro($tres);
        $this->assertCount(2, $tres);

        // Muitos itens: duas linhas e a última termina em "+ N itens (ver pedido)".
        $itens = array_map(fn ($i) => ['sku' => 'KZ-PRODUTO-'.$i, 'quantidade' => $i], range(1, 12));
        $muitos = $escrever($itens);
        $dentro($muitos);
        $this->assertCount(2, $muitos);
        $this->assertMatchesRegularExpression('/\+ \d+ itens \(ver pedido\)$/', $muitos[1][2]);
        preg_match('/\+ (\d+) itens/', $muitos[1][2], $m);
        $mostrados = substr_count($muitos[0][2].$muitos[1][2], 'SKU: ');
        $this->assertSame(12, $mostrados + (int) $m[1], 'conta dos itens que ficaram de fora');
    }

    public function test_sku_embaixo_do_codigo_e_desligavel(): void
    {
        $this->semOcr();
        $original = $this->pdf();
        $servico = app(EtiquetaMelhorEnvioComMarca::class);
        $sem = $servico->aplicar($original, 'Jadlog .Package', 1);
        $um = $servico->aplicar($original, 'Jadlog .Package', 1, null, [['sku' => 'KZ-TESTE-01', 'quantidade' => 1]]);
        $varios = $servico->aplicar($original, 'Jadlog .Package', 1, null, [
            ['sku' => 'KZ-TESTE-01', 'quantidade' => 2],
            ['sku' => null, 'quantidade' => 1],
            ['sku' => str_repeat('MUITO-LONGO-', 8), 'quantidade' => 1],
        ]);

        $this->assertGreaterThan(strlen($sem), strlen($um));
        $this->assertGreaterThan(strlen($sem), strlen($varios));
        $this->assertStringContainsString('577.50 813.00', $varios);

        config(['melhorenvio.etiqueta.sku' => 'desligado']);
        $this->assertFalse(EtiquetaMelhorEnvioComMarca::escreveSku());
        $desligado = $servico->aplicar($original, 'Jadlog .Package', 1, null, [['sku' => 'KZ-TESTE-01', 'quantidade' => 1]]);
        $this->assertSame(strlen($sem), strlen($desligado));

        config(['melhorenvio.etiqueta.sku' => 'abaixo_codigo']);
        $this->assertTrue(EtiquetaMelhorEnvioComMarca::escreveSku());
    }

    public function test_quadro_pequeno_deixa_o_logo_da_jadlog_onde_esta(): void
    {
        $this->semOcr();
        config(['melhorenvio.etiqueta.logo_loja.jadlog' => 'quadro_pequeno']);

        $layout = EtiquetaMelhorEnvioComMarca::layoutJadlog();
        $this->assertNull($layout['transportadora']);
        $this->assertSame([EtiquetaMelhorEnvioComMarca::JADLOG['cobrir_pequeno']], $layout['cobrir']);
        $this->assertSame(EtiquetaMelhorEnvioComMarca::JADLOG['logo_pequeno'], $layout['logo']);

        $pequeno = app(EtiquetaMelhorEnvioComMarca::class)->aplicar($this->pdf(), 'Jadlog .Package', 1);
        $this->assertStringContainsString('/DeviceRGB', $pequeno);

        config(['melhorenvio.etiqueta.logo_loja.jadlog' => 'quadro_grande']);
        $troca = app(EtiquetaMelhorEnvioComMarca::class)->aplicar($this->pdf(), 'Jadlog .Package', 1);
        // Na troca de lado entra uma imagem a mais: o logo da Jadlog no quadro pequeno.
        $this->assertSame(substr_count($pequeno, '/Subtype /Image') + 1, substr_count($troca, '/Subtype /Image'));
    }

    public function test_ferrakora_na_jadlog_sai_com_o_logo_dela(): void
    {
        $this->semOcr();
        $novo = app(EtiquetaMelhorEnvioComMarca::class)->aplicar($this->pdf(), 'Jadlog .Package', 1, SiteAtual::FERRAKORA);

        $this->assertStringContainsString('/DeviceGray', $novo);
        $this->assertStringNotContainsString('/DeviceRGB', $novo);
    }

    /** Logo da Jadlog para a térmica: cinza de 1 canal, só preto e branco, nítido. */
    public function test_logo_da_jadlog_e_preto_e_branco(): void
    {
        $arquivo = public_path(EtiquetaMelhorEnvioComMarca::LOGO_JADLOG);
        $this->assertFileExists($arquivo);
        $this->assertSame(0, ord(file_get_contents($arquivo, false, null, 25, 1)), 'PNG tem que ser escala de cinza');

        $img = imagecreatefrompng($arquivo);
        $this->assertGreaterThanOrEqual(600, imagesx($img), 'logo pequeno demais para imprimir nítido');
        for ($y = 0; $y < imagesy($img); $y += 4) {
            for ($x = 0; $x < imagesx($img); $x += 4) {
                $v = imagecolorsforindex($img, imagecolorat($img, $x, $y))['red'];
                $this->assertContains($v, [0, 255], "cinza em {$x},{$y}");
            }
        }
    }
}
