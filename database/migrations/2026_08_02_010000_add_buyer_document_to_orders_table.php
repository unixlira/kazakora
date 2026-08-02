<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // CPF/CNPJ do comprador pra pedidos de canal externo, onde não
            // existe um Order::user local pra buscar via $customer->cpf
            // (user_id fica null em pedido importado de marketplace).
            // NFeXmlBuilderService usa este campo com fallback pro CPF do
            // usuário local, cobrindo os dois casos com uma coisa só.
            $table->string('buyer_document')->nullable()->after('external_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('buyer_document');
        });
    }
};
