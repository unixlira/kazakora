<?php

namespace Tests\Feature\MelhorEnvio;

use App\Modules\Marketplace\Support\EtiquetaMelhorEnvioComMarca;
use App\Support\SiteAtual;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

/** Logo da KazaKora no lugar do Melhor Envio (pedido do Lira 09/11, #3978). */
class EtiquetaComMarcaTest extends TestCase
{
    private function pdf(): string
    {
        $pdf = new Fpdi('P', 'pt', [577.5, 813]);
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Text(50, 50, 'etiqueta');

        return $pdf->Output('S');
    }

    /**
     * Etiqueta térmica (pedido do Lira 21/11/2026): o símbolo da KazaKora é
     * só o desenho da casa, sem fundo. O favicon com o quadrado laranja saía
     * como um bloco preto na impressora.
     */
    public function test_simbolo_da_kazakora_nao_tem_fundo(): void
    {
        $arquivo = public_path(EtiquetaMelhorEnvioComMarca::SIMBOLO);
        $this->assertFileExists($arquivo);
        $img = imagecreatefrompng($arquivo);
        [$w, $h] = [imagesx($img), imagesy($img)];
        $this->assertGreaterThanOrEqual(300, $w, 'símbolo pequeno demais para imprimir nítido');

        $opacos = 0;
        $total = 0;
        for ($y = 0; $y < $h; $y += 2) {
            for ($x = 0; $x < $w; $x += 2) {
                $alfa = (imagecolorat($img, $x, $y) >> 24) & 0x7F; // 127 = transparente
                $opacos += $alfa < 64 ? 1 : 0;
                $total++;
            }
        }
        // Só as linhas: a maior parte da imagem é transparente.
        $this->assertLessThan(0.5, $opacos / $total);
        foreach ([[0, $h - 1], [$w - 1, $h - 1]] as [$x, $y]) {
            $this->assertSame(127, (imagecolorat($img, $x, $y) >> 24) & 0x7F, 'canto de baixo com fundo');
        }
    }

    /** Térmica: o símbolo e a palavra KazaKora da etiqueta são só preto (nada colorido que suma). */
    public function test_simbolo_e_palavra_da_etiqueta_sao_pretos(): void
    {
        foreach ([EtiquetaMelhorEnvioComMarca::SIMBOLO, EtiquetaMelhorEnvioComMarca::LOGO] as $arquivo) {
            $img = imagecreatefrompng(public_path($arquivo));
            for ($y = 0; $y < imagesy($img); $y += 3) {
                for ($x = 0; $x < imagesx($img); $x += 3) {
                    $c = imagecolorat($img, $x, $y);
                    if ((($c >> 24) & 0x7F) < 100) {
                        $this->assertSame(0, $c & 0xFFFFFF, "{$arquivo}: pixel colorido em {$x},{$y}");
                    }
                }
            }
        }
    }

    public function test_kazakora_sai_com_simbolo_e_palavra_ferrakora_so_com_o_logo(): void
    {
        $original = $this->pdf();
        $kaza = app(EtiquetaMelhorEnvioComMarca::class)->aplicar($original, 'JeT Standard', 1);
        $ferra = app(EtiquetaMelhorEnvioComMarca::class)->aplicar($original, 'JeT Standard', 1, SiteAtual::FERRAKORA);

        // PNG com transparência vira imagem + máscara no PDF: a KazaKora tem um desenho a mais.
        $this->assertGreaterThan(substr_count($ferra, '/Subtype /Image'), substr_count($kaza, '/Subtype /Image'));
        $this->assertStringContainsString('/SMask', $kaza);
    }

    public function test_jet_ganha_o_logo_e_continua_uma_pagina_do_mesmo_tamanho(): void
    {
        $original = $this->pdf();
        $novo = app(EtiquetaMelhorEnvioComMarca::class)->aplicar($original, 'JeT Standard', 1);

        $this->assertStringStartsWith('%PDF', $novo);
        $this->assertNotSame($original, $novo);
        $this->assertStringContainsString('/Image', $novo);
        $this->assertStringContainsString('577.50 813.00', $novo);
    }

    public function test_correios_ganha_o_logo_e_a_linha_do_remetente_sem_telefone(): void
    {
        $this->assertSame('correios', EtiquetaMelhorEnvioComMarca::modelo('Correios SEDEX'));
        $this->assertSame('correios', EtiquetaMelhorEnvioComMarca::modelo('Correios PAC'));
        $this->assertSame('correios', EtiquetaMelhorEnvioComMarca::modelo('SEDEX'));
        $this->assertNull(EtiquetaMelhorEnvioComMarca::modelo('LATAM Cargo'));

        $original = $this->pdf();
        $novo = app(EtiquetaMelhorEnvioComMarca::class)->aplicar($original, 'Correios SEDEX', 1);

        $this->assertNotSame($original, $novo);
        $this->assertStringContainsString('/Image', $novo);
        $this->assertStringContainsString('577.50 813.00', $novo);
        // Telefone de atendimento de cada loja, nunca o do cadastro da empresa.
        $this->assertSame('KazaKora, Tel: (11) 96572-3990', EtiquetaMelhorEnvioComMarca::remetente(null));
        $this->assertSame('FerraKora, Tel: (11) 92720-6026', EtiquetaMelhorEnvioComMarca::remetente(SiteAtual::FERRAKORA));
        $this->assertSame('11965723990', EtiquetaMelhorEnvioComMarca::telefone(SiteAtual::KAZAKORA));
        $this->assertSame('11927206026', EtiquetaMelhorEnvioComMarca::telefone(SiteAtual::FERRAKORA));
    }

    public function test_correios_lista_sku_e_quantidade_abaixo_do_remetente(): void
    {
        $original = $this->pdf();
        $sem = app(EtiquetaMelhorEnvioComMarca::class)->aplicar($original, 'Correios SEDEX', 1);
        $com = app(EtiquetaMelhorEnvioComMarca::class)->aplicar($original, 'Correios SEDEX', 1, null, [
            ['sku' => 'TRAV-CERV-01', 'quantidade' => 2],
            ['sku' => null, 'quantidade' => 1],
        ]);

        // Stream do PDF sem compressão nos testes do FPDF: o texto aparece.
        $this->assertGreaterThan(strlen($sem), strlen($com));
        $this->assertStringContainsString('577.50 813.00', $com);
        $this->assertArrayHasKey('correios', EtiquetaMelhorEnvioComMarca::ITENS);

        // J&T: SKU e quantidade no rodapé, abaixo do logo (#4162).
        $jetSem = app(EtiquetaMelhorEnvioComMarca::class)->aplicar($original, 'JeT Standard', 1);
        $jetCom = app(EtiquetaMelhorEnvioComMarca::class)->aplicar($original, 'JeT Standard', 1, null, [['sku' => 'KIC-PF36V-2B-SR', 'quantidade' => 1]]);
        $this->assertGreaterThan(strlen($jetSem), strlen($jetCom));
    }

    public function test_transportadora_sem_posicao_medida_sai_como_veio(): void
    {
        $original = $this->pdf();

        $this->assertSame($original, app(EtiquetaMelhorEnvioComMarca::class)->aplicar($original, 'LATAM Cargo', 1));
        $this->assertSame('jet', EtiquetaMelhorEnvioComMarca::modelo('J&T Express'));
    }

    public function test_ferrakora_sai_com_o_logo_preto_e_branco_dela(): void
    {
        $this->assertSame('images/marca/logo-ferrakora-etiqueta-v2.png', EtiquetaMelhorEnvioComMarca::logo(SiteAtual::FERRAKORA));
        $this->assertSame('images/marca/logo-kazakora-etiqueta.png', EtiquetaMelhorEnvioComMarca::logo(SiteAtual::KAZAKORA));
        $this->assertSame('images/marca/logo-kazakora-etiqueta.png', EtiquetaMelhorEnvioComMarca::logo(null));

        // O PNG da térmica é cinza de 1 canal (preto no branco, sem cor nem
        // transparência): no PDF não tem /DeviceRGB, o da KazaKora tem.
        $png = public_path(EtiquetaMelhorEnvioComMarca::logo(SiteAtual::FERRAKORA));
        $this->assertFileExists($png);
        $this->assertSame(0, ord(file_get_contents($png, false, null, 25, 1)), 'PNG tem que ser escala de cinza');

        $original = $this->pdf();
        $ferra = app(EtiquetaMelhorEnvioComMarca::class)->aplicar($original, 'JeT Standard', 1, SiteAtual::FERRAKORA);
        $kaza = app(EtiquetaMelhorEnvioComMarca::class)->aplicar($original, 'JeT Standard', 1, SiteAtual::KAZAKORA);

        $this->assertStringContainsString('/Image', $ferra);
        $this->assertStringContainsString('/DeviceGray', $ferra);
        $this->assertStringNotContainsString('/DeviceRGB', $ferra);
        $this->assertStringContainsString('/DeviceRGB', $kaza);
        $this->assertStringContainsString('577.50 813.00', $ferra);
    }
}
