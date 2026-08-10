<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo disponível pra saque por canal — pedido explícito 2026-08-09/10.
 * Confirmado ao vivo que a Shopee tem isso via chamada direta (ver
 * ShopeeWalletService), mas o Mercado Pago só expõe via relatório
 * assíncrono (POST /v1/account/release_report → espera ~15-20min → GET
 * .../release_report/{file_name} devolve um CSV com BALANCE_AMOUNT por
 * movimento, o último = saldo atual). Guarda o valor já calculado aqui pra
 * não segurar a página esperando um processo de minutos — ver
 * MercadoPagoWalletService + comando ads:sync-wallet-balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_wallet_balances', function (Blueprint $table) {
            $table->id();
            $table->string('channel')->unique(); // shopee | mercado_livre
            $table->decimal('balance', 10, 2);
            $table->timestamp('balance_as_of')->nullable(); // data do último movimento no relatório, não "agora"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_wallet_balances');
    }
};
