<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imagens anexadas a uma avaliação importada de marketplace (ex.: foto que
 * o comprador enviou junto do comentário na Shopee). Guarda a URL do CDN
 * do próprio canal em vez de baixar/re-hospedar (diferente de
 * ProductImage) — o cron roda periodicamente pra todos os produtos de
 * todos os canais, então evitar download+otimização por imagem aqui
 * mantém o job leve; os links da Shopee são estáveis (mesmo domínio usado
 * pelas fotos de anúncio, já linkadas direto noutros pontos do admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->string('image_url', 1000);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_images');
    }
};
