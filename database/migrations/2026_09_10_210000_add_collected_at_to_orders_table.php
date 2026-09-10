<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Entregue ao entregador" — o carimbo do KoraFlex quando a caixa sai da
 * mão de quem embala pra mão de quem transporta.
 *
 * Pedido do usuário em 2026-09-10, testando o app: até aqui "coletada"
 * vinha só do canal (o Mercado Livre marcando a venda como enviada), e
 * isso acontece HORAS depois do motorista já ter ido embora — às vezes só
 * quando ele chega no centro de distribuição. Para a briga com a
 * transportadora, o instante que importa é o da entrega em mãos, e quem
 * sabe esse instante é quem entregou.
 *
 * Fica separado de ready_for_pickup_at de propósito: um diz "estava
 * pronta às 11:32", o outro "saiu daqui às 15:12". A distância entre os
 * dois é exatamente o que se discute quando um pacote some.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('collected_at')->nullable()->after('ready_for_pickup_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('collected_at');
        });
    }
};
