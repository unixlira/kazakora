<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // API de Orders do Mercado Pago opera por order id (formato
            // "ORD...") — é o identificador usado pra capturar/cancelar/
            // estornar/consultar. mercadopago_payment_id continua existindo,
            // guardando o id do pagamento interno (transactions.payments[0].id)
            // só como referência/exibição.
            $table->string('mercadopago_order_id')->nullable()->unique()->after('mercadopago_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('mercadopago_order_id');
        });
    }
};
