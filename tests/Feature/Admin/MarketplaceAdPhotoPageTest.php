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

    public function test_ferramenta_segue_sequencia_desejo_objecao_cta(): void
    {
        foreach (['shopee' => 'H', 'mercado_livre' => 'J'] as $marketplace => $chaveCta) {
            $this->actingAs($this->admin())->post('/admin/marketplaces/fotos-anuncio', [
                'marketplace' => $marketplace,
                'product_name' => 'Parafusadeira 21V',
                'category_hint' => 'Ferramentas e Automotivo',
                'description' => 'Parafusadeira com 2 baterias.',
            ])->assertRedirect();

            $brief = MarketplaceAdPhotoBrief::query()->where('marketplace', $marketplace)->firstOrFail();
            $etapas = collect($brief->matrix)->pluck('stage')->all();

            $this->assertSame('desejo', $etapas[0]);
            $this->assertSame('cta', end($etapas));
            $this->assertSame($chaveCta, collect($brief->matrix)->last()['key']);
            $this->assertSame($etapas, collect($etapas)->sortBy(fn ($e) => array_search($e, ['desejo', 'objecao', 'cta']))->values()->all());
            $this->assertStringContainsString('Sequência psicológica', $brief->matrix[0]['prompt']);
        }
    }

    public function test_produto_fora_de_ferramenta_mantem_a_matriz_original(): void
    {
        $this->actingAs($this->admin())->post('/admin/marketplaces/fotos-anuncio', [
            'marketplace' => 'shopee',
            'product_name' => 'Organizador de gaveta',
            'category_hint' => 'Casa e Decoração',
            'description' => 'Organizador de plástico.',
        ])->assertRedirect();

        $matrix = MarketplaceAdPhotoBrief::query()->firstOrFail()->matrix;

        $this->assertArrayNotHasKey('stage', $matrix[0]);
        $this->assertSame('H', $matrix[7]['key']);
    }
}
