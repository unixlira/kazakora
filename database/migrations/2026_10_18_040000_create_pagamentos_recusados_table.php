<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motivo da recusa do Mercado Pago na tela do pedido (pedido do Lira
 * 2026-10-18), sem precisar abrir o painel do Mercado Pago.
 *
 * payments.motivo_recusa: status_detail cru do Mercado Pago quando um
 * pagamento que já existia é recusado depois (webhook, polling, varredura).
 *
 * pagamentos_recusados: cartão recusado na hora de pagar. Nesse caso o
 * pedido é desfeito (rollback, sem baixa de estoque), então a tentativa
 * fica aqui e aparece no pedido que o mesmo cliente fizer depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('motivo_recusa', 80)->nullable();
        });

        Schema::create('pagamentos_recusados', function (Blueprint $table) {
            $table->id();
            $table->string('site_origem', 30)->nullable();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('email', 190)->nullable()->index();
            $table->unsignedBigInteger('pedido_desfeito_id')->nullable();
            $table->string('metodo', 20);
            $table->decimal('valor', 12, 2)->default(0);
            $table->string('mercadopago_order_id', 60)->nullable();
            $table->string('mercadopago_payment_id', 60)->nullable();
            $table->string('status', 30)->nullable();
            $table->string('motivo_recusa', 80)->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamentos_recusados');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('motivo_recusa');
        });
    }
};
