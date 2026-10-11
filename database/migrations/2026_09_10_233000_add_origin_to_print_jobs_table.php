<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De onde veio cada impressão.
 *
 * "Saiu etiqueta duplicada" já foi investigado três vezes (2026-09-07,
 * 2026-09-10 de manhã e de novo à noite) e TODA vez a investigação foi
 * arqueologia: cruzar horário de print_job com horário de evento da
 * timeline pra adivinhar qual caminho tinha criado o segundo papel —
 * automático, lote, reimpressão no clique ou teste.
 *
 * Com a origem gravada, a próxima pergunta "quem imprimiu isso duas
 * vezes?" é uma consulta, não uma escavação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->string('origin', 20)->nullable()->after('is_thank_you');
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
