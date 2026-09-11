<?php

namespace Tests\Feature\Api;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintAgentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer test-print-agent-token'];
    }

    private function makeOrder(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'status' => Order::STATUS_PAID,
            'origin' => 'shopee',
            'external_order_id' => 'VENDA-'.uniqid(),
            'shipping_name' => 'Cliente Teste',
            'shipping_phone' => 'Não informado',
            'shipping_zip' => '00000000',
            'shipping_street' => 'Rua Teste',
            'shipping_number' => 'S/N',
            'shipping_neighborhood' => 'Não informado',
            'shipping_city' => 'Não informado',
            'shipping_state' => 'SP',
            'subtotal' => 0,
            'shipping_cost' => 0,
            'total' => 0,
        ], $attributes));
    }

    public function test_jobs_list_includes_the_order_sale_id_alongside_the_internal_order_id(): void
    {
        $order = $this->makeOrder(['external_order_id' => '2608091234567']);

        $job = PrintJob::create([
            'order_id' => $order->id,
            'channel' => 'shopee',
            'tracking_code' => null,
            'label_path' => 'labels/teste.pdf',
            'status' => PrintJob::STATUS_QUEUED,
        ]);

        $response = $this->getJson('/api/print-agent/jobs', $this->authHeaders());

        $response->assertOk();
        $response->assertJson([
            'jobs' => [
                [
                    'id' => $job->id,
                    'order_id' => $order->id,
                    'channel' => 'shopee',
                    'tracking_code' => null,
                    'sale_id' => '2608091234567',
                ],
            ],
        ]);
    }

    public function test_jobs_list_reports_a_null_sale_id_for_a_manual_label_without_a_real_order(): void
    {
        PrintJob::create([
            'order_id' => null,
            'channel' => null,
            'label_path' => 'labels/manual.pdf',
            'status' => PrintJob::STATUS_QUEUED,
        ]);

        $response = $this->getJson('/api/print-agent/jobs', $this->authHeaders());

        $response->assertOk();
        $response->assertJsonPath('jobs.0.sale_id', null);
    }

    /**
     * Dois agentes (duas instalações do KoraSync na loja, ou o app aberto
     * duas vezes) não podem imprimir a mesma etiqueta: só quem reivindica
     * primeiro baixa o arquivo. A segunda reivindicação leva 409 e o
     * claimed_by continua sendo o do primeiro.
     */
    public function test_a_second_agent_cannot_claim_a_job_that_is_already_claimed(): void
    {
        $order = $this->makeOrder();

        $job = PrintJob::create([
            'order_id' => $order->id,
            'channel' => 'shopee',
            'label_path' => 'labels/teste.pdf',
            'status' => PrintJob::STATUS_QUEUED,
        ]);

        $this->postJson("/api/print-agent/jobs/{$job->id}/claim", ['agent_id' => 'PC-DA-LOJA'], $this->authHeaders())
            ->assertOk();

        $this->postJson("/api/print-agent/jobs/{$job->id}/claim", ['agent_id' => 'PC-DO-ESCRITORIO'], $this->authHeaders())
            ->assertStatus(409);

        $job->refresh();

        $this->assertSame(PrintJob::STATUS_CLAIMED, $job->status);
        $this->assertSame('PC-DA-LOJA', $job->claimed_by);
    }

    /**
     * Pergunta do usuário (2026-09-10): "outros agentes rodam em outros pc
     * e notebook, isso pode duplicar impressão?".
     *
     * Duplicar não — a reivindicação é atômica. Mas quem reivindica
     * imprime na PRÓPRIA impressora: um notebook com o agente ligado leva
     * a etiqueta embora, e na bancada isso é igual a "a etiqueta não veio".
     */
    public function test_only_the_allowed_machine_can_claim_a_label(): void
    {
        config(['services.print_agent.allowed_agents' => ['KazaKora-PC']]);

        $order = $this->makeOrder();

        $job = PrintJob::create([
            'order_id' => $order->id,
            'channel' => 'shopee',
            'label_path' => 'labels/teste.pdf',
            'status' => PrintJob::STATUS_QUEUED,
        ]);

        $this->postJson("/api/print-agent/jobs/{$job->id}/claim", ['agent_id' => 'NOTEBOOK-DO-JOSE'], $this->authHeaders())
            ->assertStatus(403);

        $this->assertSame(PrintJob::STATUS_QUEUED, $job->refresh()->status);

        $this->postJson("/api/print-agent/jobs/{$job->id}/claim", ['agent_id' => 'KazaKora-PC'], $this->authHeaders())
            ->assertOk();

        $this->assertSame(PrintJob::STATUS_CLAIMED, $job->refresh()->status);
    }

    /** Sem lista configurada, segue como sempre foi: qualquer agente pega. */
    public function test_without_an_allowlist_any_agent_can_claim(): void
    {
        config(['services.print_agent.allowed_agents' => []]);

        $order = $this->makeOrder();

        $job = PrintJob::create([
            'order_id' => $order->id,
            'channel' => 'shopee',
            'label_path' => 'labels/teste.pdf',
            'status' => PrintJob::STATUS_QUEUED,
        ]);

        $this->postJson("/api/print-agent/jobs/{$job->id}/claim", ['agent_id' => 'QUALQUER-PC'], $this->authHeaders())
            ->assertOk();
    }

    public function test_jobs_list_only_returns_queued_jobs(): void
    {
        $order = $this->makeOrder();

        PrintJob::create([
            'order_id' => $order->id,
            'channel' => 'shopee',
            'label_path' => 'labels/teste.pdf',
            'status' => PrintJob::STATUS_PRINTED,
        ]);

        $response = $this->getJson('/api/print-agent/jobs', $this->authHeaders());

        $response->assertOk();
        $response->assertJsonCount(0, 'jobs');
    }
}
