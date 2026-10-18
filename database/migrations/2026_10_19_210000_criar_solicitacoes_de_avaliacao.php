<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido de avaliação depois da entrega (pedido do Lira 2026-10-18): um
 * e-mail por pedido do site entregue, com cupom para a próxima compra.
 * orders.entregue_em guarda quando o pacote chegou (rastreio dos Correios ou
 * troca manual para "concluído"), que é a base da espera em dias.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'entregue_em')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('entregue_em')->nullable()->after('whatsapp_entregue_em');
            });
        }

        Schema::create('avaliacao_solicitacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->unsignedTinyInteger('tentativas')->default(0);
            $table->text('erro')->nullable();
            $table->timestamp('enviado_em')->nullable();
            $table->timestamp('avaliado_em')->nullable();
            $table->timestamps();

            $table->index(['status', 'enviado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_solicitacoes');

        if (Schema::hasColumn('orders', 'entregue_em')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('entregue_em');
            });
        }
    }
};
