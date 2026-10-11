<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suporte ao arquivamento local (KoraSync, pasta de Vendas no Windows) —
 * precisa do código de rastreio real (nome do arquivo) e do arquivo bruto
 * da etiqueta exatamente como o canal devolveu (zip da Shopee, pdf do
 * Mercado Livre), antes de qualquer conversão feita por LabelFetchService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_shipments', function (Blueprint $table) {
            $table->string('tracking_code')->nullable()->after('external_shipment_id');
            $table->string('raw_label_path')->nullable()->after('label_path');
        });
    }

    public function down(): void
    {
        Schema::table('channel_shipments', function (Blueprint $table) {
            $table->dropColumn(['tracking_code', 'raw_label_path']);
        });
    }
};
