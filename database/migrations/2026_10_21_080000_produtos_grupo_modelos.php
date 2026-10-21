<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modelos ligados (Lira, 2026-10-21): anúncios separados (sem pai/filho) que
 * se mostram como "modelos" uns dos outros na página do produto, ex.: Caixa
 * Sanfonada 85 peças e 168 peças. Mesmo `grupo_modelos` = mesmo seletor;
 * `rotulo_modelo` é o nome da opção ("85 peças").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'grupo_modelos')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->string('grupo_modelos', 100)->nullable()->after('variation')->index();
            $table->string('rotulo_modelo', 100)->nullable()->after('grupo_modelos');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['grupo_modelos']);
            $table->dropColumn(['grupo_modelos', 'rotulo_modelo']);
        });
    }
};
