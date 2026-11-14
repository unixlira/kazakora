<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Medição do checkout da FerraKora (pedido do Lira 2026-11-14): de 11 a
     * 14/11, 58 pessoas abriram o /seguro-pagamento da FerraKora vindas do
     * anúncio e quase nenhuma digitou um campo; só se sabia que a página foi
     * aberta. A FerraKora passa a mandar os mesmos eventos da /finalizacao
     * (POST /api/ferrakora/checkout/eventos). `site` nulo = loja KazaKora
     * (linhas antigas ficam como estão); outro site grava o nome dele.
     */
    public function up(): void
    {
        Schema::table('checkout_eventos', function (Blueprint $table) {
            $table->string('site', 20)->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('checkout_eventos', function (Blueprint $table) {
            $table->dropIndex(['site']);
            $table->dropColumn('site');
        });
    }
};
