<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extrato financeiro real do marketplace, linha a linha (hoje o do TikTok
 * Shop, importado do relatório de receita do canal).
 *
 * Esta migration é uma RECONSTRUÇÃO: a tabela existe em produção desde
 * 2026-08-27 e está registrada na tabela `migrations` de lá, mas o arquivo
 * nunca esteve no git — é a "reconciliação à parte" prevista no comentário
 * de 2026_09_02_090000_restore_missing_order_and_invoice_columns. Sem ela,
 * ambiente novo (e a suíte de testes) nasce sem a tabela e o Dashboard
 * Financeiro, que lê extrato por canal, quebra.
 *
 * Colunas e índices copiados do SHOW CREATE TABLE de produção, pra ambiente
 * novo nascer idêntico ao que já roda. Guardada por hasTable(): em produção
 * o nome já está registrado, então nem chega a rodar.
 */
return new class extends Migration
{
    /** Toda coluna de dinheiro do extrato, decimal(12,2) começando em zero. */
    private const MONEY_COLUMNS = [
        'payout_amount',
        'product_net_sales',
        'item_subtotal_before_discounts',
        'seller_discounts',
        'product_refunds',
        'net_shipping_cost',
        'shipping_cost',
        'customer_shipping_fee',
        'channel_shipping_coverage',
        'return_shipping_cost',
        'platform_fees_taxes',
        'platform_commission_fee',
        'service_fees',
        'sfp_service_fee',
        'taxes',
        'icms_difal',
        'icms_fine',
        'affiliate_commissions',
        'estimated_affiliate_commissions',
        'agency_partner_commission',
        'shop_ads_creator_commission',
        'shop_ads_agency_commission',
        'gmv_max_ad_fee',
        'adjustment_amount',
        'customer_payment',
    ];

    public function up(): void
    {
        if (Schema::hasTable('marketplace_settlement_details')) {
            return;
        }

        Schema::create('marketplace_settlement_details', function (Blueprint $table) {
            $table->id();
            $table->string('channel');
            $table->date('settlement_date')->nullable();
            $table->string('statement_id')->nullable();
            $table->string('payment_id')->nullable();
            $table->string('status')->nullable();
            $table->string('currency', 8)->default('BRL');
            $table->string('transaction_type')->nullable();
            $table->string('external_order_id')->nullable();
            $table->string('external_sku_id')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->text('product_name')->nullable();
            $table->string('sku_name')->nullable();
            $table->date('order_created_at')->nullable();
            $table->date('order_delivered_at')->nullable();

            foreach (self::MONEY_COLUMNS as $column) {
                $table->decimal($column, 12, 2)->default(0);
            }

            $table->string('adjustment_reason')->nullable();
            $table->string('related_order_id')->nullable();
            $table->string('source_file')->nullable();
            $table->timestamps();

            // A mesma linha do extrato reimportada não pode duplicar: o
            // relatório do canal é baixado de novo a cada fechamento e
            // sempre repete os períodos anteriores.
            $table->unique(
                ['channel', 'statement_id', 'external_order_id', 'external_sku_id', 'transaction_type'],
                'marketplace_settlement_unique_line'
            );

            $table->index(['channel', 'external_order_id']);
            $table->index(['channel', 'settlement_date']);
            $table->index(['channel', 'order_created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_settlement_details');
    }
};
