<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PDFs Escolhidos (Lira, 2026-11-18): oferta do radar de PDF que foi clonada
 * pelo botão "Clonar". O clone é uma FOTO da oferta no momento do clique:
 * o importador apaga anúncio que sumiu e inativa oferta que saiu do ranking,
 * então dados, textos dos anúncios e mídias são copiados para cá (mídias em
 * pdf-escolhidos/<id>/ no disco public). Junto nascem o plano de ação e o
 * prompt que gera o entregável (o PDF nosso, em português).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_escolhidos', function (Blueprint $table) {
            $table->id();
            // Uma oferta só é clonada uma vez; se a oferta for apagada, o clone fica.
            $table->foreignId('pdf_oferta_id')->nullable()->unique()->constrained('pdf_ofertas')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nome');
            $table->string('nicho', 80)->nullable();
            $table->string('idioma', 10)->nullable();
            $table->decimal('preco', 12, 2)->nullable();
            $table->string('moeda', 8)->nullable();
            $table->string('miniatura')->nullable();
            // Resto da oferta original (anunciante, domínio, países, métricas, URLs).
            $table->json('dados')->nullable();
            // [{adId, link, titulo, texto, cta, urlDestino, iniciadoEm, variacoes, plataformas, midias:[{tipo, caminho, poster}]}]
            $table->json('anuncios')->nullable();
            // [{titulo, itens: [..]}]
            $table->json('plano_acao')->nullable();
            $table->longText('prompt_entregavel');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_escolhidos');
    }
};
