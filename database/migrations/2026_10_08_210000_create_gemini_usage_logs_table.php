<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uma linha por chamada ao Gemini (resposta da Manuela ou transcrição de
 * áudio), com os tokens que o próprio Google informa. Pedido 2026-10-08:
 * acompanhar os créditos da conta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gemini_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->string('model', 80);
            $table->string('purpose', 20);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gemini_usage_logs');
    }
};
