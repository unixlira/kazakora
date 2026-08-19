<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CorreiosControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function makeCompany(): Company
    {
        return Company::create([
            'razao_social' => 'Kazakora Comércio Ltda',
            'nome_fantasia' => 'KazaKora',
            'cnpj' => '65604590000107',
            'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL,
            'phone' => '(11) 91234-5678',
            'email' => 'contato@kazakora.com',
            'zip' => '01000-000',
            'street' => 'Rua da Loja',
            'number' => '100',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer' => [
                'name' => 'Maria da Silva',
                'document' => '111.222.333-44',
                'phone' => '(11) 98888-7777',
                'email' => 'maria@example.com',
            ],
            'address' => [
                'zip' => '02000-000',
                'street' => 'Rua das Palmeiras',
                'number' => '50',
                'neighborhood' => 'Jardim',
                'city' => 'São Paulo',
                'state' => 'SP',
            ],
            'service_code' => '03298',
            'weight_grams' => 500,
            'dimensions' => ['format' => '2', 'height' => 10, 'width' => 10, 'length' => 15],
            'content_items' => [
                ['conteudo' => 'Kit organizador', 'quantidade' => 1, 'valor' => 89.9],
            ],
        ], $overrides);
    }

    public function test_index_lists_only_records_from_the_selected_month(): void
    {
        $admin = $this->admin();

        CorreiosPrePostagem::create($this->recordAttributes(['customer_name' => 'Cliente Deste Mês']));

        $old = CorreiosPrePostagem::create($this->recordAttributes(['customer_name' => 'Cliente Antigo']));
        $old->created_at = now()->subMonths(2);
        $old->save();

        $response = $this->actingAs($admin)->get('/admin/correios');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Correios/Index')
            ->has('items.data', 1)
            ->where('items.data.0.customerName', 'Cliente Deste Mês'));
    }

    public function test_index_filters_by_customer_or_order_search(): void
    {
        $admin = $this->admin();

        CorreiosPrePostagem::create($this->recordAttributes(['customer_name' => 'Ana Souza', 'external_order_id' => 'ML-999']));
        CorreiosPrePostagem::create($this->recordAttributes(['customer_name' => 'Bruno Lima', 'external_order_id' => 'ML-111']));

        $response = $this->actingAs($admin)->get('/admin/correios?pedido=ML-999');

        $response->assertInertia(fn ($page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.customerName', 'Ana Souza'));
    }

    public function test_store_without_credentials_does_not_persist_and_shows_error(): void
    {
        config(['services.correios.numero_usuario' => null, 'services.correios.codigo_acesso' => null]);
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/admin/correios', $this->payload());

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(0, CorreiosPrePostagem::count());
    }

    public function test_store_creates_record_and_qr_payload_on_success(): void
    {
        config(['services.correios.numero_usuario' => '65604590000107', 'services.correios.codigo_acesso' => 'token-teste']);
        $this->makeCompany();

        Http::fake([
            '*/token/v1/autentica' => Http::response(['token' => 'bearer-abc'], 201),
            '*/prepostagem/v1/prepostagens' => Http::response(['id' => '999888', 'codigoObjeto' => 'OA123456789BR'], 201),
        ]);

        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/admin/correios', $this->payload());

        $record = CorreiosPrePostagem::firstOrFail();
        $response->assertRedirect(route('admin.correios.ver', $record));
        $this->assertSame(CorreiosPrePostagem::STATUS_GERADA, $record->status);
        $this->assertSame('OA123456789BR', $record->codigo_objeto);
        $this->assertSame('OA123456789BR', $record->qr_payload);
        $this->assertSame('Maria da Silva', $record->customer_name);
    }

    public function test_store_records_failed_attempt_when_correios_rejects_it(): void
    {
        config(['services.correios.numero_usuario' => '65604590000107', 'services.correios.codigo_acesso' => 'token-teste']);
        $this->makeCompany();

        Http::fake([
            '*/token/v1/autentica' => Http::response(['token' => 'bearer-abc'], 201),
            '*/prepostagem/v1/prepostagens' => Http::response(['mensagem' => 'CEP do destinatário inválido'], 400),
        ]);

        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/admin/correios', $this->payload());

        $record = CorreiosPrePostagem::firstOrFail();
        $response->assertRedirect(route('admin.correios.ver', $record));
        $this->assertSame(CorreiosPrePostagem::STATUS_ERRO, $record->status);
        $this->assertSame('CEP do destinatário inválido', $record->error_message);
    }

    public function test_edit_shows_form_prefilled_when_status_is_erro(): void
    {
        $admin = $this->admin();
        $record = CorreiosPrePostagem::create($this->recordAttributes(['status' => CorreiosPrePostagem::STATUS_ERRO, 'error_message' => 'CEP inválido']));

        $response = $this->actingAs($admin)->get("/admin/correios/{$record->id}/editar");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Correios/Create')
            ->where('editing.id', $record->id)
            ->where('editing.customer.name', 'Cliente Teste')
            ->where('editing.errorMessage', 'CEP inválido'));
    }

    public function test_edit_redirects_when_status_is_not_erro(): void
    {
        $admin = $this->admin();
        $record = CorreiosPrePostagem::create($this->recordAttributes(['status' => CorreiosPrePostagem::STATUS_GERADA]));

        $response = $this->actingAs($admin)->get("/admin/correios/{$record->id}/editar");

        $response->assertRedirect(route('admin.correios.ver', $record));
        $response->assertSessionHas('error');
    }

    public function test_update_retries_and_marks_gerada_on_success(): void
    {
        config(['services.correios.numero_usuario' => '65604590000107', 'services.correios.codigo_acesso' => 'token-teste']);
        $this->makeCompany();
        $admin = $this->admin();
        $record = CorreiosPrePostagem::create($this->recordAttributes(['status' => CorreiosPrePostagem::STATUS_ERRO, 'error_message' => 'Erro anterior']));

        Http::fake([
            '*/token/v1/autentica' => Http::response(['token' => 'bearer-abc'], 201),
            '*/prepostagem/v1/prepostagens' => Http::response(['id' => '999888', 'codigoObjeto' => 'OA123456789BR'], 201),
        ]);

        $response = $this->actingAs($admin)->put("/admin/correios/{$record->id}", $this->payload(['customer' => ['name' => 'Maria Corrigida']]));

        $record->refresh();
        $response->assertRedirect(route('admin.correios.ver', $record));
        $this->assertSame(1, CorreiosPrePostagem::count());
        $this->assertSame(CorreiosPrePostagem::STATUS_GERADA, $record->status);
        $this->assertSame('Maria Corrigida', $record->customer_name);
        $this->assertNull($record->error_message);
    }

    public function test_update_redirects_when_status_is_not_erro(): void
    {
        $admin = $this->admin();
        $record = CorreiosPrePostagem::create($this->recordAttributes(['status' => CorreiosPrePostagem::STATUS_GERADA]));

        $response = $this->actingAs($admin)->put("/admin/correios/{$record->id}", $this->payload());

        $response->assertRedirect(route('admin.correios.ver', $record));
        $response->assertSessionHas('error');
        $this->assertSame('Cliente Teste', $record->fresh()->customer_name);
    }

    public function test_buscar_pedido_returns_404_json_when_not_found(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->getJson('/admin/correios/buscar-pedido?numero=999999');

        $response->assertNotFound();
        $response->assertJsonStructure(['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function recordAttributes(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Cliente Teste',
            'zip' => '01000-000',
            'street' => 'Rua Teste',
            'number' => '10',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'service_code' => '03298',
            'service_label' => 'PAC (contrato)',
            'weight_grams' => 500,
            'content_items' => [['conteudo' => 'Produto', 'quantidade' => 1, 'valor' => 50]],
            'status' => CorreiosPrePostagem::STATUS_GERADA,
        ], $overrides);
    }
}
