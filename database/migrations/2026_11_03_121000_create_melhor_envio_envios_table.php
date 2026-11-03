<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etiqueta pelo Melhor Envio dos pedidos do site da KazaKora (pedido do Lira
 * em 2026-11-03, Melhor Envio conectado em Logística vale para "tudo"). Uma
 * linha por pedido: carrinho → compra (saldo da carteira) → etiqueta gerada.
 * Guarda cada passo para nunca comprar duas vezes o mesmo frete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('melhor_envio_envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->string('status', 20)->default('erro'); // carrinho, paga, gerada, erro
            $table->uuid('me_order_id')->nullable()->index(); // id da etiqueta no Melhor Envio
            $table->string('protocolo', 40)->nullable();
            $table->unsignedInteger('servico_id')->nullable();
            $table->string('servico', 80)->nullable(); // transportadora + serviço
            $table->decimal('preco', 8, 2)->nullable();
            $table->string('rastreio', 60)->nullable();
            $table->unsignedInteger('peso_g')->nullable();
            $table->json('volume')->nullable();
            $table->string('erro', 500)->nullable();
            $table->json('resposta')->nullable();
            $table->timestamp('paga_em')->nullable();
            $table->timestamp('gerada_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('melhor_envio_envios');
    }
};
