<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Motivo do erro no cartão (pedido do Lira 2026-11-24): o lead 21 clicou
     * Finalizar com cartão, viu só "Confira os dados do cartão." e a medição
     * gravou "erro no cartão" sem dizer por quê. Aqui fica o código da lista
     * fechada CheckoutEvento::MOTIVOS_CARTAO (numero, validade, cvv...),
     * nunca o que foi digitado.
     */
    public function up(): void
    {
        Schema::table('checkout_eventos', function (Blueprint $table) {
            $table->string('motivo', 20)->nullable()->after('valor');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_eventos', function (Blueprint $table) {
            $table->dropColumn('motivo');
        });
    }
};
