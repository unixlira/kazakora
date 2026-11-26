<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "Começar do zero" por página na tela Comportamento (pedido do Lira em
 * 2026-11-26: a página do livro mudou e o mapa mostrava pontos da tela
 * antiga). Nada é apagado: cada linha é um corte (produto + pagina, desde,
 * motivo, quem); a tela usa só os eventos depois do corte mais recente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('comportamento_cortes')) {
            return;
        }
        Schema::create('comportamento_cortes', function (Blueprint $table) {
            $table->id();
            $table->string('produto', 130);
            $table->string('pagina', 10);
            $table->timestamp('desde');
            $table->string('motivo', 200)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['produto', 'pagina', 'desde']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comportamento_cortes');
    }
};
