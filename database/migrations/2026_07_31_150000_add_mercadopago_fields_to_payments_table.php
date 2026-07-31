<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider')->default('stripe')->after('order_id');
            $table->string('mercadopago_payment_id')->nullable()->unique()->after('stripe_payment_intent_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            // Pix via Mercado Pago não tem PaymentIntent do Stripe — a coluna
            // deixa de ser obrigatória (continua única, só passa a aceitar null).
            $table->string('stripe_payment_intent_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['provider', 'mercadopago_payment_id']);
            $table->string('stripe_payment_intent_id')->nullable(false)->change();
        });
    }
};
