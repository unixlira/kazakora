<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cursor da Distribuição DFe (NfeDistribuicaoService) — a consulta da SEFAZ
 * é incremental por NSU (Número Sequencial Único), não por data/nota. Guarda
 * o maior NSU já processado pra próxima sincronização continuar de onde
 * parou em vez de reprocessar tudo desde o início toda vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedBigInteger('nfe_ultimo_nsu')->default(0)->after('certificate_password');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('nfe_ultimo_nsu');
        });
    }
};
