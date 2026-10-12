<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu Pesquisas (pedido do Lira 2026-10-12): pesquisas de mercado sobre
 * produtos campeões, lançadas à mão por plataforma (Mercado Livre, Shopee
 * e outras). Só tabelas novas; nenhuma tabela existente muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pesquisas_mercado', function (Blueprint $table) {
            $table->id();
            // mercado_livre, shopee, tiktok_shop, amazon, loja, outra
            // (mesmo vocabulário de orders.origin).
            $table->string('plataforma', 30)->index();
            // Nome livre quando plataforma = outra (ex.: Shein, Magalu).
            $table->string('plataforma_nome', 80)->nullable();
            $table->string('titulo');
            $table->date('data_pesquisa');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('rascunho');
            $table->longText('resumo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pesquisa_mercado_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pesquisa_mercado_id')->constrained('pesquisas_mercado')->cascadeOnDelete();
            $table->unsignedSmallInteger('posicao')->default(0);
            $table->string('produto');
            $table->string('link', 2048)->nullable();
            $table->decimal('preco', 12, 2)->nullable();
            // Texto livre de propósito: "+10 mil vendidos", "4,8 (2.300)".
            $table->string('vendas', 120)->nullable();
            $table->string('avaliacoes', 120)->nullable();
            $table->string('vendedor')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->text('observacoes')->nullable();
            $table->decimal('nota', 4, 1)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesquisa_mercado_itens');
        Schema::dropIfExists('pesquisas_mercado');
    }
};
