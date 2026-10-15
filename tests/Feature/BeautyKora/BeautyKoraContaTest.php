<?php

namespace Tests\Feature\BeautyKora;

use App\Models\ApiPartner;
use App\Models\User;
use App\Modules\BeautyKora\Models\BeautyKoraCliente;
use App\Modules\BeautyKora\Models\BeautyKoraPedido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Login e pedidos do site Beauty Kora no KazaKora (pedido 2026-10-12):
 * /api/beautykora/* substitui o PocketBase do Horizons.
 */
class BeautyKoraContaTest extends TestCase
{
    use RefreshDatabase;

    private function cadastrar(string $email = 'maria@exemplo.com'): string
    {
        return $this->postJson('/api/beautykora/cadastro', ['name' => 'Maria', 'email' => $email, 'password' => 'segredo123'])
            ->assertCreated()
            ->assertJsonPath('user.email', $email)
            ->json('token');
    }

    public function test_cadastro_login_e_eu(): void
    {
        $this->cadastrar();

        $this->postJson('/api/beautykora/login', ['email' => 'MARIA@exemplo.com', 'password' => 'errada00'])
            ->assertStatus(422);

        $token = $this->postJson('/api/beautykora/login', ['email' => 'MARIA@exemplo.com', 'password' => 'segredo123'])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/beautykora/eu')
            ->assertOk()->assertJsonPath('user.name', 'Maria');

        $this->assertDatabaseMissing('users', ['email' => 'maria@exemplo.com']);
    }

    public function test_navegar_no_catalogo_nao_gasta_o_limite_do_login(): void
    {
        $this->cadastrar();

        // Antes de 2026-10-15 o login (10/min) contava no mesmo balde do catálogo.
        for ($i = 0; $i < 12; $i++) {
            $this->getJson('/api/beautykora/produtos');
        }

        $this->postJson('/api/beautykora/login', ['email' => 'maria@exemplo.com', 'password' => 'segredo123'])
            ->assertOk();
    }

    public function test_email_repetido_e_senha_curta_sao_recusados(): void
    {
        $this->cadastrar();

        $this->postJson('/api/beautykora/cadastro', ['name' => 'Outra', 'email' => 'maria@exemplo.com', 'password' => 'segredo123'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/beautykora/cadastro', ['name' => 'Outra', 'email' => 'outra@exemplo.com', 'password' => '123'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_sem_token_ou_com_token_de_parceiro_nao_entra(): void
    {
        $this->getJson('/api/beautykora/eu')->assertUnauthorized();

        $parceiro = ApiPartner::create(['name' => 'Parceiro', 'slug' => 'parceiro-bk', 'abilities' => [], 'rate_limit_per_minute' => 60, 'is_active' => true]);
        $tokenParceiro = $parceiro->createToken('teste')->plainTextToken;
        $this->withToken($tokenParceiro)->getJson('/api/beautykora/pedidos')->assertUnauthorized();
    }

    public function test_sair_invalida_o_token(): void
    {
        $token = $this->cadastrar();

        $this->withToken($token)->postJson('/api/beautykora/sair')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/beautykora/eu')->assertUnauthorized();
    }

    public function test_endereco_e_pedidos_so_do_proprio_cliente(): void
    {
        $token = $this->cadastrar();

        $enderecoId = $this->withToken($token)->putJson('/api/beautykora/endereco', [
            'cep' => '01001-000', 'logradouro' => 'Praça da Sé', 'numero' => '1', 'bairro' => 'Sé', 'cidade' => 'São Paulo', 'estado' => 'sp',
        ])->assertOk()->assertJsonPath('address.estado', 'SP')->json('address.id');

        $pedidos = $this->withToken($token)->postJson('/api/beautykora/pedidos', [
            'address_id' => (int) $enderecoId,
            'items' => [
                ['product_id' => 'prod_1', 'product_name' => 'Sérum Centella', 'quantity' => 2, 'valor_pago' => 28000],
                ['product_id' => 'prod_2', 'product_name' => 'Protetor', 'quantity' => 1, 'valor_pago' => 9900],
            ],
        ])->assertCreated()->json('orders');

        $this->assertCount(2, $pedidos);
        $this->assertSame('pending', $pedidos[0]['status']);
        $this->assertSame('São Paulo', BeautyKoraPedido::query()->first()->endereco['cidade']);

        $this->withToken($token)->getJson('/api/beautykora/pedidos')->assertOk()->assertJsonCount(2, 'orders');
        $this->withToken($token)->getJson('/api/beautykora/pedidos/'.$pedidos[0]['id'])
            ->assertOk()->assertJsonPath('address.cidade', 'São Paulo');

        // Outra cliente não vê o pedido nem o endereço da Maria.
        $this->app['auth']->forgetGuards();
        $outra = $this->cadastrar('outra@exemplo.com');
        $this->withToken($outra)->getJson('/api/beautykora/pedidos')->assertOk()->assertJsonCount(0, 'orders');
        $this->withToken($outra)->getJson('/api/beautykora/pedidos/'.$pedidos[0]['id'])->assertNotFound();
        $this->withToken($outra)->deleteJson('/api/beautykora/endereco/'.$enderecoId)->assertNotFound();
    }

    public function test_admin_ve_e_muda_status_dos_pedidos(): void
    {
        $cliente = BeautyKoraCliente::query()->create(['name' => 'Ana', 'email' => 'ana@exemplo.com', 'password' => 'segredo123']);
        $pedido = BeautyKoraPedido::query()->create([
            'cliente_id' => $cliente->id, 'produto_id' => 'p1', 'produto_nome' => 'Sérum', 'quantidade' => 1, 'valor_pago_centavos' => 14000,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/pedidos-beautykora')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/PedidosBeautyKora/Index', false)
                ->where('totais.itens', 1)
                ->where('pedidos.data.0.cliente', 'Ana'));

        $this->actingAs($admin)->patch("/admin/pedidos-beautykora/{$pedido->id}/status", ['status' => 'paid'])->assertRedirect();
        $this->assertSame('paid', $pedido->fresh()->status);

        $cliente2 = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->actingAs($cliente2)->get('/admin/pedidos-beautykora')->assertForbidden();
    }
}
