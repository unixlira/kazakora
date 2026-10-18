<?php

use App\Modules\Marketplace\Models\MetaAdsCampanha;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Meta ADS (2026-10-18): a campanha passa a ter a estrutura inteira do
 * Gerenciador da Meta, Campanha > Conjuntos > Anúncios, montada de uma vez.
 * Pedido do Lira: "Black November" com os conjuntos Árvore de Natal (3
 * anúncios), Ferramentas (2) e Caixa de Ferramentas (2).
 *
 * - meta_ads_conjuntos: cada conjunto tem nome, orçamento (quando o
 *   orçamento é por conjunto) e, opcional, público próprio; vazio usa o
 *   público da campanha.
 * - campanha.orcamento_tipo: 'campanha' (Advantage, a Meta distribui entre
 *   os conjuntos) ou 'conjunto' (cada conjunto com o seu valor). Substitui
 *   campanha.estrutura e campanha.meta_adset_id (migration 220000).
 *
 * Campanhas que já existiam viram conjuntos, guardando o id do ad set já
 * criado na Meta:
 * - estrutura conjunto_unico: 1 conjunto com todos os vídeos e o orçamento
 *   inteiro (o ad set era o da campanha);
 * - estrutura por_anuncio: 1 conjunto por vídeo com a parte dele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ads_conjuntos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campanha_id')->constrained('meta_ads_campanhas')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->string('nome', 120);
            $table->decimal('orcamento_diario', 10, 2)->nullable();
            $table->boolean('publico_proprio')->default(false);
            $table->unsignedTinyInteger('idade_min')->nullable();
            $table->unsignedTinyInteger('idade_max')->nullable();
            $table->string('genero', 20)->nullable();
            $table->json('localizacoes')->nullable();
            $table->string('meta_adset_id')->nullable();
            $table->text('ultimo_erro')->nullable();
            $table->timestamps();

            $table->index(['campanha_id', 'ordem']);
        });

        Schema::table('meta_ads_campanhas', function (Blueprint $table) {
            $table->string('orcamento_tipo', 10)->default('campanha')->after('objetivo');
        });

        Schema::table('meta_ads_videos', function (Blueprint $table) {
            $table->foreignId('conjunto_id')->nullable()->after('campanha_id')->constrained('meta_ads_conjuntos')->cascadeOnDelete();
        });

        $this->migrarCampanhasExistentes();

        Schema::table('meta_ads_campanhas', function (Blueprint $table) {
            $table->dropColumn(['estrutura', 'meta_adset_id']);
        });
    }

    public function down(): void
    {
        Schema::table('meta_ads_campanhas', function (Blueprint $table) {
            $table->string('estrutura', 20)->default('por_anuncio')->after('objetivo');
            $table->string('meta_adset_id')->nullable()->after('meta_campaign_id');
        });

        Schema::table('meta_ads_videos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conjunto_id');
        });

        Schema::table('meta_ads_campanhas', function (Blueprint $table) {
            $table->dropColumn('orcamento_tipo');
        });

        Schema::dropIfExists('meta_ads_conjuntos');
    }

    private function migrarCampanhasExistentes(): void
    {
        $campanhas = DB::table('meta_ads_campanhas')->orderBy('id')->get(['id', 'nome', 'estrutura', 'meta_adset_id', 'orcamento_diario']);

        foreach ($campanhas as $campanha) {
            $videos = DB::table('meta_ads_videos')->where('campanha_id', $campanha->id)->orderBy('ordem')->orderBy('id')->get();

            if ($campanha->estrutura === 'conjunto_unico') {
                // O conjunto único tinha o orçamento inteiro no ad set.
                DB::table('meta_ads_campanhas')->where('id', $campanha->id)->update(['orcamento_tipo' => 'conjunto']);
                $conjunto = DB::table('meta_ads_conjuntos')->insertGetId([
                    'campanha_id' => $campanha->id,
                    'ordem' => 0,
                    'nome' => 'Conjunto 1',
                    'orcamento_diario' => $campanha->orcamento_diario,
                    'meta_adset_id' => $campanha->meta_adset_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('meta_ads_videos')->where('campanha_id', $campanha->id)->update(['conjunto_id' => $conjunto]);

                continue;
            }

            DB::table('meta_ads_campanhas')->where('id', $campanha->id)->update(['orcamento_tipo' => 'conjunto']);
            $partes = MetaAdsCampanha::dividirOrcamento((float) $campanha->orcamento_diario, $videos->count());

            foreach ($videos->values() as $i => $video) {
                $conjunto = DB::table('meta_ads_conjuntos')->insertGetId([
                    'campanha_id' => $campanha->id,
                    'ordem' => $i,
                    'nome' => mb_substr(pathinfo($video->nome_original, PATHINFO_FILENAME), 0, 120) ?: 'Conjunto '.($i + 1),
                    'orcamento_diario' => $video->orcamento_diario ?? $partes[$i] / 100,
                    'meta_adset_id' => $video->meta_adset_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('meta_ads_videos')->where('id', $video->id)->update(['conjunto_id' => $conjunto]);
            }
        }
    }
};
