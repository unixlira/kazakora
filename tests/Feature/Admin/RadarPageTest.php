<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Radar de Oportunidades (2026-10-12): uma tela só no grupo Oportunidades
 * do menu, sem separar por marketplace, conteúdo ainda em construção.
 */
class RadarPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_radar_page_opens(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->assertSame(url('/admin/radar'), route('admin.radar.index'));

        $this->actingAs($admin)
            ->get('/admin/radar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Radar/Index', false));
    }

    public function test_radar_has_no_page_per_channel(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/radar/shopee')->assertNotFound();
        $this->actingAs($admin)->get('/admin/radar/mercado-livre')->assertNotFound();
    }

    public function test_customer_cannot_open_radar_page(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($customer)->get('/admin/radar')->assertForbidden();
    }
}
