<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta ADS (2026-10-18): vários anúncios na mesma campanha, como no
 * Gerenciador da Meta (Campanha > Conjunto > Anúncios). Pedido do Lira:
 * 3 árvores de Natal diferentes no mesmo fluxo, cada uma com o seu
 * produto, texto e mídia.
 *
 * - campanha.estrutura: 'conjunto_unico' (um conjunto com todos os
 *   anúncios, a Meta distribui a verba) ou 'por_anuncio' (um conjunto por
 *   anúncio com o orçamento dividido igual, o jeito que já existia).
 * - Cada anúncio (tabela meta_ads_videos) pode ter produto, link, texto,
 *   título, descrição e botão próprios; vazio = usa o padrão da campanha.
 * - Anúncio pode ser vídeo ou imagem (imagem vai para adimages, sem espera
 *   de processamento).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ads_campanhas', function (Blueprint $table) {
            $table->string('estrutura', 20)->default('por_anuncio')->after('objetivo');
            $table->string('meta_adset_id')->nullable()->after('meta_campaign_id');
            // Texto e título passam a ser só o padrão: cada anúncio pode ter o seu.
            $table->text('texto_principal')->nullable()->change();
            $table->string('titulo')->nullable()->change();
        });

        Schema::table('meta_ads_videos', function (Blueprint $table) {
            $table->string('tipo', 10)->default('video')->after('ordem');
            $table->foreignId('produto_id')->nullable()->after('duracao_segundos')->constrained('products')->nullOnDelete();
            $table->string('link_destino', 500)->nullable()->after('produto_id');
            $table->text('texto_principal')->nullable()->after('link_destino');
            $table->string('titulo')->nullable()->after('texto_principal');
            $table->string('descricao')->nullable()->after('titulo');
            $table->string('chamada', 40)->nullable()->after('descricao');
            $table->string('meta_image_hash')->nullable()->after('meta_video_id');
        });
    }

    public function down(): void
    {
        Schema::table('meta_ads_videos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('produto_id');
            $table->dropColumn(['tipo', 'link_destino', 'texto_principal', 'titulo', 'descricao', 'chamada', 'meta_image_hash']);
        });

        Schema::table('meta_ads_campanhas', function (Blueprint $table) {
            $table->dropColumn(['estrutura', 'meta_adset_id']);
        });
    }
};
