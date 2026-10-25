<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contador de cliques do painel (pedido do Lira 2026-10-25): saber quais
     * menus, abas e botões do admin a equipe usa, para enxugar o que ninguém
     * usa. Uma linha por dia (America/Sao_Paulo) + elemento + página +
     * usuário, com a contagem somada: não guarda clique a clique, então a
     * tabela cresce pouco. Só o NOME do elemento (data-clique ou texto do
     * item do menu); nada do que foi digitado.
     */
    public function up(): void
    {
        Schema::create('admin_cliques', function (Blueprint $table) {
            $table->id();
            $table->date('dia');
            $table->string('elemento', 120);
            // menu, topo, aba ou botao.
            $table->string('tipo', 12);
            // Caminho onde o clique aconteceu, com número trocado por {id}.
            $table->string('pagina', 150);
            // Para onde o link leva (só links), também com {id}.
            $table->string('destino', 150)->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('contagem')->default(0);
            $table->timestamps();

            $table->unique(['dia', 'elemento', 'pagina', 'user_id'], 'admin_cliques_dia_unico');
            $table->index(['dia', 'pagina']);
            $table->index(['user_id', 'dia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_cliques');
    }
};
