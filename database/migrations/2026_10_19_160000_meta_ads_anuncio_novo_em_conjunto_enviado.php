<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta ADS (pedido do Lira 2026-10-19): acrescentar anúncio novo dentro de
 * conjunto que já está na Meta. O anúncio sobe sozinho, pausado, no ad set
 * que já existe.
 *
 * - adicionado_depois: anúncio criado depois do envio da campanha.
 * - ativado_em: quando o anúncio novo foi ligado na Meta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ads_videos', function (Blueprint $table) {
            $table->boolean('adicionado_depois')->default(false)->after('meta_ad_id');
            $table->timestamp('ativado_em')->nullable()->after('adicionado_depois');
        });
    }

    public function down(): void
    {
        Schema::table('meta_ads_videos', function (Blueprint $table) {
            $table->dropColumn(['adicionado_depois', 'ativado_em']);
        });
    }
};
