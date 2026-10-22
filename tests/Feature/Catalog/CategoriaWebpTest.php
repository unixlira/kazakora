<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CategoriaWebpTest extends TestCase
{
    use RefreshDatabase;

    public function test_sem_webp_usa_o_original(): void
    {
        Storage::fake('public');
        $caminho = UploadedFile::fake()->image('casa.jpg', 360, 360)->store('categories', 'public');
        $categoria = Category::factory()->create(['image_path' => $caminho]);

        $this->assertStringEndsWith($caminho, $categoria->image_url);
    }

    public function test_comando_gera_webp_ao_lado_e_a_categoria_passa_a_usar(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD sem WebP neste ambiente.');
        }
        Storage::fake('public');
        $caminho = UploadedFile::fake()->image('pet.jpg', 720, 720)->store('categories', 'public');
        $categoria = Category::factory()->create(['image_path' => $caminho]);

        $this->artisan('categorias:gerar-webp')->assertSuccessful();

        $webp = preg_replace('/\.\w+$/', '', $caminho).'.webp';
        Storage::disk('public')->assertExists($caminho);
        Storage::disk('public')->assertExists($webp);
        $this->assertSame(480, getimagesize(Storage::disk('public')->path($webp))[0]);
        $this->assertStringEndsWith($webp, $categoria->fresh()->image_url);
    }

    public function test_sem_imagem_continua_nulo(): void
    {
        $this->assertNull(Category::factory()->create(['image_path' => null])->image_url);
    }
}
