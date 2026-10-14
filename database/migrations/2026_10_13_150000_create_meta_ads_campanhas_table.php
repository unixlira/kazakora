<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta ADS (2026-10-13): campanhas de vídeo montadas no admin e enviadas
 * pela Marketing API da Meta. Cada vídeo vira um conjunto de anúncios com a
 * sua parte do orçamento diário (pedido do Lira: 5 vídeos = uma campanha
 * com o orçamento dividido entre eles).
 *
 * Os ids meta_* são gravados a cada passo do envio, para um reenvio depois
 * de erro continuar de onde parou em vez de duplicar campanha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ads_campanhas', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('objetivo', 40);
            // Orçamento diário da campanha inteira; cada vídeo recebe a sua parte.
            $table->decimal('orcamento_diario', 10, 2);
            $table->date('inicio');
            $table->date('fim')->nullable();

            $table->unsignedTinyInteger('idade_min')->default(18);
            $table->unsignedTinyInteger('idade_max')->default(65);
            $table->string('genero', 20)->default('todos');
            $table->json('localizacoes')->nullable();

            $table->foreignId('produto_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('link_destino', 500);
            $table->text('texto_principal');
            $table->string('titulo');
            $table->string('descricao')->nullable();
            $table->string('chamada', 40)->default('SHOP_NOW');

            $table->string('status', 30)->default('rascunho');
            $table->string('meta_campaign_id')->nullable();
            $table->text('ultimo_erro')->nullable();
            $table->timestamp('enviada_em')->nullable();
            $table->timestamp('ativada_em')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('meta_ads_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campanha_id')->constrained('meta_ads_campanhas')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->string('nome_original');
            // Disco local (storage/app/private), fora do público.
            $table->string('path', 500);
            $table->unsignedBigInteger('tamanho')->default(0);
            $table->decimal('duracao_segundos', 8, 2)->nullable();

            // Parte do orçamento diário gravada no envio (R$).
            $table->decimal('orcamento_diario', 10, 2)->nullable();
            $table->string('meta_video_id')->nullable();
            $table->string('meta_thumbnail_url', 1000)->nullable();
            $table->string('meta_adset_id')->nullable();
            $table->string('meta_creative_id')->nullable();
            $table->string('meta_ad_id')->nullable();
            $table->text('ultimo_erro')->nullable();
            $table->timestamps();

            $table->index(['campanha_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ads_videos');
        Schema::dropIfExists('meta_ads_campanhas');
    }
};
