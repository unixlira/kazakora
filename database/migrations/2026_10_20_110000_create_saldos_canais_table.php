<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto de hora em hora do dinheiro parado nos marketplaces (pedido do Lira
 * em 2026-10-19, aba "Saldos nos canais" do Fluxo de Caixa): saldo
 * disponível e o que ainda vai entrar, por empresa e canal. Uma linha por
 * coleta (financeiro:coletar-saldos), para o gráfico de evolução; coleta
 * com erro também vira linha (status erro), para a tela marcar a fonte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saldos_canais', function (Blueprint $table) {
            $table->id();
            $table->string('empresa', 20)->default('kazakora');
            $table->string('canal', 30);
            $table->dateTime('coletado_em');
            $table->string('status', 10); // ok | parcial | erro
            $table->decimal('disponivel', 12, 2)->nullable();
            $table->decimal('a_receber', 12, 2)->nullable();
            $table->json('repasses')->nullable(); // [{data: Y-m-d|null, valor, rotulo}]
            $table->json('detalhe')->nullable();
            $table->string('erro', 500)->nullable();
            $table->timestamps();

            $table->index(['empresa', 'canal', 'coletado_em']);
            $table->index('coletado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saldos_canais');
    }
};
