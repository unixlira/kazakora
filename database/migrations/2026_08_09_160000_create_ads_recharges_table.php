<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito 2026-08-09: histórico de recarga de saldo de anúncio
 * (Shopee Ads / Mercado Ads). Nenhuma das duas APIs expõe um extrato de
 * recarga consultável (confirmado ao vivo: só dá pra ler o saldo ATUAL da
 * Shopee via get_total_balance; Mercado Livre nem tem conceito de saldo
 * separado, debita direto da conta) — então isso é lançado à mão, mesmo
 * padrão já usado no Fluxo de Caixa (CashFlowEntry).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ads_recharges', function (Blueprint $table) {
            $table->id();
            $table->string('channel'); // shopee | mercado_livre
            $table->decimal('amount', 10, 2);
            $table->date('recharge_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ads_recharges');
    }
};
