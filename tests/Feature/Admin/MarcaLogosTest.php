<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Marca;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Logos e favicon (pedido 2026-10-10): upload com validação e volta ao padrão. */
class MarcaLogosTest extends TestCase
{
    use RefreshDatabase;

    public function test_padrao_sobe_logos_valida_e_restaura(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->assertSame('/images/marca/logo-nav.png', Marca::url('logo_nav'));
        $this->get('/')->assertSee('/images/marca/favicon-32.png', false);

        $this->actingAs($admin)->post('/admin/banners/marca', [
            'logo_nav' => UploadedFile::fake()->image('logo.png', 520, 92),
            'favicon' => UploadedFile::fake()->image('fav.png', 512, 512),
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString('/storage/marca/', Marca::url('logo_nav'));
        Storage::disk('public')->assertExists(Marca::caminho('favicon'));

        $this->post('/admin/banners/marca', ['logo_rodape' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors(['logo_rodape' => 'A logo do rodapé precisa ser PNG, WebP ou JPG.']);
        $this->post('/admin/banners/marca', ['favicon' => UploadedFile::fake()->image('fav.png', 300, 100)])
            ->assertSessionHasErrors('favicon');

        $antigo = Marca::caminho('logo_nav');
        $this->post('/admin/banners/marca', ['restaurar' => ['logo_nav']])->assertSessionHasNoErrors();
        $this->assertSame('/images/marca/logo-nav.png', Marca::url('logo_nav'));
        Storage::disk('public')->assertMissing($antigo);
    }
}
