<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Oportunidades › KazaKora (Lira, 2026-11-18): produtos FÍSICOS que estão
 * escalando na Biblioteca de Anúncios da Meta (infoproduto vai para o
 * digitalmktseller). Métricas do PLANO-KORABOX-CRIATIVOS-VENCEDORES: dias no
 * ar, variações ativas, anunciantes vendendo a mesma oferta, alcance na UE e
 * criativos novos em 7 dias, que viram a pontuação e a fase do termômetro.
 *
 * kz_criativos: os criativos NOSSOS pedidos pela tela (clonar um anúncio ou
 * criar do zero), cada um com código (KZ-AAMMDD-0001) e pasta no servidor
 * (storage/app/criativos-kazakora/<código>) com o roteiro completo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kz_oportunidades', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 191)->unique();
            $table->unsignedSmallInteger('posicao')->nullable()->index();
            $table->boolean('ativa')->default(true)->index();
            $table->string('nome');
            $table->string('nicho', 80)->nullable()->index();
            $table->string('idioma', 10)->nullable();
            $table->string('anunciante')->nullable();
            $table->string('pagina_id', 40)->nullable();
            $table->text('url_destino')->nullable();
            $table->string('dominio')->nullable();
            $table->decimal('preco', 12, 2)->nullable();
            $table->string('moeda', 8)->nullable();
            $table->json('paises')->nullable();
            $table->unsignedInteger('anuncios_ativos')->default(0);
            $table->unsignedInteger('variacoes')->default(0);
            $table->unsignedInteger('anunciantes_distintos')->default(1);
            $table->unsignedBigInteger('alcance_ue')->nullable();
            $table->unsignedInteger('criativos_novos_7d')->default(0);
            $table->date('inicio_mais_antigo')->nullable();
            $table->unsignedInteger('dias_rodando')->default(0);
            // 0-100 e fase (teste, validando, escala, saturando): App\Modules\Admin\Support\TermometroOportunidade.
            $table->decimal('pontuacao', 5, 1)->default(0);
            $table->string('fase', 20)->default('teste')->index();
            $table->string('miniatura')->nullable();
            $table->text('observacao')->nullable();
            $table->timestamp('coletado_em')->nullable();
            $table->timestamps();
        });

        Schema::create('kz_oportunidade_anuncios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kz_oportunidade_id')->constrained('kz_oportunidades')->cascadeOnDelete();
            $table->string('ad_archive_id', 40)->unique();
            $table->text('texto')->nullable();
            $table->string('titulo', 500)->nullable();
            $table->string('cta', 120)->nullable();
            $table->text('url_destino')->nullable();
            $table->date('iniciado_em')->nullable();
            $table->unsignedInteger('variacoes')->default(1);
            $table->unsignedBigInteger('alcance_ue')->nullable();
            $table->json('plataformas')->nullable();
            // [{tipo: video|imagem, caminho, poster}] no disco public, kz-oportunidades/<oferta>/.
            $table->json('midias')->nullable();
            $table->timestamps();
        });

        Schema::create('kz_criativos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->nullable()->unique();
            $table->foreignId('kz_oportunidade_id')->constrained('kz_oportunidades')->cascadeOnDelete();
            // Anúncio de referência quando clonado; null quando criado do zero.
            $table->foreignId('kz_oportunidade_anuncio_id')->nullable()->constrained('kz_oportunidade_anuncios')->nullOnDelete();
            $table->string('referencia_ad_id', 40)->nullable();
            $table->string('origem', 10); // clone | zero
            $table->string('lote', 36)->index();
            $table->unsignedTinyInteger('numero')->default(1);
            $table->unsignedTinyInteger('total_lote')->default(1);
            // roteirizando -> roteiro_pronto -> produzindo -> pronto -> aprovado | reprovado; erro
            $table->string('status', 20)->default('roteirizando')->index();
            $table->json('briefing')->nullable();
            $table->string('pasta')->nullable();
            $table->string('video')->nullable();
            $table->text('erro')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kz_criativos');
        Schema::dropIfExists('kz_oportunidade_anuncios');
        Schema::dropIfExists('kz_oportunidades');
    }
};
