<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** BUG REAL 2026-10-10: endereço novo do cliente logado dava "Preencha o endereço completo" sem dizer o quê. */
class EnderecoNovoLogadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_logado_com_endereco_salvo_cadastra_endereco_novo(): void
    {
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'José Roberto Lira', 'phone' => null, 'cpf' => null]);
        $user->addresses()->create(['label' => 'Casa', 'recipient_name' => 'José', 'phone' => '11999990000', 'zip' => '01001-000', 'street' => 'Praça da Sé', 'number' => '1', 'neighborhood' => 'Sé', 'city' => 'São Paulo', 'state' => 'SP']);
        $this->actingAs($user);
        $product = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true]);
        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);
        $props = $this->get('/finalizacao')->viewData('page')['props'];

        $resposta = $this->postJson('/finalizacao/entrega', [
            'shipping_method_id' => $props['shippingMethods'][0]['id'],
            'new_address' => ['zip' => '03187-040', 'street' => 'Rua Mogi Mirim', 'number' => '20', 'complement' => '', 'neighborhood' => 'Vila Bertioga', 'city' => 'São Paulo', 'state' => 'SP', 'recipient_name' => 'José Roberto Lira', 'phone' => '(11) 96572-3990', 'label' => 'Entrega'],
        ]);
        $resposta->assertOk();

        // Sem celular em lugar nenhum: mensagem clara apontando o campo; sem número: "Informe o número."
        $this->postJson('/finalizacao/entrega', [
            'shipping_method_id' => $props['shippingMethods'][0]['id'],
            'new_address' => ['zip' => '03187-040', 'street' => 'Rua Mogi Mirim', 'number' => '', 'neighborhood' => 'Vila Bertioga', 'city' => 'São Paulo', 'state' => 'SP', 'recipient_name' => 'José Roberto Lira', 'phone' => ''],
        ])->assertStatus(422)
            ->assertJsonPath('errors', fn ($erros) => $erros['new_address.number'][0] === 'Informe o número.'
                && str_contains($erros['new_address.phone'][0], 'celular'));

        // Celular do cadastro é usado quando o campo vem vazio.
        $user->forceFill(['phone' => '11988887777'])->save();
        $this->postJson('/finalizacao/entrega', [
            'shipping_method_id' => $props['shippingMethods'][0]['id'],
            'new_address' => ['zip' => '03187-040', 'street' => 'Rua Mogi Mirim', 'number' => '20', 'neighborhood' => 'Vila Bertioga', 'city' => 'São Paulo', 'state' => 'SP', 'recipient_name' => 'José Roberto Lira', 'phone' => ''],
        ])->assertOk();
        $this->assertSame('11988887777', session('checkout_draft.new_address.phone'));
    }
}
