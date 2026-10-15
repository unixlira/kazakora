<?php

namespace Tests\Feature\Admin;

use App\Modules\Catalog\Support\BannerMobileOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BannerMobileOtimizadoTest extends TestCase
{
    public function test_gera_webp_e_larguras_sem_ampliar(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD sem WebP neste ambiente.');
        }
        Storage::fake('public');
        $caminho = UploadedFile::fake()->image('m.png', 1400, 800)->store('banners', 'public');

        $novo = BannerMobileOptimizer::otimizar($caminho);

        $this->assertStringEndsWith('.webp', $novo);
        Storage::disk('public')->assertExists($novo);
        Storage::disk('public')->assertMissing($caminho);
        $this->assertSame(1080, getimagesize(Storage::disk('public')->path($novo))[0]);
        foreach ([480, 828] as $largura) {
            Storage::disk('public')->assertExists(BannerMobileOptimizer::variante($novo, $largura));
        }
        Storage::disk('public')->assertMissing(BannerMobileOptimizer::variante($novo, 1080));
        $this->assertStringContainsString('480w', BannerMobileOptimizer::srcset($novo));
    }

    public function test_imagem_pequena_nao_e_ampliada(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD sem WebP neste ambiente.');
        }
        Storage::fake('public');
        $caminho = UploadedFile::fake()->image('m.png', 600, 340)->store('banners', 'public');

        $novo = BannerMobileOptimizer::otimizar($caminho);

        $this->assertSame(600, getimagesize(Storage::disk('public')->path($novo))[0]);
        Storage::disk('public')->assertMissing(BannerMobileOptimizer::variante($novo, 828));
    }

    public function test_arquivo_que_nao_e_imagem_fica_como_esta(): void
    {
        Storage::fake('public');
        $caminho = UploadedFile::fake()->create('x.png', 10, 'image/png')->store('banners', 'public');

        $this->assertNull(BannerMobileOptimizer::otimizar($caminho));
        Storage::disk('public')->assertExists($caminho);
    }
}
