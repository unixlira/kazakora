<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Radar (2026-10-12): grupo próprio no menu, uma tela por canal
 * (Shopee e Mercado Livre), conteúdo ainda em construção.
 */
class RadarPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_radar_root_redirects_to_shopee(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->assertSame(url('/admin/radar'), route('admin.radar.index'));

        $this->actingAs($admin)->get('/admin/radar')->assertRedirect('/admin/radar/shopee');
    }

    public function test_radar_page_opens_for_each_channel(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        foreach (['shopee' => 'Shopee', 'mercado-livre' => 'Mercado Livre'] as $slug => $nome) {
            $this->assertSame(url("/admin/radar/{$slug}"), route('admin.radar.canal', $slug));

            $this->actingAs($admin)
                ->get("/admin/radar/{$slug}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Admin/Radar/Index', false)
                    ->where('canal.slug', $slug)
                    ->where('canal.nome', $nome));
        }
    }

    public function test_unknown_channel_is_not_found(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/radar/tiktok')->assertNotFound();
    }

    public function test_customer_cannot_open_radar_page(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($customer)->get('/admin/radar')->assertForbidden();
        $this->actingAs($customer)->get('/admin/radar/shopee')->assertForbidden();
    }
}
