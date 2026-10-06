<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quebra da taxa do canal por pedido (pedido do usuário 2026-10-06: margem
 * "oficial e fidedigna"). fee_amount continua sendo o total descontado da
 * venda — agora = venda − repasse real —, e estas colunas dizem de onde
 * ele vem. Nulo = o canal não informou aquele componente (não é zero).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_channel_fees', function (Blueprint $table) {
            $table->decimal('commission_fee', 10, 2)->nullable()->after('fee_amount');
            $table->decimal('service_fee', 10, 2)->nullable()->after('commission_fee');
            $table->decimal('shipping_fee', 10, 2)->nullable()->after('service_fee');
            $table->decimal('seller_discount', 10, 2)->nullable()->after('shipping_fee');
            $table->decimal('platform_discount', 10, 2)->nullable()->after('seller_discount');
            $table->decimal('payout_amount', 10, 2)->nullable()->after('platform_discount');
            $table->json('breakdown')->nullable()->after('payout_amount');
        });
    }

    public function down(): void
    {
        Schema::table('order_channel_fees', function (Blueprint $table) {
            $table->dropColumn(['commission_fee', 'service_fee', 'shipping_fee', 'seller_discount', 'platform_discount', 'payout_amount', 'breakdown']);
        });
    }
};
