<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tela Compras (pedido do Lira em 12/11/2026): o custo do produto sai do que
 * a loja pagou de verdade. Reaproveita purchase_orders/purchase_order_items
 * (pedidos de compra, 2026-07-23, vazias até hoje) com o que a compra
 * precisa: data, frete e outros custos rateados nos itens, forma de
 * pagamento, comprovante, chave da NF-e quando houver e o histórico de quem
 * aplicou o custo no produto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // 'pedido' = tela antiga de pedidos de compra; 'compra' = tela Compras.
            $table->string('origem', 20)->default('pedido')->after('status');
            $table->date('purchase_date')->nullable()->after('origem')->index();
            $table->decimal('freight', 10, 2)->default(0)->after('purchase_date');
            $table->decimal('other_costs', 10, 2)->default(0)->after('freight');
            $table->string('payment_method', 30)->nullable()->after('other_costs');
            $table->string('attachment_path')->nullable()->after('payment_method');
            $table->string('invoice_key', 44)->nullable()->after('attachment_path');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->string('description')->nullable()->after('product_id');
            $table->decimal('effective_unit_cost', 12, 4)->nullable()->after('unit_cost');
            $table->decimal('previous_cost', 10, 2)->nullable()->after('effective_unit_cost');
            $table->timestamp('cost_applied_at')->nullable()->after('previous_cost');
            $table->foreignId('cost_applied_by')->nullable()->after('cost_applied_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_applied_by');
            $table->dropColumn(['description', 'effective_unit_cost', 'previous_cost', 'cost_applied_at']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['purchase_date']);
            $table->dropColumn(['origem', 'purchase_date', 'freight', 'other_costs', 'payment_method', 'attachment_path', 'invoice_key']);
        });
    }
};
