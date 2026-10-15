<?php

namespace Tests\Feature\FerraKora;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\FerraKora\Models\FerraKoraCliente;
use App\Modules\FerraKora\Models\FerraKoraSessao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Meu Perfil do site FerraKora igual ao da KazaKora (pedido 2026-10-14), na
 * conta própria do FerraKora (dados separados por projeto).
 */
class FerraKoraPerfilTest extends TestCase
{
    use RefreshDatabase;

    private function endereco(array $extra = []): array
    {
        return [
            'label' => 'Casa', 'recipient_name' => 'João', 'phone' => '11999999999', 'zip' => '01001-000',
            'street' => 'Praça da Sé', 'number' => '1', 'complement' => null, 'neighborhood' => 'Sé',
            'city' => 'São Paulo', 'state' => 'sp', 'is_default' => false, ...$extra,
        ];
    }

    private function cliente(string $email = 'joao@exemplo.com'): FerraKoraCliente
    {
        return FerraKoraCliente::query()->create(['name' => 'João', 'email' => $email, 'password' => 'segredo123']);
    }

    private function logar(FerraKoraCliente $cliente): string
    {
        return FerraKoraSessao::abrir($cliente, request());
    }

    public function test_ve_e_edita_os_dados_pessoais_sem_mexer_na_kazakora(): void
    {
        $cliente = $this->cliente();
        $daKazaKora = User::factory()->create(['name' => 'João KZ', 'email' => 'joao@exemplo.com', 'role' => User::ROLE_CUSTOMER]);
        $this->cliente('outra@exemplo.com');
        $token = $this->logar($cliente);

        $this->getJson('/api/ferrakora/perfil')->assertUnauthorized();

        $this->withToken($token)->getJson('/api/ferrakora/perfil')
            ->assertOk()
            ->assertJsonPath('perfil.name', 'João')
            ->assertJsonPath('perfil.initials', 'J')
            ->assertJsonPath('selo', 'super_cliente')
            ->assertJsonPath('enderecos', []);

        $this->withToken($token)->patchJson('/api/ferrakora/perfil', ['name' => 'João Silva', 'email' => 'outra@exemplo.com'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->withToken($token)->patchJson('/api/ferrakora/perfil', [
            'name' => 'João Silva', 'email' => 'joao@exemplo.com', 'phone' => '(11) 91234-5678', 'cpf' => '111.444.777-35', 'birth_date' => '1990-05-15',
        ])->assertOk()
            ->assertJsonPath('perfil.birth_date', '1990-05-15')
            ->assertJsonPath('perfil.initials', 'JS')
            ->assertJsonPath('user.name', 'João Silva');

        $this->assertSame('111.444.777-35', $cliente->fresh()->cpf);
        $this->assertSame('João KZ', $daKazaKora->fresh()->name);
        $this->assertSame($daKazaKora->cpf, $daKazaKora->fresh()->cpf);
    }

    public function test_foto_e_senha(): void
    {
        Storage::fake('public');
        $cliente = $this->cliente();
        $token = $this->logar($cliente);

        $url = $this->withToken($token)->post('/api/ferrakora/perfil/avatar', ['avatar' => UploadedFile::fake()->image('eu.png')], ['Accept' => 'application/json'])
            ->assertOk()->json('perfil.avatar_url');
        $this->assertNotNull($url);
        Storage::disk('public')->assertExists($cliente->fresh()->avatar_path);

        $this->withToken($token)->deleteJson('/api/ferrakora/perfil/avatar')->assertOk()->assertJsonPath('perfil.avatar_url', null);

        $this->withToken($token)->putJson('/api/ferrakora/perfil/senha', ['password' => 'novaSenha1', 'password_confirmation' => 'outra'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->withToken($token)->putJson('/api/ferrakora/perfil/senha', ['password' => 'novaSenha1', 'password_confirmation' => 'novaSenha1'])->assertOk();
        $this->assertTrue(Hash::check('novaSenha1', $cliente->fresh()->password));
    }

    public function test_enderecos_principal_e_travado_por_pedido(): void
    {
        $cliente = $this->cliente();
        $token = $this->logar($cliente);

        $primeiro = $this->withToken($token)->postJson('/api/ferrakora/perfil/enderecos', $this->endereco())
            ->assertCreated()->assertJsonPath('enderecos.0.is_default', true)->assertJsonPath('enderecos.0.state', 'SP')->json('enderecos.0.id');

        $enderecos = $this->withToken($token)->postJson('/api/ferrakora/perfil/enderecos', $this->endereco(['label' => 'Trabalho', 'is_default' => true]))
            ->assertCreated()->json('enderecos');
        $this->assertSame('Trabalho', $enderecos[0]['label']);
        $this->assertFalse(collect($enderecos)->firstWhere('id', $primeiro)['is_default']);

        // Pedido do FerraKora com o e-mail da conta, no mesmo CEP e número.
        $comprador = User::factory()->create(['email' => 'joao@exemplo.com', 'role' => User::ROLE_CUSTOMER]);
        Order::create([
            'user_id' => $comprador->id, 'site_origem' => Order::SITE_FERRAKORA, 'status' => Order::STATUS_PENDING,
            'shipping_name' => 'João', 'shipping_phone' => '11999999999', 'shipping_zip' => '01001000', 'shipping_street' => 'Praça da Sé',
            'shipping_number' => '1', 'shipping_neighborhood' => 'Sé', 'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'total' => 100,
        ]);

        $this->withToken($token)->deleteJson("/api/ferrakora/perfil/enderecos/{$primeiro}")->assertStatus(422);
        $this->withToken($token)->putJson("/api/ferrakora/perfil/enderecos/{$primeiro}", $this->endereco(['is_default' => true]))
            ->assertOk()->assertJsonPath('enderecos.0.id', $primeiro)->assertJsonPath('enderecos.0.tem_pedido', true);

        // Endereço de outra conta: 404.
        $outro = $this->cliente('outra@exemplo.com')->enderecos()->create([
            'cep' => '01001-000', 'logradouro' => 'Rua', 'numero' => '9', 'bairro' => 'Sé', 'cidade' => 'São Paulo', 'estado' => 'SP',
        ]);
        $this->withToken($token)->putJson("/api/ferrakora/perfil/enderecos/{$outro->id}", $this->endereco())->assertNotFound();
        $this->withToken($token)->deleteJson("/api/ferrakora/perfil/enderecos/{$outro->id}")->assertNotFound();
    }
}
