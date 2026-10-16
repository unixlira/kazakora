<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido da Amazon com pagamento pendente (boleto aguardando): o pedido
 * completo da SP-API e a conferência de 24 em 24 horas
 * (ver AmazonPagamentoPendenteService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amazon_pagamentos_pendentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->string('amazon_order_id');
            $table->string('status_amazon')->nullable();
            $table->json('pedido_amazon')->nullable();
            $table->timestamp('pendente_desde')->nullable();
            $table->timestamp('conferido_em')->nullable();
            $table->unsignedInteger('conferencias')->default(0);
            $table->timestamp('resolvido_em')->nullable();
            $table->string('resultado')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_pagamentos_pendentes');
    }
};
