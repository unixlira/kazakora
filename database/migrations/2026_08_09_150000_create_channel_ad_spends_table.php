<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito 2026-08-09: gasto real com anúncio (Shopee Ads +
 * Mercado Ads), pra alimentar o painel de lucro líquido — confirmado ao
 * vivo que as duas APIs devolvem dado real de custo diário. Uma linha por
 * dia por canal (não por campanha — a Shopee já devolve agregado por dia,
 * o Mercado Livre é somado entre campanhas na hora do sync pra ficar no
 * mesmo formato).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_ad_spends', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('channel'); // shopee | mercado_livre
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('attributed_orders')->default(0);
            $table->decimal('attributed_gmv', 10, 2)->default(0);
            $table->decimal('spend', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['date', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_ad_spends');
    }
};
