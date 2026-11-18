<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radar de PDF (Lira, 2026-11-17): as ofertas de infoproduto em PDF mais
 * quentes do mundo, coletadas da Biblioteca de Anúncios da Meta pelo coletor
 * do Homer (ferramentas/radar-pdf-mundo) e importadas com
 * `php artisan pdf-oportunidades:importar ofertas.json`. Uma oferta é o
 * destino do anúncio (página de vendas/checkout); cada anúncio guarda o
 * criativo baixado (as URLs do fbcdn expiram) e o link na Biblioteca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_ofertas', function (Blueprint $table) {
            $table->id();
            // Chave estável da oferta (domínio + caminho); reimportar atualiza pela chave.
            $table->string('chave', 191)->unique();
            $table->unsignedSmallInteger('posicao')->nullable()->index();
            $table->boolean('ativa')->default(true)->index();
            $table->string('nome');
            $table->string('nicho', 80)->nullable()->index();
            $table->string('idioma', 10)->nullable()->index();
            $table->string('anunciante')->nullable();
            $table->string('pagina_id', 40)->nullable();
            $table->text('url_destino')->nullable();
            $table->string('dominio')->nullable();
            $table->string('plataforma', 40)->nullable();
            $table->decimal('preco', 12, 2)->nullable();
            $table->string('moeda', 8)->nullable();
            $table->json('paises')->nullable();
            $table->unsignedInteger('anuncios_ativos')->default(0);
            $table->unsignedInteger('variacoes')->default(0);
            $table->date('inicio_mais_antigo')->nullable();
            $table->unsignedInteger('dias_rodando')->default(0);
            $table->decimal('pontuacao', 8, 2)->default(0);
            $table->string('miniatura')->nullable();
            $table->text('observacao')->nullable();
            $table->timestamp('coletado_em')->nullable();
            $table->timestamps();
        });

        Schema::create('pdf_oferta_anuncios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdf_oferta_id')->constrained('pdf_ofertas')->cascadeOnDelete();
            $table->string('ad_archive_id', 40)->unique();
            $table->text('texto')->nullable();
            $table->string('titulo', 500)->nullable();
            $table->string('cta', 120)->nullable();
            $table->text('url_destino')->nullable();
            $table->date('iniciado_em')->nullable();
            // Quantos anúncios usam o mesmo criativo (collation_count da Biblioteca).
            $table->unsignedInteger('variacoes')->default(1);
            $table->json('plataformas')->nullable();
            // [{tipo: video|imagem, caminho: 'pdf-oportunidades/..', poster: '...'}]
            $table->json('midias')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_oferta_anuncios');
        Schema::dropIfExists('pdf_ofertas');
    }
};
