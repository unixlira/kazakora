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
        $this->assertNull(EtiquetaMelhorEnvioComMarca::modelo('Jadlog .Package'));

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
    }

    public function test_transportadora_sem_posicao_medida_sai_como_veio(): void
    {
        $original = $this->pdf();

        $this->assertSame($original, app(EtiquetaMelhorEnvioComMarca::class)->aplicar($original, 'Jadlog .Package', 1));
        $this->assertSame('jet', EtiquetaMelhorEnvioComMarca::modelo('J&T Express'));
    }

    public function test_ferrakora_sai_com_o_logo_preto_e_branco_dela(): void
    {
        $this->assertSame('images/marca/logo-ferrakora-etiqueta.png', EtiquetaMelhorEnvioComMarca::logo(SiteAtual::FERRAKORA));
        $this->assertSame('images/marca/logo-kazakora-fiel.png', EtiquetaMelhorEnvioComMarca::logo(SiteAtual::KAZAKORA));
        $this->assertSame('images/marca/logo-kazakora-fiel.png', EtiquetaMelhorEnvioComMarca::logo(null));

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
