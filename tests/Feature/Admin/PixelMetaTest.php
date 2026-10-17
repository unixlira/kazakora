<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\PixelMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Configurações › Pixel da Meta (pedido do Lira 2026-10-16): ID editável no admin. */
class PixelMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.meta_ads.pixel_id' => '2186107885537817']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_tela_mostra_o_pixel_do_env_enquanto_nada_foi_salvo(): void
    {
        $this->actingAs($this->admin())->get('/admin/pixel-meta')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/PixelMeta/Index', false)
                ->where('pixelId', '2186107885537817')
                ->where('origem', 'env'));
    }

    public function test_salvar_troca_o_pixel_do_site_sem_mexer_no_codigo(): void
    {
        $this->actingAs($this->admin())->put('/admin/pixel-meta', ['pixel_id' => '1111222233334444'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('1111222233334444', PixelMeta::id());
        $this->assertSame('admin', PixelMeta::origem());

        $this->get('/politica-de-entrega')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cookies.pixelMeta', '1111222233334444'));
    }

    public function test_apagar_o_id_volta_ao_do_env(): void
    {
        PixelMeta::definir('1111222233334444');

        $this->actingAs($this->admin())->put('/admin/pixel-meta', ['pixel_id' => ''])->assertRedirect();

        $this->assertSame('2186107885537817', PixelMeta::id());
        $this->assertSame('env', PixelMeta::origem());
    }

    public function test_id_com_letras_e_recusado(): void
    {
        $this->actingAs($this->admin())->put('/admin/pixel-meta', ['pixel_id' => 'abc123'])
            ->assertSessionHasErrors('pixel_id');

        $this->assertSame('2186107885537817', PixelMeta::id());
    }

    public function test_quem_nao_e_admin_nao_altera(): void
    {
        $operador = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($operador)->put('/admin/pixel-meta', ['pixel_id' => '1111222233334444']);

        $this->assertSame('2186107885537817', PixelMeta::id());
    }
}
