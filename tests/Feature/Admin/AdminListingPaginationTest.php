<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Models\CashFlowEntry;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\OrderChannelFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Notas Fiscais, Clientes e Fluxo de Caixa passaram a paginar no servidor
 * (2026-09-29 — "admin muito lento": as telas mandavam milhares de linhas
 * pro navegador). Estes testes garantem que nada que dava pra fazer na
 * tabela antes (aba, busca, ordenação, filtro de plataforma/período) se
 * perdeu, e que os cards de total continuam sobre o conjunto filtrado
 * INTEIRO, não só sobre a página exibida.
 *
 * Valores com centavo de propósito (mesma armadilha documentada em
 * FinancialDashboardNetProfitTest: json_encode(100.0) === "100").
 */
class AdminListingPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function makeOrder(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ], $attributes));
    }

    // Uma nota por pedido (invoices.order_id é único) — sem pedido
    // informado, cria um só pra ela.
    private function makeInvoice(?Order $order, int $numero, string $status, float $valor): Invoice
    {
        return Invoice::create([
            'order_id' => ($order ?? $this->makeOrder())->id,
            'status' => $status,
            'serie' => 1,
            'numero' => $numero,
            'valor_total' => $valor,
            'chave_acesso' => str_pad((string) $numero, 44, '0', STR_PAD_LEFT),
        ]);
    }

    public function test_invoices_are_paginated_and_summary_counts_every_invoice(): void
    {
        foreach (range(1, 55) as $numero) {
            $this->makeInvoice(null, $numero, Invoice::STATUS_AUTHORIZED, 10.25);
        }
        $this->makeInvoice(null, 900, Invoice::STATUS_CANCELLED, 5.5);
        $this->makeInvoice(null, 901, Invoice::STATUS_ERROR, 1.25);

        $response = $this->actingAs($this->admin())->get('/admin/notas-fiscais');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('invoices.data', 50)
            ->where('invoices.total', 57)
            ->where('invoices.last_page', 2)
            // Cards = todas as notas, não só a página.
            ->where('summary.authorized_count', 55)
            ->where('summary.authorized_total', 563.75)
            ->where('summary.cancelled_count', 1)
            ->where('summary.cancelled_total', 5.5)
            ->where('summary.failed_count', 1));
    }

    public function test_invoice_tab_search_and_sort_are_handled_server_side(): void
    {
        $shopeeOrder = $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SHP-777']);
        $storeOrder = $this->makeOrder();

        $this->makeInvoice($shopeeOrder, 10, Invoice::STATUS_AUTHORIZED, 30.5);
        $this->makeInvoice($storeOrder, 11, Invoice::STATUS_CANCELLED, 20.5);
        $this->makeInvoice(null, 12, Invoice::STATUS_REJECTED, 10.5);

        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/notas-fiscais?status=failed_group')
            ->assertInertia(fn ($page) => $page
                ->has('invoices.data', 1)
                ->where('invoices.data.0.numero', 12)
                ->where('filters.status', 'failed_group')
                // Aba não mexe nos cards (igual antes).
                ->where('summary.cancelled_count', 1));

        // Busca pelo pedido na plataforma e pelo nome da plataforma.
        $this->actingAs($admin)->get('/admin/notas-fiscais?search=SHP-777')
            ->assertInertia(fn ($page) => $page->has('invoices.data', 1)->where('invoices.data.0.numero', 10));
        $this->actingAs($admin)->get('/admin/notas-fiscais?search=shopee')
            ->assertInertia(fn ($page) => $page->has('invoices.data', 1)->where('invoices.data.0.numero', 10));
        // "número/série", como a coluna Nota mostra.
        $this->actingAs($admin)->get('/admin/notas-fiscais?search=11/1')
            ->assertInertia(fn ($page) => $page->has('invoices.data', 1)->where('invoices.data.0.numero', 11));

        $this->actingAs($admin)->get('/admin/notas-fiscais?sort=valor_total&direction=asc')
            ->assertInertia(fn ($page) => $page
                ->where('invoices.data.0.numero', 12)
                ->where('invoices.data.2.numero', 10)
                ->where('filters.sort', 'valor_total'));

        // Coluna fora da lista permitida cai na ordenação padrão, sem erro.
        $this->actingAs($admin)->get('/admin/notas-fiscais?sort=motivo_rejeicao;drop&direction=asc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.sort', null));
    }

    public function test_customers_are_grouped_by_document_paginated_searchable_and_sortable(): void
    {
        // Mesmo comprador no site (CPF do usuário, com máscara) e na Shopee
        // (buyer_document só dígitos) = um cliente só.
        $user = User::factory()->create(['cpf' => '123.456.789-09', 'name' => 'Maria Site']);
        $this->makeOrder(['user_id' => $user->id, 'total' => 50.25, 'shipping_name' => 'Maria Silva']);
        $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'buyer_document' => '12345678909', 'total' => 20.5, 'shipping_name' => 'Maria Silva']);

        foreach (range(1, 54) as $i) {
            $this->makeOrder([
                'origin' => Order::ORIGIN_SHOPEE,
                'buyer_document' => str_pad((string) $i, 11, '0', STR_PAD_LEFT),
                'shipping_name' => "Comprador {$i}",
                'total' => $i + 0.5,
            ]);
        }

        // Sem documento não entra (regra de sempre).
        $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'buyer_document' => null, 'shipping_name' => 'Anônimo']);

        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/clientes')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('customers.data', 50)
                ->where('customers.total', 55));

        $this->actingAs($admin)->get('/admin/clientes?search=123.456.789')
            ->assertInertia(fn ($page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.document', '12345678909')
                ->where('customers.data.0.orders_count', 2)
                ->where('customers.data.0.total_spent', 70.75)
                ->where('customers.data.0.origins', ['loja', 'shopee']));

        $this->actingAs($admin)->get('/admin/clientes?search=maria')
            ->assertInertia(fn ($page) => $page->has('customers.data', 1));

        $this->actingAs($admin)->get('/admin/clientes?sort=total_spent&direction=desc')
            ->assertInertia(fn ($page) => $page
                ->where('customers.data.0.document', '12345678909')
                ->where('customers.data.1.total_spent', 54.5)
                ->where('filters.sort', 'total_spent'));

        $this->actingAs($admin)->get('/admin/clientes?sort=total_spent&direction=asc&page=2')
            ->assertInertia(fn ($page) => $page
                ->has('customers.data', 5)
                ->where('customers.current_page', 2));
    }

    public function test_customer_analytics_page_still_shows_orders_and_products(): void
    {
        $product = Product::factory()->create();
        $order = $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'buyer_document' => '98765432100', 'total' => 40.5]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'product_price' => 20.25, 'quantity' => 2, 'subtotal' => 40.5]);
        $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'buyer_document' => '11111111111']);

        $this->actingAs($this->admin())->get('/admin/clientes/987.654.321-00')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('customer.document', '98765432100')
                ->has('orders', 1)
                ->where('products.0.quantity', 2)
                ->where('products.0.total_spent', 40.5));
    }

    public function test_cash_flow_sales_lines_match_contribution_margin_math(): void
    {
        // Mesmo cenário real de FinancialDashboardNetProfitTest (Amazon com
        // taxa do Bling + Correios da loja + frete recebido do comprador):
        // 100,50 + 20,25 − 60,50 − 15,25 − 22,75 = 22,25.
        $product = Product::factory()->create(['cost_price' => 30.25]);
        $order = $this->makeOrder(['origin' => Order::ORIGIN_AMAZON, 'subtotal' => 100.5, 'shipping_cost' => 20.25, 'total' => 120.75]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => 'Produto Amazon', 'product_price' => 50.25, 'quantity' => 2, 'subtotal' => 100.5]);
        OrderChannelFee::create(['order_id' => $order->id, 'channel' => 'amazon', 'gross_amount' => 100.5, 'fee_amount' => 15.25, 'source' => OrderChannelFee::SOURCE_API, 'computed_at' => now()]);
        CorreiosPrePostagem::create([
            'order_id' => $order->id, 'origin' => 'amazon', 'customer_name' => 'Maria', 'zip' => '13010000', 'street' => 'Rua',
            'number' => '1', 'neighborhood' => 'Centro', 'city' => 'Campinas', 'state' => 'SP', 'service_code' => '03298',
            'service_label' => 'PAC (contrato)', 'postage_price' => 22.75, 'weight_grams' => 400, 'dimension_format' => '2',
            'content_items' => [], 'status' => CorreiosPrePostagem::STATUS_GERADA,
        ]);

        // Mercado Livre Flex com dois itens: comissão e frete rateados
        // proporcionalmente (30% / 70%), sem comissão = "incompleto".
        $ml = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'subtotal' => 100, 'total' => 100]);
        $ml->forceFill(['created_at' => Carbon::now()->subMonths(2)])->save();
        $ml->items()->create(['product_id' => null, 'product_name' => 'Item A', 'product_price' => 30, 'quantity' => 1, 'subtotal' => 30, 'manual_cost_price' => 10.5]);
        $ml->items()->create(['product_id' => null, 'product_name' => 'Item B', 'product_price' => 70, 'quantity' => 1, 'subtotal' => 70]);
        ChannelShipment::create(['order_id' => $ml->id, 'channel' => 'mercado_livre', 'shipping_method' => 'self_service']);

        // Pedido cancelado não é venda.
        $cancelled = $this->makeOrder(['status' => Order::STATUS_CANCELLED]);
        $cancelled->items()->create(['product_id' => null, 'product_name' => 'Cancelado', 'product_price' => 100, 'quantity' => 1, 'subtotal' => 100]);

        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/fluxo-de-caixa?sort=product_name&direction=asc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 3)
                ->where('sales.data.0.product_name', 'Item A')
                ->where('sales.data.0.product_cost', 10.5)
                ->where('sales.data.0.has_cost', true)
                ->where('sales.data.0.has_fee_data', false)
                ->where('sales.data.0.platform_fee', 0)
                // Flex 12,99 × 30% = 3,90 (arredondado).
                ->where('sales.data.0.shipping_cost', 3.9)
                ->where('sales.data.0.net_profit', 15.6)
                ->where('sales.data.1.product_name', 'Item B')
                ->where('sales.data.1.has_cost', false)
                ->where('sales.data.1.shipping_cost', 9.09)
                ->where('sales.data.2.product_name', 'Produto Amazon')
                ->where('sales.data.2.platform', 'Amazon')
                ->where('sales.data.2.product_cost', 60.5)
                ->where('sales.data.2.platform_fee', 15.25)
                ->where('sales.data.2.shipping_cost', 2.5)
                ->where('sales.data.2.net_profit', 22.25)
                ->where('salesTotals.cost', 71)
                ->where('salesTotals.fee', 15.25)
                ->where('salesTotals.shipping', 15.49)
                ->where('salesTotals.netProfit', 98.76)
                ->where('salesPlatforms', [
                    ['value' => 'amazon', 'label' => 'Amazon'],
                    ['value' => 'mercado_livre', 'label' => 'Mercado Livre'],
                ]));

        // Plataforma + período filtram linhas E totais.
        $this->actingAs($admin)->get('/admin/fluxo-de-caixa?platform=mercado_livre')
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 2)
                ->where('salesTotals.cost', 10.5)
                ->where('salesFilter.platform', 'mercado_livre'));

        $this->actingAs($admin)->get('/admin/fluxo-de-caixa?start='.now()->startOfMonth()->toDateString())
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 1)
                ->where('salesTotals.netProfit', 22.25));

        // Busca por produto e por nome da plataforma.
        $this->actingAs($admin)->get('/admin/fluxo-de-caixa?search=item b')
            ->assertInertia(fn ($page) => $page->has('sales.data', 1)->where('salesTotals.shipping', 9.09));
        $this->actingAs($admin)->get('/admin/fluxo-de-caixa?search=mercado')
            ->assertInertia(fn ($page) => $page->has('sales.data', 2));
    }

    public function test_cash_flow_entries_are_paginated_and_totals_cover_all_entries(): void
    {
        $admin = $this->admin();

        foreach (range(1, 52) as $i) {
            CashFlowEntry::create([
                'type' => CashFlowEntry::TYPE_INCOME,
                'description' => "Entrada {$i}",
                'amount' => 10.5,
                'entry_date' => now()->subDays($i)->toDateString(),
                'created_by' => $admin->id,
            ]);
        }
        CashFlowEntry::create([
            'type' => CashFlowEntry::TYPE_EXPENSE,
            'description' => 'Aluguel',
            'amount' => 100.25,
            'entry_date' => now()->toDateString(),
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->get('/admin/fluxo-de-caixa')
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 50)
                ->where('entries.total', 53)
                ->where('entries.data.0.description', 'Aluguel')
                ->where('summary.income', 546)
                ->where('summary.expense', 100.25)
                ->where('summary.balance', 445.75));

        $this->actingAs($admin)->get('/admin/fluxo-de-caixa?entries_search=aluguel')
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 1)
                // Busca da tabela não mexe nos cards (igual antes).
                ->where('summary.income', 546));

        $this->actingAs($admin)->get('/admin/fluxo-de-caixa?entries_sort=amount&entries_direction=asc&entries_page=2')
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 3)
                ->where('entries.data.2.description', 'Aluguel')
                ->where('entriesFilter.sort', 'amount'));
    }

    public function test_product_listing_only_sends_the_columns_the_table_uses(): void
    {
        $parent = Product::factory()->create(['name' => 'Ring Light', 'description' => str_repeat('descrição longa ', 50)]);
        Product::factory()->create(['name' => 'Ring Light 10"', 'parent_product_id' => $parent->id]);

        $this->actingAs($this->admin())->get('/admin/produtos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products', 2)
                ->where('products', fn ($products) => collect($products)->every(fn ($product) => ! array_key_exists('description', $product) && ! array_key_exists('final_price', $product)))
                ->where('products', fn ($products) => collect($products)->firstWhere('id', $parent->id)['children_count'] === 1)
                ->where('products', fn ($products) => collect($products)->firstWhere('parent_product_id', $parent->id)['parent']['name'] === 'Ring Light'));
    }
}
