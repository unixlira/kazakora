<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conjuntos e anúncios de cada campanha excluída, com os números de cada um
 * (pedido do Lira em 2026-10-27: botão Visualizar no histórico). Vêm da mesma
 * foto da Meta de 27/10 (database/data/meta-ads-historico-2026-10-27.json),
 * copiados como estão; só a miniatura ficou de fora porque o link expira.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ads_campanhas_historico', function (Blueprint $table) {
            $table->json('estrutura')->nullable()->after('anuncios');
        });

        $foto = json_decode(file_get_contents(database_path('data/meta-ads-historico-2026-10-27.json')), true);

        foreach ($foto as $metaId => $conjuntos) {
            DB::table('meta_ads_campanhas_historico')
                ->where('meta_id', (string) $metaId)
                ->update(['estrutura' => json_encode($conjuntos, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        Schema::table('meta_ads_campanhas_historico', function (Blueprint $table) {
            $table->dropColumn('estrutura');
        });
    }
};
