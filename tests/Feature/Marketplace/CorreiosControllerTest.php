<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
            // false: as páginas ficam em resources/js/Modules (ver app.js),
            // não no resources/js/pages que o Inertia confere por padrão.
            ->component('Admin/Correios/Index', false)
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

    public function test_index_totals_only_generated_labels_of_the_month_including_returns(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $admin = $this->admin();

        $this->postagem(['customer_name' => 'Ana Souza', 'postage_price' => 100.10], '2026-10-01 00:00:00');
        $this->postagem(['customer_name' => 'Bruno Lima', 'postage_price' => 26.85], '2026-10-31 23:59:59');
        $this->postagem(['customer_name' => 'Devolução', 'postage_price' => 300.00, 'devolucao' => true], '2026-10-10 10:00:00');
        $this->postagem(['customer_name' => 'Sem valor', 'postage_price' => null], '2026-10-10 10:00:00');

        $this->postagem(['status' => CorreiosPrePostagem::STATUS_CANCELADA, 'postage_price' => 1000.00], '2026-10-10 10:00:00');
        $this->postagem(['status' => CorreiosPrePostagem::STATUS_ERRO, 'postage_price' => 2000.00], '2026-10-10 10:00:00');
        $this->postagem(['postage_price' => 4000.00], '2026-09-30 23:59:59');
        $this->postagem(['postage_price' => 8000.00], '2026-11-01 00:00:00');

        $this->actingAs($admin)->get('/admin/correios?mes=2026-10')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('totalAPagar', 426.95));

        // O outro mês tem o próprio total.
        $this->actingAs($admin)->get('/admin/correios?mes=2026-09')
            ->assertInertia(fn ($page) => $page->where('totalAPagar', 4000));
    }

    public function test_index_total_does_not_change_with_search_or_page(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $admin = $this->admin();

        $this->postagem(['customer_name' => 'Ana Souza', 'postage_price' => 10.50], '2026-10-10 10:00:00');
        // 30 etiquetas: passa dos 25 por página.
        foreach (range(1, 30) as $i) {
            $this->postagem(['customer_name' => "Bruno {$i}", 'postage_price' => 2.00], '2026-10-10 10:00:00');
        }

        $this->actingAs($admin)->get('/admin/correios?mes=2026-10&pedido=Ana')
            ->assertInertia(fn ($page) => $page
                ->has('items.data', 1)
                ->where('filters.pedido', 'Ana')
                ->where('totalAPagar', 70.5));

        $this->actingAs($admin)->get('/admin/correios?mes=2026-10&pedido=ninguem-com-esse-nome')
            ->assertInertia(fn ($page) => $page
                ->has('items.data', 0)
                ->where('totalAPagar', 70.5));

        foreach (['', '&page=2'] as $pagina) {
            $this->actingAs($admin)->get("/admin/correios?mes=2026-10{$pagina}")
                ->assertInertia(fn ($page) => $page->where('totalAPagar', 70.5));
        }
    }

    public function test_index_total_is_zero_in_a_month_without_generated_labels(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');

        $this->actingAs($this->admin())->get('/admin/correios?mes=2026-10')
            ->assertInertia(fn ($page) => $page->where('totalAPagar', 0));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postagem(array $overrides, string $createdAt): CorreiosPrePostagem
    {
        $postagem = CorreiosPrePostagem::create($this->recordAttributes($overrides));
        $postagem->created_at = $createdAt;
        $postagem->save();

        return $postagem;
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
            ->component('Admin/Correios/Create', false)
            ->where('editing.id', $record->id)
            ->where('editing.customer.name', 'Cliente Teste')
            ->where('editing.errorMessage', 'CEP inválido'));
    }

    public function test_etiqueta_serves_the_same_pdf_as_the_automatic_print(): void
    {
        $this->makeCompany();
        $record = CorreiosPrePostagem::create($this->recordAttributes(['status' => CorreiosPrePostagem::STATUS_GERADA]));

        $response = $this->actingAs($this->admin())->get("/admin/correios/{$record->id}/etiqueta.pdf");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_etiqueta_is_404_while_the_pre_postagem_has_no_qr(): void
    {
        $record = CorreiosPrePostagem::create($this->recordAttributes(['status' => CorreiosPrePostagem::STATUS_ERRO]));

        $this->actingAs($this->admin())->get("/admin/correios/{$record->id}/etiqueta.pdf")->assertNotFound();
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
