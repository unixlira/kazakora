<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conciliação do Mercado Pago (2026-10-11). A mesma conta recebe as vendas
 * do Mercado Livre e as do site; cada pagamento diz quanto entrou de
 * verdade (net_received_amount). O site é reconhecido pelo número da
 * transação que a loja guardou (payments.mercadopago_payment_id).
 *
 * orders.estornado_em: venda devolvida/estornada por inteiro na
 * plataforma — continua na lista de pedidos, mas sai da receita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mercado_pago_pagamentos', function (Blueprint $table) {
            $table->id();
            $table->string('mp_payment_id', 40)->unique();
            $table->timestamp('data_criacao')->nullable()->index();
            $table->timestamp('data_aprovacao')->nullable();
            $table->timestamp('data_liberacao')->nullable();
            $table->string('status', 30)->index();
            $table->string('status_detalhe', 60)->nullable();
            $table->string('tipo_operacao', 40)->nullable();
            $table->string('canal', 20)->index(); // loja | mercado_livre | bonificacao | outro
            $table->string('ml_order_id', 40)->nullable()->index();
            $table->string('referencia_externa', 120)->nullable();
            $table->string('descricao', 190)->nullable();
            $table->string('meio_pagamento', 40)->nullable();
            $table->decimal('valor', 12, 2)->default(0);
            $table->decimal('liquido', 12, 2)->default(0);
            $table->decimal('reembolsado', 12, 2)->default(0);
            $table->foreignId('order_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('estornado_em')->nullable()->index();
            $table->string('estorno_origem', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['estornado_em']);
            $table->dropColumn(['estornado_em', 'estorno_origem']);
        });
        Schema::dropIfExists('mercado_pago_pagamentos');
    }
};
