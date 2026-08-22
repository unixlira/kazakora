<?php

namespace Tests\Feature\Api;

use App\Models\ApiPartner;
use App\Models\ApiRequestLog;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API pública de parceiros externos, pedido explícito 2026-08-21. Cobre o
 * MECANISMO central (auth:sanctum + abilities + api.partner.active +
 * log.api), não cada endpoint de recurso individualmente — cada
 * controller de recurso passa pelo MESMO stack de middleware, então o
 * risco real está aqui, não repetido em cada um. Verificado também ao
 * vivo contra o servidor real (não só estes testes) antes de considerar
 * pronto — ver histórico do dia pros 2 bugs reais achados só nesse teste
 * manual (validated() com campo ausente do JSON, stock não recarregado
 * após create()).
 */
class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function makePartner(array $abilities, bool $active = true): ApiPartner
    {
        return ApiPartner::create([
            'name' => 'Parceiro Teste',
            'slug' => 'parceiro-teste-'.uniqid(),
            'abilities' => $abilities,
            'rate_limit_per_minute' => 60,
            'is_active' => $active,
        ]);
    }

    public function test_request_without_a_token_is_rejected(): void
    {
        $this->getJson('/api/v1/produtos')->assertUnauthorized();
    }

    public function test_request_with_an_invalid_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer token-que-nao-existe')
            ->getJson('/api/v1/produtos')
            ->assertUnauthorized();
    }

    public function test_token_without_the_required_ability_is_forbidden(): void
    {
        $partner = $this->makePartner(['pedidos.view']); // sem cadastros.view
        $token = $partner->createToken('teste', $partner->allowedAbilities())->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/produtos')
            ->assertForbidden();
    }

    public function test_token_with_the_required_ability_succeeds(): void
    {
        $partner = $this->makePartner(['cadastros.view']);
        $token = $partner->createToken('teste', $partner->allowedAbilities())->plainTextToken;
        Product::factory()->count(2)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/produtos')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_deactivated_partners_token_is_rejected_even_if_still_valid(): void
    {
        $partner = $this->makePartner(['cadastros.view'], active: false);
        $token = $partner->createToken('teste', $partner->allowedAbilities())->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/produtos')
            ->assertForbidden();
    }

    /**
     * ApiPartner::allowedAbilities() é a defesa em profundidade citada no
     * model — mesmo que `abilities` no banco tenha uma string fora do
     * vocabulário de Permissions::ALL (edição direta, bug em outro lugar
     * etc.), o token nunca deveria SAIR com uma ability inválida.
     */
    public function test_allowed_abilities_filters_out_anything_outside_the_known_permission_vocabulary(): void
    {
        $partner = $this->makePartner(['cadastros.view', 'algo.inventado', 'pedidos.view']);

        $this->assertSame(['cadastros.view', 'pedidos.view'], $partner->allowedAbilities());
    }

    public function test_every_request_is_logged_regardless_of_outcome(): void
    {
        $partner = $this->makePartner(['cadastros.view']);
        $token = $partner->createToken('teste', $partner->allowedAbilities())->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/produtos');
        $this->withHeader('Authorization', 'Bearer token-invalido')->getJson('/api/v1/produtos');

        $this->assertDatabaseHas('api_request_logs', ['api_partner_id' => $partner->id, 'status_code' => 200]);
        $this->assertDatabaseHas('api_request_logs', ['api_partner_id' => null, 'status_code' => 401]);
    }

    public function test_me_endpoint_reflects_the_tokens_actual_abilities(): void
    {
        $partner = $this->makePartner(['cadastros.view', 'pedidos.view']);
        $token = $partner->createToken('teste', $partner->allowedAbilities())->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJson([
                'id' => $partner->id,
                'name' => $partner->name,
                'abilities' => ['cadastros.view', 'pedidos.view'],
            ]);
    }
}
