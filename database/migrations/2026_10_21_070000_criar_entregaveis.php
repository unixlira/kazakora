<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entregáveis (pedido 2026-10-20): livros digitais (PDF) e sorteios que o
 * Lira liga e desliga em cada produto pelo admin, no lugar dos banners fixos
 * da página do produto (PR #765).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('livros_digitais', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');
            $table->string('arquivo');
            $table->string('nome_arquivo');
            $table->unsignedBigInteger('tamanho')->default(0);
            $table->string('capa')->nullable();
            $table->boolean('ativo')->default(true);
            $table->boolean('todos_produtos')->default(true);
            $table->timestamps();
        });

        Schema::create('livro_digital_produto', function (Blueprint $table) {
            $table->foreignId('livro_digital_id')->constrained('livros_digitais')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['livro_digital_id', 'product_id']);
        });

        Schema::create('sorteios', function (Blueprint $table) {
            $table->id();
            // null = aparece em todos os produtos.
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('titulo');
            $table->text('paragrafo_1');
            $table->text('paragrafo_2')->nullable();
            $table->string('imagem');
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        // O sorteio da AirFryer que estava fixo na página (PR #765) vira o
        // primeiro cadastro, igual ao que está no ar: todos os produtos.
        DB::table('sorteios')->insert([
            'product_id' => null,
            'titulo' => 'Compre e Concorra a uma AirFryer de Última Geração!',
            'paragrafo_1' => 'Pedidos realizados e pagos até 20/10/2026 participam automaticamente.',
            'paragrafo_2' => null,
            'imagem' => 'images/promocoes/airfryer.jpg',
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sorteios');
        Schema::dropIfExists('livro_digital_produto');
        Schema::dropIfExists('livros_digitais');
    }
};
