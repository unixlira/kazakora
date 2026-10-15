<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Checkout\Models\Address;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileAddressTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'label' => 'Casa',
            'recipient_name' => 'Ana Souza',
            'phone' => '11999999999',
            'zip' => '01000-000',
            'street' => 'Rua Teste',
            'number' => '100',
            'complement' => null,
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'sp',
            'is_default' => false,
            ...$overrides,
        ];
    }

    private function address(User $user, array $overrides = []): Address
    {
        return $user->addresses()->create([...$this->payload(), 'state' => 'SP', ...$overrides]);
    }

    public function test_first_address_becomes_default(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($customer)->post('/perfil/enderecos', $this->payload())->assertRedirect();

        $address = $customer->addresses()->sole();
        $this->assertTrue($address->is_default);
        $this->assertSame('SP', $address->state);
    }

    public function test_choosing_new_default_unsets_the_old_one(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $old = $this->address($customer, ['is_default' => true]);
        $new = $this->address($customer, ['number' => '200']);

        $this->actingAs($customer)->put("/perfil/enderecos/{$new->id}", $this->payload(['number' => '200', 'is_default' => true]))
            ->assertRedirect();

        $this->assertFalse($old->fresh()->is_default);
        $this->assertTrue($new->fresh()->is_default);
    }

    public function test_address_without_orders_can_be_deleted(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $address = $this->address($customer);

        $this->actingAs($customer)->delete("/perfil/enderecos/{$address->id}")->assertRedirect();

        $this->assertModelMissing($address);
    }

    public function test_address_used_in_an_order_cannot_be_deleted_but_can_be_edited(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $address = $this->address($customer);

        Order::create([
            'user_id' => $customer->id,
            'address_id' => $address->id,
            'status' => Order::STATUS_PENDING,
            'shipping_name' => $customer->name,
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua Teste',
            'shipping_number' => '100',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ]);

        $this->actingAs($customer)->delete("/perfil/enderecos/{$address->id}")
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertModelExists($address);

        $this->actingAs($customer)->put("/perfil/enderecos/{$address->id}", $this->payload(['complement' => 'Apto 12']))
            ->assertRedirect();
        $this->assertSame('Apto 12', $address->fresh()->complement);
    }

    public function test_customer_cannot_touch_another_customers_address(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $address = $this->address($other);

        $this->actingAs($customer)->put("/perfil/enderecos/{$address->id}", $this->payload())->assertForbidden();
        $this->actingAs($customer)->delete("/perfil/enderecos/{$address->id}")->assertForbidden();
        $this->assertModelExists($address);
    }
}
