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
