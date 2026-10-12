<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada chamada REAL à API do Kalodata (resposta de cache não entra). Serve
 * para o teto diário de chamadas (KALODATA_TETO_DIARIO) e para ver onde os
 * créditos foram gastos. Ver App\Services\Kalodata\KalodataService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kalodata_chamadas', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint', 120);
            $table->string('corpo', 500)->nullable();
            $table->boolean('sucesso')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('codigo', 20)->nullable();
            $table->string('mensagem', 255)->nullable();
            $table->unsignedInteger('duracao_ms')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kalodata_chamadas');
    }
};
