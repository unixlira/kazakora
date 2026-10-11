<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BUG REAL 2026-10-10: a edição do banner mandava a imagem mobile num PUT
 * multipart (que o PHP não lê) e ela nunca era gravada. Agora vai em POST
 * com _method=put.
 */
class BannerMobileUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_edicao_grava_a_imagem_mobile(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $banner = Banner::create(['title' => 'Promo', 'image_path' => 'banners/desk.png', 'sort_order' => 1, 'is_active' => true]);

        $this->actingAs($admin)->post("/admin/banners/{$banner->id}", [
            '_method' => 'put',
            'title' => 'Promo',
            'is_active' => true,
            'image_mobile' => UploadedFile::fake()->create('mobile.png', 50, 'image/png'),
        ])->assertRedirect('/admin/banners');

        $banner->refresh();
        $this->assertNotNull($banner->image_path_mobile);
        Storage::disk('public')->assertExists($banner->image_path_mobile);
        $this->assertSame('banners/desk.png', $banner->image_path);
    }
}
