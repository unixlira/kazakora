<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pedido explícito 2026-08-09: menu de emissão manual de nota fiscal, item
 * podendo ser produto do catálogo OU digitado na hora (serviço avulso).
 */
class InvoiceManualControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function baseFields(): array
    {
        return [
            'buyer_document' => '123.456.789-09',
            'buyer_name' => 'Cliente Teste',
            'buyer_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua Teste',
            'shipping_number' => '100',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
        ];
    }

    public function test_create_page_loads_with_active_products(): void
    {
        Product::factory()->create(['is_active' => true]);
        Product::factory()->create(['is_active' => false]);

        $response = $this->actingAs($this->admin())->get('/admin/notas-fiscais/emitir');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Invoices/Emitir')->has('products', 1));
    }

    public function test_store_creates_an_order_for_a_catalog_product_item_and_dispatches_invoice_generation(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['price' => 50, 'stock' => 10]);

        $response = $this->actingAs($this->admin())->post('/admin/notas-fiscais/emitir', array_merge($this->baseFields(), [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50],
            ],
        ]));

        $response->assertRedirect('/admin/notas-fiscais');

        $order = Order::query()->where('origin', Order::ORIGIN_MANUAL_INVOICE)->firstOrFail();
        $this->assertSame(100.0, (float) $order->total);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame($product->id, $order->items()->first()->product_id);

        $this->assertSame(8, $product->fresh()->stock);

        Queue::assertPushed(GenerateInvoiceJob::class);
    }

    public function test_store_creates_an_order_for_a_freeform_service_item_without_touching_stock(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->admin())->post('/admin/notas-fiscais/emitir', array_merge($this->baseFields(), [
            'items' => [
                [
                    'product_id' => null,
                    'description' => 'Consultoria avulsa',
                    'item_type' => OrderItem::TYPE_SERVICE,
                    'quantity' => 1,
                    'unit_price' => 200,
                    'ncm' => '99999999',
                    'cfop' => '5933',
                    'icms_situacao_tributaria' => '400',
                    'pis_situacao_tributaria' => '07',
                    'cofins_situacao_tributaria' => '07',
                ],
            ],
        ]));

        $response->assertRedirect('/admin/notas-fiscais');

        $order = Order::query()->where('origin', Order::ORIGIN_MANUAL_INVOICE)->firstOrFail();
        $item = $order->items()->firstOrFail();

        $this->assertNull($item->product_id);
        $this->assertSame('Consultoria avulsa', $item->product_name);
        $this->assertSame(OrderItem::TYPE_SERVICE, $item->item_type);
        $this->assertSame('99999999', $item->ncm);

        Queue::assertPushed(GenerateInvoiceJob::class);
    }

    public function test_store_rejects_a_freeform_item_missing_required_fiscal_fields(): void
    {
        $response = $this->actingAs($this->admin())->post('/admin/notas-fiscais/emitir', array_merge($this->baseFields(), [
            'items' => [
                ['product_id' => null, 'description' => 'Item incompleto', 'quantity' => 1, 'unit_price' => 10],
            ],
        ]));

        $response->assertSessionHasErrors([
            'items.0.ncm',
            'items.0.cfop',
            'items.0.icms_situacao_tributaria',
            'items.0.pis_situacao_tributaria',
            'items.0.cofins_situacao_tributaria',
        ]);
        $this->assertSame(0, Order::query()->count());
    }
}
