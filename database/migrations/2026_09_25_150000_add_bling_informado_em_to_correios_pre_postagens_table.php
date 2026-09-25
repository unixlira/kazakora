<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quando o rastreio desta pré-postagem foi mandado pro Bling (e dele pra
 * Amazon) — ver InformAmazonShipmentToBling. Null = ainda não informado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correios_pre_postagens', function (Blueprint $table) {
            $table->timestamp('bling_informado_em')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('correios_pre_postagens', function (Blueprint $table) {
            $table->dropColumn('bling_informado_em');
        });
    }
};
