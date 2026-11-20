<?php

namespace Tests\Feature;

use App\Modules\Financeiro\Support\PrevisaoFornecedores;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailPrevisaoFornecedoresTest extends TestCase
{
    public function test_database_adapter_reads_approved_payment_and_preserves_unknown_supplier(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $db = DB::connection();
        $db->statement('CREATE TABLE orders (id INTEGER, origin TEXT, status TEXT, fiscal_operation_type TEXT, estornado_em TEXT, created_at TEXT)');
        $db->statement('CREATE TABLE order_items (order_id INTEGER, product_id INTEGER, product_name TEXT, quantity INTEGER, manual_cost_price REAL)');
        $db->statement('CREATE TABLE products (id INTEGER, sku TEXT, variation TEXT, cost_price REAL)');
        $db->statement('CREATE TABLE mercado_pago_pagamentos (order_id INTEGER, status TEXT, data_aprovacao TEXT)');
        $db->statement('CREATE TABLE marketplace_returns (order_id INTEGER)');
        $db->table('orders')->insert(['id' => 123, 'origin' => 'shopee', 'status' => 'paid', 'created_at' => '2026-01-01']);
        $db->table('order_items')->insert(['order_id' => 123, 'product_id' => 1, 'product_name' => 'Teste', 'quantity' => 2]);
        $db->table('products')->insert(['id' => 1, 'sku' => 'SKU', 'cost_price' => 10]);
        $db->table('mercado_pago_pagamentos')->insert(['order_id' => 123, 'status' => 'approved', 'data_aprovacao' => '2026-11-13 12:00:00']);
        $r = (new PrevisaoFornecedores)->calcular('2026-11-23');
        self::assertCount(1, $r['pendencias']);
        self::assertSame(123, $r['pendencias'][0]['order_id']);
        self::assertSame(2000, $r['pendencias'][0]['total_centavos']);
        self::assertNull($r['pendencias'][0]['supplier']);
        self::assertStringContainsString('aprovação comprador', $r['pendencias'][0]['base_fonte']);
    }

    public function test_no_email_before_start_or_future_date(): void
    {
        Mail::fake();
        $this->travelTo(now()->setDate(2026, 11, 20));
        $this->artisan('financeiro:fornecedores-d10')->assertSuccessful();
        $this->artisan('financeiro:fornecedores-d10 --data=2026-11-23')->assertFailed();
        Mail::assertNothingSent();
    }

    public function test_mail_failure_never_persists_sent_state(): void
    {
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 11, 23)->setTime(6, 0));
        $this->app->instance('env', 'production');
        $this->mock(PrevisaoFornecedores::class, function ($m) {
            $m->shouldReceive('elegivel')->andReturn(true);
            $m->shouldReceive('calcular')->andReturn([]);
            $m->shouldReceive('texto')->andReturn('Previsão');
            $m->shouldReceive('csv')->andReturn('CSV');
        });
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP indisponível'));
        try {
            $this->artisan('financeiro:fornecedores-d10')->run();
            self::fail('Deveria propagar falha SMTP');
        } catch (\RuntimeException $e) {
            self::assertSame('SMTP indisponível', $e->getMessage());
        }
        Storage::disk('local')->assertMissing('automation/fornecedores-d10/2026-11-23.json');
    }

    public function test_dry_run_does_not_send_or_persist_and_live_is_idempotent(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 11, 23)->setTime(6, 0));
        $this->mock(PrevisaoFornecedores::class, function ($m) {
            $m->shouldReceive('elegivel')->andReturn(true);
            $m->shouldReceive('calcular')->twice()->andReturn(['data' => '2026-11-23', 'fornecedores' => [], 'pendencias' => [], 'total_centavos' => 0]);
            $m->shouldReceive('texto')->twice()->andReturn('Previsão');
            $m->shouldReceive('csv')->twice()->andReturn('Fornecedor;Produto');
        });
        $this->artisan('financeiro:fornecedores-d10 --dry-run')->assertSuccessful();
        Mail::assertNothingSent();
        Storage::disk('local')->assertMissing('automation/fornecedores-d10/2026-11-23.json');
        $this->app->instance('env', 'production');
        $this->artisan('financeiro:fornecedores-d10')->assertSuccessful();
        $this->artisan('financeiro:fornecedores-d10')->assertSuccessful();
        Mail::assertSentCount(1);
        Storage::disk('local')->assertExists('automation/fornecedores-d10/2026-11-23.json');
    }
}
