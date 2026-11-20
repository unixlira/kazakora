<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fila de criação dos PDFs de brinde (pedido do Lira 20/11/2026): cada linha é
 * um PDF a fazer para um produto, com o tema certo (brinquedo infantil = 2
 * histórias + 1 livro de colorir; travesseiro = dor nas costas; ferramenta =
 * guias da FerraKora). O Homer pega a próxima pela API, entrega o PDF e a capa,
 * e o livro entra desativado até o Lira aprovar no admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_brinde_fila', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // historia | colorir | guia | receitas
            $table->string('tipo', 20);
            $table->string('titulo');
            // Tema, público, ângulo da copy e regras do conteúdo (o que o Homer segue).
            $table->text('briefing')->nullable();
            $table->unsignedSmallInteger('paginas')->default(20);
            // fila | produzindo | revisao | aprovado | erro
            $table->string('status', 20)->default('fila');
            $table->unsignedSmallInteger('prioridade')->default(100);
            $table->foreignId('livro_digital_id')->nullable()->constrained('livros_digitais')->nullOnDelete();
            $table->text('observacao')->nullable();
            $table->timestamp('pego_em')->nullable();
            $table->timestamps();

            $table->index(['status', 'prioridade', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_brinde_fila');
    }
};
