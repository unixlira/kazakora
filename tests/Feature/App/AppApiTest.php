<?php

namespace Tests\Feature\App;

use App\Models\User;
use App\Modules\Catalog\Models\Favorite;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Address;
use App\Modules\Checkout\Models\Order;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * API do app nativo da KazaKora (/api/app, 2026-10-18): catálogo, conta com
 * token do Sanctum, endereços, favoritos, pedido no Pix ligado à conta,
 * cancelamento e exclusão da conta.
 */
class AppApiTest extends TestCase
{
    use RefreshDatabase;

    private const CEP = '01310-100';

    private Product $produto;

    protected function setUp(): void
    {
        parent::setUp();
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        Mail::fake();
        Bus::fake();

        $this->produto = Product::factory()->create([
            'sku' => 'KZ-APP-01', 'name' => 'Cozinha Infantil com Pia', 'slug' => 'cozinha-infantil-pia', 'price' => 100.00, 'stock' => 5,
            'is_active' => true, 'discount_percentage' => null, 'discount_amount' => null,
        ]);
    }

    private function cliente(array $extra = []): User
    {
        return User::factory()->create([
            'name' => 'Maria da Silva Souza', 'email' => 'maria@exemplo.com', 'cpf' => '12345678909', 'phone' => '11988887777',
            'password' => 'segredo123', 'role' => User::ROLE_CUSTOMER, ...$extra,
        ]);
    }

    private function tokenDe(User $user): string
    {
        return $user->createToken('app:teste', ['app'])->plainTextToken;
    }

    private function endereco(User $user): Address
    {
        return $user->addresses()->create([
            'recipient_name' => 'Maria da Silva Souza', 'phone' => '11988887777', 'zip' => self::CEP, 'street' => 'Av. Paulista',
            'number' => '1000', 'neighborhood' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'SP', 'is_default' => true,
        ]);
    }

    private function mercadoPago(callable $configurar): void
    {
        $this->mock(MercadoPagoPaymentService::class, function ($mock) use ($configurar) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $configurar($mock);
        });
    }

    public function test_config_e_catalogo_publicos_em_centavos(): void
    {
        $this->getJson('/api/app/config')->assertOk()
            ->assertJsonPath('loja.nome', 'KazaKora')
            ->assertJsonPath('desconto_pix_percentual', 5)
            ->assertJsonStructure(['loja' => ['whatsapp_link', 'politica_url', 'termos_url'], 'mercadopago_public_key', 'versao_minima' => ['ios', 'android']]);

        $lista = $this->getJson('/api/app/produtos?busca=cozinha')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('produtos.0.slug', 'cozinha-infantil-pia');
        $this->assertIsInt($lista->json('produtos.0.preco'));
        $this->assertLessThan($lista->json('produtos.0.preco'), $lista->json('produtos.0.preco_pix'));

        $this->getJson('/api/app/produtos/cozinha-infantil-pia')->assertOk()
            ->assertJsonPath('produto.variantes.0.variante', 'kzv-'.$this->produto->id)
            ->assertJsonPath('produto.avaliacoes.total', 0);
        $this->getJson('/api/app/produtos/nao-existe')->assertNotFound();
        $this->getJson('/api/app/inicio')->assertOk()->assertJsonStructure(['banners', 'vitrines']);
        $this->getJson('/api/app/departamentos')->assertOk()->assertJsonStructure(['departamentos']);
    }

    public function test_cadastro_login_e_rotas_protegidas(): void
    {
        $cadastro = $this->postJson('/api/app/conta/cadastro', [
            'nome' => 'João Pereira', 'email' => 'joao@exemplo.com', 'cpf' => '529.982.247-25', 'telefone' => '(11) 97777-6666',
            'senha' => 'senhaforte1', 'aceite_termos' => true, 'dispositivo' => 'iPhone de João',
        ])->assertCreated()->assertJsonPath('usuario.cpf_mascarado', '***.982.247-**');

        $this->withToken($cadastro->json('token'))->getJson('/api/app/conta/eu')->assertOk()->assertJsonPath('usuario.email', 'joao@exemplo.com');

        $this->postJson('/api/app/conta/cadastro', [
            'nome' => 'Outro', 'email' => 'joao@exemplo.com', 'cpf' => '529.982.247-25', 'telefone' => '11977776666', 'senha' => 'senhaforte1', 'aceite_termos' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['email', 'cpf']);

        $this->postJson('/api/app/conta/login', ['email' => 'joao@exemplo.com', 'senha' => 'errada'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/app/conta/login', ['email' => 'joao@exemplo.com', 'senha' => 'senhaforte1'])->assertOk()->assertJsonStructure(['token', 'usuario']);

        $this->flushHeaders();
        $this->getJson('/api/app/conta/eu')->assertUnauthorized();
        $this->getJson('/api/app/pedidos')->assertUnauthorized();
    }

    public function test_token_sem_ability_app_nao_entra(): void
    {
        $token = $this->cliente()->createToken('outro', ['parceiro'])->plainTextToken;

        $this->withToken($token)->getJson('/api/app/conta/eu')->assertUnauthorized();
    }

    public function test_enderecos_e_favoritos_da_conta(): void
    {
        $user = $this->cliente();
        $token = $this->tokenDe($user);

        $id = $this->withToken($token)->postJson('/api/app/conta/enderecos', [
            'destinatario' => 'Maria', 'telefone' => '11988887777', 'cep' => '01310-100', 'rua' => 'Av. Paulista', 'numero' => '1000',
            'bairro' => 'Bela Vista', 'cidade' => 'São Paulo', 'uf' => 'SP',
        ])->assertCreated()->assertJsonPath('endereco.principal', true)->json('endereco.id');

        $this->withToken($token)->getJson('/api/app/conta/enderecos')->assertOk()->assertJsonCount(1, 'enderecos');

        $outro = $this->cliente(['email' => 'outra@exemplo.com', 'cpf' => '52998224725']);
        $this->withToken($this->tokenDe($outro))->deleteJson("/api/app/conta/enderecos/{$id}")->assertNotFound();

        $this->withToken($token)->postJson("/api/app/favoritos/{$this->produto->id}")->assertOk()->assertJsonPath('ids', [$this->produto->id]);
        $this->withToken($token)->getJson('/api/app/favoritos')->assertOk()->assertJsonPath('produtos.0.id', $this->produto->id);
        $this->assertTrue(Favorite::query()->where('user_id', $user->id)->exists());
        $this->withToken($token)->deleteJson("/api/app/favoritos/{$this->produto->id}")->assertOk()->assertJsonPath('ids', []);
    }

    public function test_pedido_no_pix_vira_venda_do_site_ligada_a_conta(): void
    {
        $user = $this->cliente();
        $endereco = $this->endereco($user);
        $token = $this->tokenDe($user);
        $itens = [['variante' => 'kzv-'.$this->produto->id, 'quantidade' => 1]];

        $resumo = $this->withToken($token)->postJson('/api/app/checkout/resumo', ['itens' => $itens, 'cep' => self::CEP])->assertOk()->json();

        $this->mercadoPago(function ($mock) {
            $mock->shouldReceive('createPixPayment')->once()
                ->andReturn(['id' => 777, 'status' => 'pending', 'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix-copia', 'qr_code_base64' => 'abc']]]);
            $mock->shouldReceive('retrievePayment')->with('777')->andReturn(['status' => 'approved']);
        });

        $resposta = $this->withToken($token)->withHeader('X-App-Plataforma', 'ios')->postJson('/api/app/checkout/pedido', [
            'itens' => $itens,
            'endereco_id' => $endereco->id,
            'frete' => $resumo['entrega']['escolhida'],
            'total_esperado' => $resumo['total_pix'],
            'pagamento' => ['metodo' => 'pix'],
        ])->assertCreated()->assertJsonPath('pix.copia_e_cola', 'pix-copia');

        $order = Order::findOrFail($resposta->json('pedido.id'));
        $this->assertSame($user->id, $order->user_id);
        $this->assertSame(Order::ORIGIN_STORE, $order->origin);
        $this->assertNull($order->site_origem);
        $this->assertSame('ios', $order->app_plataforma);
        $this->assertSame($endereco->id, $order->address_id);

        $this->withToken($token)->getJson("/api/app/pedidos/{$order->id}/status")->assertOk()->assertJsonPath('pago', true);
        $this->withToken($token)->getJson('/api/app/pedidos')->assertOk()->assertJsonPath('pedidos.0.id', $order->id);

        $outro = $this->cliente(['email' => 'outra@exemplo.com', 'cpf' => '52998224725']);
        $this->withToken($this->tokenDe($outro))->getJson("/api/app/pedidos/{$order->id}")->assertNotFound();
    }

    public function test_total_diferente_volta_409_com_resumo_novo(): void
    {
        $user = $this->cliente();
        $endereco = $this->endereco($user);
        $this->mercadoPago(fn ($mock) => $mock->shouldReceive('createPixPayment')->never());

        $this->withToken($this->tokenDe($user))->postJson('/api/app/checkout/pedido', [
            'itens' => [['variante' => 'kzv-'.$this->produto->id, 'quantidade' => 1]],
            'endereco_id' => $endereco->id,
            'total_esperado' => 1,
            'pagamento' => ['metodo' => 'pix'],
        ])->assertStatus(409)->assertJsonStructure(['message', 'resumo' => ['total', 'total_pix']]);
    }

    public function test_cancelar_pedido_pago_pela_conta(): void
    {
        $user = $this->cliente();
        $order = Order::create([
            'user_id' => $user->id, 'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Maria', 'shipping_phone' => '11988887777', 'shipping_zip' => self::CEP, 'shipping_street' => 'Av. Paulista',
            'shipping_number' => '1000', 'shipping_neighborhood' => 'Bela Vista', 'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'total' => 100,
        ]);
        $token = $this->tokenDe($user);

        $this->withToken($token)->getJson("/api/app/pedidos/{$order->id}")->assertOk()->assertJsonPath('pedido.pode_cancelar', true);
        $this->withToken($token)->postJson("/api/app/pedidos/{$order->id}/cancelar", ['motivo' => ''])->assertStatus(422);
        $this->withToken($token)->postJson("/api/app/pedidos/{$order->id}/cancelar", ['motivo' => 'Desisti da compra'])
            ->assertOk()->assertJsonPath('pedido.status', 'cancelado');

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->withToken($token)->postJson("/api/app/pedidos/{$order->id}/cancelar", ['motivo' => 'De novo'])->assertStatus(422);
    }

    public function test_excluir_conta_anonimiza_e_derruba_o_token(): void
    {
        $user = $this->cliente();
        $token = $this->tokenDe($user);

        $this->withToken($token)->deleteJson('/api/app/conta', ['senha' => 'errada'])->assertStatus(422);
        $this->withToken($token)->deleteJson('/api/app/conta', ['senha' => 'segredo123'])->assertNoContent();

        $apagado = User::withTrashed()->findOrFail($user->id);
        $this->assertTrue($apagado->trashed());
        $this->assertNull($apagado->cpf);
        $this->assertStringEndsWith('@conta-excluida.invalid', $apagado->email);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/app/conta/eu')->assertUnauthorized();
    }
}
