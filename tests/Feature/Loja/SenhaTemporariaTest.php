<?php

namespace Tests\Feature\Loja;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Conta com senha temporária: a loja pede a troca (pedido 2026-10-10). */
class SenhaTemporariaTest extends TestCase
{
    use RefreshDatabase;

    public function test_aviso_aparece_e_some_depois_de_trocar(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $user->forceFill(['deve_trocar_senha' => true])->save();

        $this->actingAs($user)->get('/rastreio')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.deve_trocar_senha', true));

        $this->actingAs($user)->put('/perfil/senha', ['password' => 'MinhaSenha123', 'password_confirmation' => 'MinhaSenha123'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->deve_trocar_senha);
    }
}
