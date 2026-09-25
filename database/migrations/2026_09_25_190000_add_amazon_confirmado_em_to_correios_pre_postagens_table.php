<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quando o envio desta pré-postagem foi confirmado direto na Amazon pela
 * SP-API (shipmentConfirmation) — ver ConfirmAmazonShipment. Null = ainda
 * não confirmado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correios_pre_postagens', function (Blueprint $table) {
            $table->timestamp('amazon_confirmado_em')->nullable()->after('bling_informado_em');
        });
    }

    public function down(): void
    {
        Schema::table('correios_pre_postagens', function (Blueprint $table) {
            $table->dropColumn('amazon_confirmado_em');
        });
    }
};
