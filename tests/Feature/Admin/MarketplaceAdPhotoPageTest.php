<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceAdPhotoBrief;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * BUG REAL 2026-09-29: "Fotos de Anúncio" (gerador de fotos/criativos) dava
 * 404. Feita direto no servidor em 28-30/08 e nunca commitada — um deploy
 * apagou controller, telas e rotas; a tabela e as imagens continuavam lá.
 */
class MarketplaceAdPhotoPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_index_and_create_pages_open(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/marketplaces/fotos-anuncio')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Marketplaces/AdPhotos/Index', false));

        $this->actingAs($admin)->get('/admin/marketplaces/fotos-anuncio/criar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Marketplaces/AdPhotos/Create', false));
    }

    public function test_generate_creates_a_brief_and_shows_it(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/admin/marketplaces/fotos-anuncio', [
            'marketplace' => 'shopee',
            'product_name' => 'Bicicleta Ergométrica Spinning',
            'description' => 'Bike spinning profissional, suporta 150 kg, cor preto e vermelho.',
        ]);

        $brief = MarketplaceAdPhotoBrief::query()->firstOrFail();
        $response->assertRedirect('/admin/marketplaces/fotos-anuncio/'.$brief->uuid);

        $this->actingAs($admin)->get('/admin/marketplaces/fotos-anuncio/'.$brief->uuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Marketplaces/AdPhotos/Show', false));

        $this->actingAs($admin)->patch('/admin/marketplaces/fotos-anuncio/'.$brief->uuid.'/aprovar')->assertRedirect();
        $this->assertSame(MarketplaceAdPhotoBrief::APPROVAL_APPROVED, $brief->fresh()->approval_status);
    }
}
