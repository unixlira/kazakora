<?php

namespace Tests\Feature\Financeiro;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Financeiro\Mail\AuditoriaDaSemana;
use App\Modules\Financeiro\Support\AuditoriaSemanal;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Auditoria semanal do dinheiro que caiu (pedido do Lira em 12/11/2026):
 * soma o que cada canal depositou na semana fechada e diz quanto separar
 * para imposto, fornecedor e insumos, e quanto sobra de lucro.
 */
class AuditoriaSemanalTest extends TestCase
{
    use RefreshDatabase;

    private array $carteira = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['loja.simples.aliquota' => 4, 'loja.insumos_por_pedido' => 2, 'loja.email_relatorios' => 'lira@example.com']);
        // Segunda-feira 16/11/2026, 14h: a semana fechada é 09/11 a 15/11.
        Carbon::setTestNow('2026-11-16 14:00:00');
        Storage::fake('local');

        $shopee = Mockery::mock(ShopeeClient::class);
        $shopee->shouldReceive('get')->andReturnUsing(fn () => ['response' => ['transaction_list' => $this->carteira, 'more' => false]]);
        $this->app->instance(ShopeeClient::class, $shopee);
    }

    private function pedido(string $origem, string $codigo, float $total, float $custo, ?float $nota = null): Order
    {
        $produto = Product::factory()->create(['price' => $total, 'cost_price' => $custo, 'stock' => 10]);
        $pedido = Order::create([
            'status' => Order::STATUS_COMPLETED, 'origin' => $origem, 'external_order_id' => $codigo,
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => $total, 'discount_amount' => 0, 'shipping_cost' => 0, 'total' => $total,
        ]);
        $pedido->forceFill(['created_at' => '2026-11-03 10:00:00'])->save();
        $pedido->items()->create(['product_id' => $produto->id, 'product_name' => 'Item', 'product_price' => $total, 'quantity' => 1, 'subtotal' => $total, 'item_type' => OrderItem::TYPE_PRODUCT]);
        if ($nota !== null) {
            DB::table('invoices')->insert(['order_id' => $pedido->id, 'numero' => random_int(1, 999999), 'status' => 'authorized', 'operation_type' => 'saida', 'valor_total' => $nota, 'autorizada_em' => '2026-11-03 11:00:00', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $pedido;
    }

    private function extrato(string $canal, string $status, string $tipo, ?string $codigo, string $dia, float $valor): void
    {
        DB::table('marketplace_settlement_details')->insert([
            'channel' => $canal, 'statement_id' => 'st-'.$dia, 'status' => $status, 'transaction_type' => $tipo,
            'external_order_id' => $codigo, 'settlement_date' => $dia, 'payout_amount' => $valor,
            'source_file' => 'a.xlsx', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mercadoPago(?Order $pedido, string $liberacao, float $liquido, float $reembolsado = 0, string $status = 'approved'): void
    {
        static $n = 1;
        DB::table('mercado_pago_pagamentos')->insert([
            'mp_payment_id' => 'mp-'.$n++, 'status' => $status, 'canal' => 'mercado_livre', 'order_id' => $pedido?->id,
            'valor' => $liquido + 10, 'liquido' => $liquido, 'reembolsado' => $reembolsado,
            'data_criacao' => '2026-11-02 10:00:00', 'data_liberacao' => $liberacao, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function semana(): array
    {
        return app(AuditoriaSemanal::class)->calcular(...AuditoriaSemanal::semanaFechada());
    }

    public function test_semana_fechada_e_de_segunda_a_domingo_antes_de_hoje(): void
    {
        [$de, $ate] = AuditoriaSemanal::semanaFechada();

        $this->assertSame('2026-11-09 00:00:00', $de->toDateTimeString());
        $this->assertSame('2026-11-16 00:00:00', $ate->toDateTimeString());
        // No meio da semana também: quarta 18/11 ainda audita 09 a 15/11.
        $this->assertSame('2026-11-09', AuditoriaSemanal::semanaFechada(Carbon::parse('2026-11-18 09:00:00'))[0]->toDateString());
    }

    public function test_tiktok_soma_o_extrato_pago_da_semana_e_separa_pelos_pedidos(): void
    {
        $a = $this->pedido('tiktok_shop', 'TT-1', 100, 40, nota: 90);
        $this->pedido('tiktok_shop', 'TT-2', 60, 25);
        $this->extrato('tiktok_shop', 'Pagos', 'Pedido', 'TT-1', '2026-11-09', 70);
        $this->extrato('tiktok_shop', 'Pagos', 'Pedido', 'TT-2', '2026-11-15', 45);
        // GMV Max descontado no próprio extrato: baixa o que caiu.
        $this->extrato('tiktok_shop', 'Pagos', 'Pagamento de GMV para anúncios do TikTok', null, '2026-11-12', -15);
        // Fora da semana, ainda não pago e outra semana: não entram.
        $this->extrato('tiktok_shop', 'Pagos', 'Pedido', 'TT-1', '2026-11-16', 999);
        $this->extrato('tiktok_shop', 'Pagamento pendente', 'Pedido', 'TT-2', '2026-11-10', 999);
        $this->extrato('tiktok_shop', 'Pagos', 'Pedido', 'TT-1', '2026-11-08', 999);

        $tiktok = $this->semana()['canais']['tiktok_shop'];

        $this->assertSame(100.0, $tiktok['depositado']); // 70 + 45 − 15
        $this->assertSame(-15.0, $tiktok['descontos']);
        $this->assertSame(2, $tiktok['pedidos']);
        $this->assertSame(65.0, $tiktok['fornecedor']);
        // 4% da nota de R$ 90 + 4% do total de R$ 60 (sem nota).
        $this->assertSame(6.0, $tiktok['imposto']);
        $this->assertSame(1, $tiktok['sem_nota']);
        $this->assertSame(4.0, $tiktok['insumos']);
        $this->assertSame(25.0, $tiktok['sobra']); // 100 − 65 − 6 − 4
        $this->assertSame($a->id > 0, true);
    }

    public function test_linha_zerada_e_estorno_baixam_o_que_caiu_mas_nao_viram_pedido_pago(): void
    {
        $this->pedido('tiktok_shop', 'TT-PAGO', 100, 40, nota: 100);
        $this->pedido('tiktok_shop', 'TT-ZERO', 90, 40, nota: 90);
        $this->pedido('tiktok_shop', 'TT-ESTORNO', 50, 18, nota: 50);
        $this->extrato('tiktok_shop', 'Pagos', 'Pedido', 'TT-PAGO', '2026-11-10', 70);
        // O TikTok repete o pedido com R$ 0,00 (a linha que paga cai em outra semana).
        $this->extrato('tiktok_shop', 'Pagos', 'Pedido', 'TT-ZERO', '2026-11-10', 0);
        // Estorno: tira dinheiro, não é venda paga.
        $this->extrato('tiktok_shop', 'Pagos', 'Pedido', 'TT-ESTORNO', '2026-11-11', -33.39);

        $tiktok = $this->semana()['canais']['tiktok_shop'];

        $this->assertSame(36.61, $tiktok['depositado']);
        $this->assertSame(1, $tiktok['pedidos']);
        $this->assertSame(40.0, $tiktok['fornecedor']);
        $this->assertSame(4.0, $tiktok['imposto']);
        $this->assertSame(2.0, $tiktok['insumos']);
        $this->assertSame(-9.39, $tiktok['sobra']); // 36,61 − 40 − 4 − 2
    }

    public function test_amazon_so_conta_o_liberado_e_avisa_pedido_que_nao_esta_no_sistema(): void
    {
        $this->pedido('amazon', 'AMZ-1', 120, 50, nota: 120);
        $this->extrato('amazon', 'RELEASED', 'Order', 'AMZ-1', '2026-11-10', 95);
        $this->extrato('amazon', 'RELEASED', 'Order', 'AMZ-FORA', '2026-11-11', 30);
        $this->extrato('amazon', 'DEFERRED', 'Order', 'AMZ-1', '2026-11-12', 500);

        $resultado = $this->semana();
        $amazon = $resultado['canais']['amazon'];

        $this->assertSame(125.0, $amazon['depositado']);
        $this->assertSame(1, $amazon['pedidos']);
        $this->assertSame(30.0, $amazon['sem_pedido']);
        $this->assertSame(50.0, $amazon['fornecedor']);
        $this->assertSame(4.8, $amazon['imposto']);
        $this->assertSame(68.2, $amazon['sobra']); // 125 − 50 − 4,80 − 2
        $this->assertTrue(collect($resultado['avisos'])->contains(fn ($a) => str_contains($a, 'Amazon') && str_contains($a, '30,00')));
    }

    public function test_mercado_livre_usa_a_liberacao_do_mercado_pago(): void
    {
        $um = $this->pedido('mercado_livre', 'ML-1', 200, 80, nota: 200);
        $dois = $this->pedido('mercado_livre', 'ML-2', 100, 30, nota: 100);
        $this->mercadoPago($um, '2026-11-09 08:00:00', 170);
        $this->mercadoPago($dois, '2026-11-15 23:59:00', 85, reembolsado: 5);
        // Liberação na segunda de hoje, pagamento recusado e liberação antiga: fora.
        $this->mercadoPago($um, '2026-11-16 09:00:00', 999);
        $this->mercadoPago($um, '2026-11-10 09:00:00', 999, status: 'rejected');
        $this->mercadoPago($dois, '2026-11-08 23:00:00', 999);

        $ml = $this->semana()['canais']['mercado_livre'];

        $this->assertSame(250.0, $ml['depositado']); // 170 + (85 − 5)
        $this->assertSame(2, $ml['pedidos']);
        $this->assertSame(110.0, $ml['fornecedor']);
        $this->assertSame(12.0, $ml['imposto']);
        $this->assertSame(4.0, $ml['insumos']);
        $this->assertSame(124.0, $ml['sobra']);
    }

    public function test_shopee_le_a_carteira_sem_os_saques_e_desconta_a_recarga_de_ads(): void
    {
        $this->pedido('shopee', 'SH-1', 80, 30, nota: 75);
        $this->carteira = [
            ['transaction_id' => 1, 'status' => 'COMPLETED', 'transaction_type' => 'ESCROW_VERIFIED_ADD', 'amount' => 61.39, 'order_sn' => 'SH-1'],
            ['transaction_id' => 2, 'status' => 'COMPLETED', 'transaction_type' => 'SPM_DEDUCT', 'amount' => -20, 'order_sn' => ''],
            // Saque para o banco: não é depósito nem custo.
            ['transaction_id' => 3, 'status' => 'COMPLETED', 'transaction_type' => 'PIX_TRANSFER_SEND_CREATED', 'amount' => -500, 'order_sn' => ''],
            ['transaction_id' => 4, 'status' => 'COMPLETED', 'transaction_type' => 'WITHDRAWAL_CREATED', 'amount' => -100, 'order_sn' => ''],
        ];

        $shopee = $this->semana()['canais']['shopee'];

        $this->assertSame(41.39, $shopee['depositado']);
        $this->assertSame(-20.0, $shopee['descontos']);
        $this->assertSame(1, $shopee['pedidos']);
        $this->assertSame(30.0, $shopee['fornecedor']);
        $this->assertSame(3.0, $shopee['imposto']);
        $this->assertSame(6.39, $shopee['sobra']); // 41,39 − 30 − 3 − 2
    }

    public function test_carteira_da_shopee_fora_do_ar_vira_aviso_e_nao_zero_silencioso(): void
    {
        $shopee = Mockery::mock(ShopeeClient::class);
        $shopee->shouldReceive('get')->andThrow(new \RuntimeException('token expirado'));
        $this->app->instance(ShopeeClient::class, $shopee);

        $resultado = $this->semana();

        $this->assertSame(0.0, $resultado['canais']['shopee']['depositado']);
        $this->assertTrue(collect($resultado['avisos'])->contains(fn ($a) => str_contains($a, 'Shopee') && str_contains($a, 'NÃO está nesta auditoria')));
    }

    public function test_total_e_a_soma_dos_canais_e_o_comando_manda_o_email_uma_vez_por_semana(): void
    {
        Mail::fake();
        $this->pedido('tiktok_shop', 'TT-1', 100, 40, nota: 100);
        $this->extrato('tiktok_shop', 'Pagos', 'Pedido', 'TT-1', '2026-11-09', 70);
        $ml = $this->pedido('mercado_livre', 'ML-1', 200, 80, nota: 200);
        $this->mercadoPago($ml, '2026-11-10 08:00:00', 170);

        $total = $this->semana()['total'];
        $this->assertSame(240.0, $total['depositado']);
        $this->assertSame(120.0, $total['fornecedor']);
        $this->assertSame(12.0, $total['imposto']);
        $this->assertSame(4.0, $total['insumos']);
        $this->assertSame(104.0, $total['sobra']);
        $this->assertSame(2, $total['pedidos']);

        $this->artisan('financeiro:auditoria-semanal --seco')->assertSuccessful();
        Mail::assertNothingSent();

        $this->artisan('financeiro:auditoria-semanal')->assertSuccessful();
        Mail::assertSent(AuditoriaDaSemana::class, fn (AuditoriaDaSemana $m) => $m->hasTo('lira@example.com') && $m->auditoria['total']['sobra'] === 104.0);
        // Rodar de novo na mesma semana não repete o e-mail.
        $this->artisan('financeiro:auditoria-semanal')->assertSuccessful();
        Mail::assertSent(AuditoriaDaSemana::class, 1);
    }

    public function test_email_mostra_o_que_separar(): void
    {
        $this->pedido('tiktok_shop', 'TT-1', 100, 40, nota: 100);
        $this->extrato('tiktok_shop', 'Pagos', 'Pedido', 'TT-1', '2026-11-09', 70);

        $html = (new AuditoriaDaSemana($this->semana()))->render();

        $this->assertStringContainsString('Dinheiro que caiu de 09/11 a 15/11/2026', $html);
        $this->assertStringContainsString('Separar para imposto', $html);
        $this->assertStringContainsString('Separar para fornecedor', $html);
        $this->assertStringContainsString('Separar para insumos', $html);
        $this->assertStringContainsString('R$ 24,00', $html); // 70 − 40 − 4 − 2
    }
}
