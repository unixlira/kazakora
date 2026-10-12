<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Tela /admin/radar (2026-10-12): só a estrutura, conteúdo em construção.
 */
class RadarPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_radar_page_opens_for_admin(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->assertSame(url('/admin/radar'), route('admin.radar.index'));

        $this->actingAs($admin)
            ->get('/admin/radar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Radar/Index', false));
    }

    public function test_customer_cannot_open_radar_page(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($customer)->get('/admin/radar')->assertForbidden();
    }
}
