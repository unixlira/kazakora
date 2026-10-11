<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valor real da postagem (cotado via API de Preço no momento da criação —
 * a API de pré-postagem não devolve preço, "modalidadePagamento":2 =
 * pago/pesado na agência, ver CorreiosFreightQuoteService::priceFor()).
 * Pedido explícito 2026-08-19: "preciso saber o valor de todos tipos de
 * postagens inclusive dos qrcode criado, incluir uma coluna valor da
 * postagem no listagem".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correios_pre_postagens', function (Blueprint $table) {
            $table->decimal('postage_price', 8, 2)->nullable()->after('service_label');
        });
    }

    public function down(): void
    {
        Schema::table('correios_pre_postagens', function (Blueprint $table) {
            $table->dropColumn('postage_price');
        });
    }
};
