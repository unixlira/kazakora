<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Models\AdminClique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Contador de cliques do painel (pedido do Lira 2026-10-25). */
class CliquesAdminTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function enviar(User $user, array $cliques, string $agente = self::NAVEGADOR)
    {
        return $this->actingAs($user)
            ->withHeaders(['User-Agent' => $agente])
            ->postJson('/admin/cliques', ['cliques' => $cliques]);
    }

    public function test_grava_e_soma_na_mesma_linha_do_dia(): void
    {
        $admin = $this->admin();
        $clique = ['elemento' => 'Menu: Pedidos', 'tipo' => 'menu', 'pagina' => '/admin/pedidos/123?aba=x', 'destino' => '/admin/pedidos', 'quantos' => 2];

        $this->enviar($admin, [$clique])->assertNoContent();
        $this->enviar($admin, [[...$clique, 'pagina' => '/admin/pedidos/456', 'quantos' => 3]])->assertNoContent();

        $linha = AdminClique::query()->sole();
        $this->assertSame('Menu: Pedidos', $linha->elemento);
        $this->assertSame('/admin/pedidos/{id}', $linha->pagina);
        $this->assertSame(5, $linha->contagem);
        $this->assertSame($admin->id, $linha->user_id);
        $this->assertSame(now('America/Sao_Paulo')->toDateString(), $linha->dia->toDateString());
    }

    public function test_pagina_diferente_e_pessoa_diferente_viram_linhas_separadas(): void
    {
        $clique = ['elemento' => 'Pedidos: buscar', 'tipo' => 'botao', 'pagina' => '/admin/pedidos', 'quantos' => 1];
        $this->enviar($this->admin(), [$clique, [...$clique, 'pagina' => '/admin/produtos']]);
        $this->enviar($this->admin(), [$clique]);

        $this->assertSame(3, AdminClique::query()->count());
        $this->assertSame(3, (int) AdminClique::query()->sum('contagem'));
    }

    public function test_robo_e_cliente_nao_gravam(): void
    {
        $clique = ['elemento' => 'Menu: Pedidos', 'tipo' => 'menu', 'pagina' => '/admin/pedidos', 'quantos' => 1];

        $this->assertNotSame(204, $this->postJson('/admin/cliques', ['cliques' => [$clique]])->status());
        $this->enviar($this->admin(), [$clique], 'curl/8.0')->assertNoContent();
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->assertNotSame(204, $this->enviar($cliente, [$clique])->status());

        $this->assertSame(0, AdminClique::query()->count());
    }

    public function test_recusa_campo_fora_da_lista_e_tipo_invalido(): void
    {
        $this->enviar($this->admin(), [['elemento' => 'X', 'tipo' => 'campo', 'pagina' => '/admin', 'quantos' => 1]])
            ->assertStatus(422);
        $this->enviar($this->admin(), [])->assertStatus(422);

        $this->assertSame(0, AdminClique::query()->count());
    }

    public function test_tela_abre_com_ranking_e_filtros(): void
    {
        $admin = $this->admin();
        AdminClique::somar($admin->id, 'Menu: Pedidos', 'menu', '/admin/pedidos', '/admin/pedidos', 7);
        AdminClique::somar($admin->id, 'Menu: Produtos', 'menu', '/admin/produtos', '/admin/produtos', 2);
        AdminClique::somar($admin->id, 'Menu: Cupons', 'menu', '/admin', '/admin/cupons', 1, now()->subDays(20));

        $this->actingAs($admin)->get('/admin/relatorios/cliques')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Cliques/Index', false)
                ->where('filtros.periodo', '7')
                ->where('total', 9)
                ->where('ranking.0.elemento', 'Menu: Pedidos')
                ->where('ranking.0.total', 7)
                ->has('ranking', 2)
                ->where('semCliqueNoPeriodo.0.elemento', 'Menu: Cupons'));

        $this->actingAs($admin)->get('/admin/relatorios/cliques?periodo=30&pagina=/admin/produtos')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('total', 2)
                ->has('ranking', 1));
    }

    public function test_tela_e_so_de_admin(): void
    {
        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($gerente)->get('/admin/relatorios/cliques')->assertForbidden();
    }
}
