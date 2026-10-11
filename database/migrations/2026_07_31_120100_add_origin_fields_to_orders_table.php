<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Toda venda hoje vem do checkout da própria loja (não existe
            // ingestão de pedidos de marketplace ainda) — 'loja' é o único
            // valor real até essa integração ser construída. Os campos já
            // existem agora pra não precisar de outra migration quando isso
            // acontecer, e pra permitir buscar a nota por pedido de
            // marketplace no futuro.
            $table->string('origin')->default('loja')->after('user_id');
            $table->string('external_order_id')->nullable()->after('origin');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['origin', 'external_order_id']);
        });
    }
};
