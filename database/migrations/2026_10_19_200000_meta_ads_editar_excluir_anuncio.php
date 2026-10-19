<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta ADS (2026-10-19): editar e excluir anúncio que já está na Meta.
 *
 * - nome: nome do anúncio escolhido no KazaKora (vazio = o nome automático
 *   "campanha | conjunto | anúncio N").
 * - edicao_pendente: troca de vídeo esperando a Meta processar o arquivo
 *   novo. Os campos do anúncio só mudam quando a Meta aceita o criativo.
 * - deleted_at: anúncio excluído na Meta continua no banco (exclusão
 *   suave) para não perder o vínculo com o gasto que ele já teve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ads_videos', function (Blueprint $table) {
            $table->string('nome', 255)->nullable()->after('ordem');
            $table->json('edicao_pendente')->nullable()->after('ultimo_erro');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('meta_ads_videos', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['nome', 'edicao_pendente']);
        });
    }
};
