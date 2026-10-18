<?php

namespace Tests\Feature\Fiscal;

use App\Services\NFe\NFeDanfeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-10-17: o DANFE nunca saiu com logo. O serviço passava base64 puro e a
 * sped-da só aceita 'data://text/plain;base64,...' (ou caminho de arquivo):
 * o logo era descartado sem erro. E o PNG transparente vira JPEG com fundo
 * preto, apagando o "Kora" preto do logo da KazaKora.
 */
class NFeDanfeLogoTest extends TestCase
{
    use RefreshDatabase;

    private function logoDataUri(): ?string
    {
        $metodo = new \ReflectionMethod(NFeDanfeService::class, 'logoDataUri');

        return $metodo->invoke(new NFeDanfeService());
    }

    public function test_without_company_logo_uses_the_site_logo_in_the_format_the_library_accepts(): void
    {
        $logo = $this->logoDataUri();

        $this->assertNotNull($logo);
        $this->assertStringStartsWith('data://text/plain;base64,', $logo);
    }

    public function test_transparent_background_becomes_white(): void
    {
        if (! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('Extensão GD ausente.');
        }

        $png = base64_decode(substr($this->logoDataUri(), strlen('data://text/plain;base64,')));
        $imagem = imagecreatefromstring($png);

        // canto superior esquerdo do logo do site é transparente
        $cor = imagecolorsforindex($imagem, imagecolorat($imagem, 0, 0));

        $this->assertSame([255, 255, 255, 0], [$cor['red'], $cor['green'], $cor['blue'], $cor['alpha']]);
    }
}
