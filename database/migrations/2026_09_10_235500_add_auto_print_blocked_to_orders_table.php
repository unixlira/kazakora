<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Venda trazida por VARREDURA não manda papel pra impressora sozinha.
 *
 * ERRO MEU, 2026-09-10: o usuário achou no painel da Shopee uma venda que
 * nunca tinha entrado no sistema. Recuperei ela pelo `orders:sync-shopee`
 * — e o fluxo normal seguiu em frente sozinho: confirmou o envio com o
 * canal e IMPRIMIU a etiqueta, de um pedido que já estava a caminho,
 * despachado por fora dias antes. Papel perdido e susto na bancada, do
 * jeito que o usuário disse: "já tinha pedido a caminho e vc imprimiu de
 * novo".
 *
 * A varredura existe pra ACHAR o que se perdeu, não pra decidir imprimir.
 * O que ela traz fica marcado aqui e o caminho automático recusa; a
 * impressão continua a um clique — botão "Gerar etiquetas em lote" ou o
 * card —, com alguém olhando pra bancada antes de gastar papel.
 *
 * O webhook (fluxo vivo, venda entrando na hora) não marca nada e segue
 * imprimindo automático como sempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('auto_print_blocked')->default(false)->after('packed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('auto_print_blocked');
        });
    }
};
