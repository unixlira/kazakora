<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ranking do Kalodata já consultado (pedido do Lira 2026-10-12: guardar no
 * banco). Uma linha por coleta; o Radar lê a mais recente e só consulta de
 * novo quando ela passa da validade. Sobrevive a deploy e a cache:clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kalodata_rankings', function (Blueprint $table) {
            $table->id();
            $table->string('periodo', 20);
            $table->boolean('completo')->default(true);
            $table->unsignedInteger('total')->default(0);
            $table->longText('produtos');
            $table->timestamp('coletado_em');
            $table->timestamps();

            $table->index(['periodo', 'coletado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kalodata_rankings');
    }
};
