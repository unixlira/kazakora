<?php

namespace Tests\Feature\MelhorEnvio;

use App\Modules\Marketplace\Support\EtiquetaMelhorEnvioComMarca;
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
}
