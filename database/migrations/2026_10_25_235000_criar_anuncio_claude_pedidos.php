<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fila do Claude para o conteúdo do anúncio (pedido do Lira 2026-10-25: "se
 * o Gemini não responder, o Claude assume a geração do texto"). Quando o
 * Gemini falha, o pedido fica aqui com o mesmo prompt e as mesmas fotos; o
 * worker do Homer no PC do Lira (Claude Code, sem custo de API) pega pelo
 * artisan anuncio-claude:proxima e devolve em anuncio-claude:responder.
 * tipo: conteudo (página do produto), cta (só a chamada final) ou descricao
 * (botão "Gerar descrição com IA pelas fotos").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anuncio_claude_pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('tipo', 20);
            $table->string('status', 20)->default('pendente');
            $table->string('origem_hash', 64)->nullable();
            $table->text('sistema');
            $table->longText('prompt');
            $table->json('imagens')->nullable();
            $table->unsignedSmallInteger('total_imagens')->default(0);
            $table->json('resposta')->nullable();
            $table->text('erro')->nullable();
            $table->timestamp('pego_em')->nullable();
            $table->timestamp('concluido_em')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['product_id', 'tipo', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anuncio_claude_pedidos');
    }
};
