<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avaliações › Cadastro e controle (pedido do Lira 2026-10-18):
 * - review_images.media_type: a avaliação cadastrada à mão aceita vídeo,
 *   além de foto (as importadas continuam 'image').
 * - contagem_avaliacoes_canais: quantas avaliações reais cada anúncio tem
 *   na Shopee e no Mercado Livre, lida das APIs (avaliacoes:contar-canais).
 *   Por anúncio e não por produto: o mesmo item da Shopee atende várias
 *   variações (ver ReviewImportService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_images', function (Blueprint $table) {
            $table->string('media_type', 10)->default('image')->after('image_url');
        });

        Schema::create('contagem_avaliacoes_canais', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 40);
            $table->string('external_id', 64);
            $table->unsignedInteger('total')->default(0);
            $table->decimal('nota_media', 3, 2)->nullable();
            $table->timestamp('conferido_em')->nullable();
            $table->string('erro')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contagem_avaliacoes_canais');

        Schema::table('review_images', function (Blueprint $table) {
            $table->dropColumn('media_type');
        });
    }
};
