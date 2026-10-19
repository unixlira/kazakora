<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta ADS (pedido do Lira 2026-10-19): conjunto novo dentro de campanha
 * que já está na Meta. O conjunto é montado no admin e enviado sozinho,
 * pausado, sem mexer no status da campanha (que pode estar gastando).
 *
 * - adicionado_depois: conjunto criado depois do envio da campanha.
 * - enviando: o envio só deste conjunto está na fila.
 * - ativado_em: quando o conjunto foi ligado na Meta (o botão de ativar só
 *   aparece para conjunto novo ainda pausado em campanha ativa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ads_conjuntos', function (Blueprint $table) {
            $table->boolean('adicionado_depois')->default(false)->after('meta_adset_id');
            $table->boolean('enviando')->default(false)->after('adicionado_depois');
            $table->timestamp('ativado_em')->nullable()->after('enviando');
        });
    }

    public function down(): void
    {
        Schema::table('meta_ads_conjuntos', function (Blueprint $table) {
            $table->dropColumn(['adicionado_depois', 'enviando', 'ativado_em']);
        });
    }
};
