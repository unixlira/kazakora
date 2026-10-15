<?php

namespace Tests\Feature\BeautyKora;

use App\Modules\BeautyKora\Models\BeautyKoraCliente;
use App\Modules\BeautyKora\Models\BeautyKoraEndereco;
use App\Modules\BeautyKora\Models\BeautyKoraPedido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Meu Perfil do site Beauty Kora igual ao da KazaKora (pedido 2026-10-14). */
class BeautyKoraPerfilTest extends TestCase
{
    use RefreshDatabase;

    private function endereco(array $extra = []): array
    {
        return [
            'label' => 'Casa', 'recipient_name' => 'Maria', 'phone' => '11999999999', 'zip' => '01001-000',
            'street' => 'Praça da Sé', 'number' => '1', 'complement' => null, 'neighborhood' => 'Sé',
            'city' => 'São Paulo', 'state' => 'sp', 'is_default' => false, ...$extra,
        ];
    }

    private function cliente(): array
    {
        $cliente = BeautyKoraCliente::query()->create(['name' => 'Maria Souza', 'email' => 'maria@exemplo.com', 'password' => 'segredo123']);

        return [$cliente, $cliente->createToken('teste', ['beautykora:cliente'], now()->addDay())->plainTextToken];
    }

    public function test_ve_e_edita_dados_foto_e_senha(): void
    {
        Storage::fake('public');
        [$cliente, $token] = $this->cliente();
        BeautyKoraCliente::query()->create(['name' => 'Outra', 'email' => 'outra@exemplo.com', 'password' => 'segredo123']);

        $this->getJson('/api/beautykora/perfil')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/beautykora/perfil')
            ->assertOk()->assertJsonPath('perfil.initials', 'MS')->assertJsonPath('selo', 'super_cliente');

        $this->withToken($token)->patchJson('/api/beautykora/perfil', ['name' => 'Maria', 'email' => 'outra@exemplo.com'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->withToken($token)->patchJson('/api/beautykora/perfil', [
            'name' => 'Maria S.', 'email' => 'Maria.Nova@exemplo.com', 'phone' => '11988887777', 'cpf' => '529.982.247-25', 'birth_date' => '1992-01-31',
        ])->assertOk()
            ->assertJsonPath('perfil.email', 'maria.nova@exemplo.com')
            ->assertJsonPath('perfil.birth_date', '1992-01-31')
            ->assertJsonPath('user.name', 'Maria S.');

        $this->withToken($token)->post('/api/beautykora/perfil/avatar', ['avatar' => UploadedFile::fake()->image('eu.jpg')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('user.avatar', fn ($url) => str_contains((string) $url, 'beautykora/avatars/'));
        $this->withToken($token)->post('/api/beautykora/perfil/avatar', ['avatar' => UploadedFile::fake()->create('eu.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->withToken($token)->deleteJson('/api/beautykora/perfil/avatar')->assertOk()->assertJsonPath('perfil.avatar_url', null);

        $this->withToken($token)->putJson('/api/beautykora/perfil/senha', ['password' => 'curta', 'password_confirmation' => 'curta'])->assertStatus(422);
        $this->withToken($token)->putJson('/api/beautykora/perfil/senha', ['password' => 'novaSenha1', 'password_confirmation' => 'novaSenha1'])->assertOk();
        $this->assertTrue(Hash::check('novaSenha1', $cliente->fresh()->password));
    }

    public function test_varios_enderecos_principal_e_checkout(): void
    {
        [$cliente, $token] = $this->cliente();

        $casa = $this->withToken($token)->postJson('/api/beautykora/perfil/enderecos', $this->endereco())
            ->assertCreated()->assertJsonPath('enderecos.0.is_default', true)->assertJsonPath('enderecos.0.state', 'SP')->json('enderecos.0.id');
        $trabalho = $this->withToken($token)->postJson('/api/beautykora/perfil/enderecos', $this->endereco(['label' => 'Trabalho', 'zip' => '04538-133', 'number' => '50']))
            ->assertCreated()->assertJsonPath('enderecos.0.id', $casa)->json('enderecos.1.id');

        // Tornar principal.
        $this->withToken($token)->putJson("/api/beautykora/perfil/enderecos/{$trabalho}", $this->endereco(['label' => 'Trabalho', 'zip' => '04538-133', 'number' => '50', 'is_default' => true]))
            ->assertOk()->assertJsonPath('enderecos.0.id', $trabalho)->assertJsonPath('enderecos.1.is_default', false);

        // O checkout pega o principal e, com o mesmo CEP e número, atualiza em vez de duplicar.
        $this->withToken($token)->getJson('/api/beautykora/endereco')->assertOk()->assertJsonPath('address.cep', '04538-133');
        $this->withToken($token)->putJson('/api/beautykora/endereco', [
            'cep' => '04538133', 'logradouro' => 'Av. Faria Lima', 'numero' => '50', 'bairro' => 'Itaim', 'cidade' => 'São Paulo', 'estado' => 'SP',
        ])->assertOk();
        $this->assertSame(2, $cliente->enderecos()->count());

        // Endereço usado em pedido não sai; o outro sai e o principal passa adiante.
        BeautyKoraPedido::query()->create([
            'cliente_id' => $cliente->id, 'endereco_id' => $casa, 'produto_id' => 'p1', 'produto_nome' => 'Sérum', 'quantidade' => 1, 'valor_pago_centavos' => 1000,
        ]);
        $this->withToken($token)->getJson('/api/beautykora/perfil')->assertJsonPath('enderecos.1.tem_pedido', true);
        $this->withToken($token)->deleteJson("/api/beautykora/perfil/enderecos/{$casa}")->assertStatus(422);
        $this->withToken($token)->deleteJson("/api/beautykora/perfil/enderecos/{$trabalho}")
            ->assertOk()->assertJsonCount(1, 'enderecos')->assertJsonPath('enderecos.0.is_default', true);

        // Endereço de outra cliente: 404.
        $outra = BeautyKoraCliente::query()->create(['name' => 'Ana', 'email' => 'ana@exemplo.com', 'password' => 'segredo123']);
        $daOutra = $outra->enderecos()->create(BeautyKoraEndereco::doPerfil($this->endereco()));
        $this->withToken($token)->putJson("/api/beautykora/perfil/enderecos/{$daOutra->id}", $this->endereco())->assertNotFound();
    }
}
