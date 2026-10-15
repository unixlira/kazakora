<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Boleto pelo Mercado Pago (pedido 2026-10-14): o pedido fica dias
     * aguardando, então a linha digitável, o link do PDF e o vencimento
     * ficam guardados para mostrar de novo em Minhas Compras e para a
     * varredura que confere o pagamento (mercadopago:conferir-boletos).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('boleto_linha_digitavel', 80)->nullable()->after('mercadopago_order_id');
            $table->text('boleto_url')->nullable()->after('boleto_linha_digitavel');
            $table->timestamp('expira_em')->nullable()->after('boleto_url');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['boleto_linha_digitavel', 'boleto_url', 'expira_em']);
        });
    }
};
