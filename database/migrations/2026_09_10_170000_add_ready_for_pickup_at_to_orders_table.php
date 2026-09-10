<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Pronto pra coleta" — o estado que o KoraFlex grava quando alguém bipa o
 * QR da etiqueta do Flex com a caixa fechada na mão.
 *
 * É estado NOVO, e não um apelido pro packed_at: separar ("tirei da
 * prateleira") e estar pronto pra transportadora levar ("está etiquetado,
 * fechado e na área de coleta") são momentos diferentes, e a briga com a
 * transportadora é sempre sobre o segundo. Guardar os dois separados é o
 * que permite responder "estava pronto às 11:32" com hora, e não de
 * memória.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('ready_for_pickup_at')->nullable()->after('packed_at');

            // A tela do dia filtra por "Flex, pago, ainda não coletado" e
            // ordena pelo que falta — sempre com este campo no meio.
            $table->index(['status', 'ready_for_pickup_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'ready_for_pickup_at']);
            $table->dropColumn('ready_for_pickup_at');
        });
    }
};
