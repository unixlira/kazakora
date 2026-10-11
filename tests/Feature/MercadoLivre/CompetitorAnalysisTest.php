<?php

namespace Tests\Feature\MercadoLivre;

use App\Models\MercadoLivreToken;
use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompetitorAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private function seedValidToken(): MercadoLivreToken
    {
        return MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(),
            'ml_user_id' => 123456789,
            'ml_nickname' => 'LOJA_KAZAKORA',
            'access_token' => 'valid-access-token',
            'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->addHours(6),
            'scopes' => ['offline_access', 'read', 'write'],
        ]);
    }

    public function test_admin_sees_the_product_picker_with_no_selection(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Product::factory()->create(['name' => 'Fritadeira Elétrica']);

        $response = $this->actingAs($admin)->get('/admin/concorrencia');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('selectedProduct', null)
            ->where('results', []));
    }

    public function test_search_returns_results_with_estimated_fees(): void
    {
        $this->seedValidToken();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create(['name' => 'Fritadeira Elétrica', 'price' => 250]);

        Http::fake([
            'https://api.mercadolibre.com/sites/MLB/search*' => Http::response([
                'results' => [[
                    'id' => 'MLB111',
                    'title' => 'Fritadeira Elétrica Sem Óleo 4L',
                    'price' => 229.9,
                    'permalink' => 'https://produto.mercadolivre.com.br/MLB111',
                    'seller' => ['id' => 555, 'nickname' => 'LOJA_CONCORRENTE'],
                    'condition' => 'new',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'category_id' => 'MLB1234',
                ]],
            ]),
            'https://api.mercadolibre.com/sites/MLB/listing_prices*' => Http::response([
                [
                    'listing_type_id' => 'gold_special',
                    'sale_fee_amount' => 27.5,
                    'sale_fee_details' => ['percentage_fee' => 11],
                ],
            ]),
        ]);

        $response = $this->actingAs($admin)->get("/admin/concorrencia?product_id={$product->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('results.0.title', 'Fritadeira Elétrica Sem Óleo 4L')
            ->where('results.0.seller_nickname', 'LOJA_CONCORRENTE')
            ->where('results.0.estimated_fee_amount', 27.5)
            ->where('results.0.estimated_fee_listing_type', 'gold_special'));
    }

    public function test_zero_results_does_not_error(): void
    {
        $this->seedValidToken();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create();

        Http::fake([
            'https://api.mercadolibre.com/sites/MLB/search*' => Http::response(['results' => []]),
        ]);

        $response = $this->actingAs($admin)->get("/admin/concorrencia?product_id={$product->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('results', [])->where('error', null));
    }

    public function test_rate_limit_shows_a_friendly_error_instead_of_crashing(): void
    {
        $this->seedValidToken();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create();

        Http::fake([
            'https://api.mercadolibre.com/sites/MLB/search*' => Http::response(['message' => 'rate limited'], 429, ['Retry-After' => '5']),
        ]);

        $response = $this->actingAs($admin)->get("/admin/concorrencia?product_id={$product->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('error.type', 'rate_limit'));
    }

    public function test_search_forbidden_shows_the_ml_partner_access_message(): void
    {
        $this->seedValidToken();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create();

        Http::fake([
            'https://api.mercadolibre.com/sites/MLB/search*' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $response = $this->actingAs($admin)->get("/admin/concorrencia?product_id={$product->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('error.type', 'search_restricted')
            ->where('error.message', fn ($message) => str_contains($message, 'suporte do Mercado Livre')));
    }

    public function test_generic_failure_shows_a_friendly_error_instead_of_crashing(): void
    {
        $this->seedValidToken();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create();

        Http::fake([
            'https://api.mercadolibre.com/sites/MLB/search*' => Http::response(['message' => 'boom'], 500),
        ]);

        $response = $this->actingAs($admin)->get("/admin/concorrencia?product_id={$product->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('error.type', 'generic'));
    }

    public function test_no_ml_account_connected_shows_a_guidance_message(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create();

        $response = $this->actingAs($admin)->get("/admin/concorrencia?product_id={$product->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('error.type', 'generic')
            ->where('error.message', 'Nenhuma conta do Mercado Livre está conectada. Conecte uma conta antes de continuar.'));
    }

    public function test_customer_cannot_access_the_page(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($customer)->get('/admin/concorrencia');

        $response->assertForbidden();
    }
}
