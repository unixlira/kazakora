<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido de avaliação depois da entrega (pedido do Lira 2026-11-23: juntar
 * avaliações reais de quem comprou). loja:pedir-avaliacao manda o link 5 dias
 * depois da entrega:
 *
 * - orders.entregue_em: quando o pedido virou "completed" (Order::booted
 *   preenche daqui pra frente). Pedidos já entregues ganham a data do aviso de
 *   entrega do WhatsApp ou, sem ele, a última alteração do pedido.
 * - orders.pedido_avaliacao_enviado_em: quando o pedido de avaliação saiu
 *   (trava para não mandar duas vezes).
 * - reviews.order_id: avaliação feita pelo link do pedido (compra verificada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'entregue_em')) {
                $table->timestamp('entregue_em')->nullable()->index();
            }
            if (! Schema::hasColumn('orders', 'pedido_avaliacao_enviado_em')) {
                $table->timestamp('pedido_avaliacao_enviado_em')->nullable();
            }
        });

        Schema::table('reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('reviews', 'order_id')) {
                $table->unsignedBigInteger('order_id')->nullable()->index();
            }
        });

        DB::table('orders')
            ->where('status', 'completed')
            ->whereNull('entregue_em')
            ->update(['entregue_em' => DB::raw('COALESCE(whatsapp_entregue_em, updated_at)')]);
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
            $table->dropColumn('order_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['entregue_em']);
            $table->dropColumn(['entregue_em', 'pedido_avaliacao_enviado_em']);
        });
    }
};
