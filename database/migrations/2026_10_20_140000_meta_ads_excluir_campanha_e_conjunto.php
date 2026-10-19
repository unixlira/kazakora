<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta ADS (2026-10-20): editar e excluir campanha e conjunto que já estão
 * na Meta, pelo admin. Excluído na Meta sai da tela mas fica no banco
 * (exclusão suave), como o anúncio (migration 2026_10_19_200000), para não
 * perder o vínculo com o gasto que já teve. Rascunho continua saindo de vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ads_campanhas', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('meta_ads_conjuntos', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('meta_ads_conjuntos', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('meta_ads_campanhas', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
