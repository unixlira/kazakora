<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Services\SkuGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkuGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    private SkuGeneratorService $skus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skus = app(SkuGeneratorService::class);
    }

    public function test_product_with_all_fields_filled(): void
    {
        $sku = $this->skus->generate([
            'categoria' => 'Moda',
            'produto' => 'Camiseta Básica',
            'marca' => 'Nike',
            'modelo' => 'Dri-Fit',
            'cor' => 'Preta',
            'variacao' => 'P',
        ]);

        $this->assertSame('MOD-CAM-NIK-DRI-PRE-P-0001', $sku);
    }

    public function test_product_without_color(): void
    {
        $sku = $this->skus->generate([
            'categoria' => 'Moda',
            'produto' => 'Camiseta Básica',
            'variacao' => 'P',
        ]);

        $this->assertSame('MOD-CAM-P-0001', $sku);
        $this->assertStringNotContainsString('PRE', $sku);
    }

    public function test_product_without_brand(): void
    {
        $sku = $this->skus->generate([
            'categoria' => 'Moda',
            'produto' => 'Camiseta Básica',
            'cor' => 'Preta',
            'variacao' => 'P',
        ]);

        $this->assertSame('MOD-CAM-PRE-P-0001', $sku);
    }

    public function test_product_with_size_variation(): void
    {
        $sku = $this->skus->generate([
            'produto' => 'Camiseta',
            'cor' => 'Preta',
            'variacao' => 'GG',
        ]);

        $this->assertStringEndsWith('-GG-0001', $sku);
    }

    public function test_product_with_voltage_variation(): void
    {
        $sku = $this->skus->generate([
            'produto' => 'Carregador',
            'cor' => 'Branco',
            'variacao' => '20W',
        ]);

        $this->assertSame('CAR-BRA-20W-0001', $sku);
    }

    public function test_registering_two_units_with_the_same_data_increments_the_sequence(): void
    {
        $payload = [
            'categoria' => 'Moda',
            'produto' => 'Camiseta Básica',
            'cor' => 'Preta',
            'variacao' => 'P',
        ];

        $firstSku = $this->skus->generate($payload);
        $this->createProduct($firstSku);

        $secondSku = $this->skus->generate($payload);

        $this->assertSame('MOD-CAM-PRE-P-0001', $firstSku);
        $this->assertSame('MOD-CAM-PRE-P-0002', $secondSku);
        $this->assertNotSame($firstSku, $secondSku);
    }

    public function test_different_variations_produce_different_skus(): void
    {
        $base = [
            'categoria' => 'Moda',
            'produto' => 'Camiseta Básica',
            'cor' => 'Preta',
        ];

        $skuP = $this->skus->generate([...$base, 'variacao' => 'P']);
        $this->createProduct($skuP);

        $skuM = $this->skus->generate([...$base, 'variacao' => 'M']);

        $this->assertSame('MOD-CAM-PRE-P-0001', $skuP);
        $this->assertSame('MOD-CAM-PRE-M-0001', $skuM);
        $this->assertNotSame($skuP, $skuM);
    }

    public function test_sku_collision_falls_through_to_next_available_number(): void
    {
        // Simulate a gap-free run of 0001 and a manually created 0002 that
        // would otherwise collide with the naturally generated next value.
        $this->createProduct('MOD-CAM-PRE-P-0001');
        $this->createProduct('MOD-CAM-PRE-P-0002');

        $sku = $this->skus->generate([
            'categoria' => 'Moda',
            'produto' => 'Camiseta Básica',
            'cor' => 'Preta',
            'variacao' => 'P',
        ]);

        $this->assertSame('MOD-CAM-PRE-P-0003', $sku);
    }

    public function test_updating_a_product_does_not_change_its_existing_sku(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $category = Category::factory()->create();

        $product = Product::factory()->create([
            'sku' => 'MOD-CAM-PRE-P-0001',
            'category_id' => $category->id,
            'name' => 'Camiseta Básica',
        ]);

        $response = $this->actingAs($admin)->put("/admin/products/{$product->id}", [
            'name' => 'Camiseta Básica Renovada',
            'category_id' => $category->id,
            'brand' => '',
            'model' => '',
            'color' => 'Preta',
            'variation' => 'P',
            'description' => '',
            'price' => 79.90,
            'stock' => $product->stock,
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $product->refresh();

        $this->assertSame('MOD-CAM-PRE-P-0001', $product->sku);
        $this->assertSame('Camiseta Básica Renovada', $product->name);
    }

    private function createProduct(string $sku): Product
    {
        return Product::factory()->create(['sku' => $sku]);
    }
}
