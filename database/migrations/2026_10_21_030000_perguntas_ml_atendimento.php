<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perguntas MK Place (2026-10-20): quem assumiu a pergunta (atendimento
 * humano), quem respondeu (Manuela, atendente ou direto no ML) e o erro do
 * último envio, para a tela do admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ml_perguntas', 'humano_assumiu_em')) {
            return;
        }

        Schema::table('ml_perguntas', function (Blueprint $table) {
            $table->unsignedBigInteger('humano_assumiu_por')->nullable()->after('precisa_humano');
            $table->timestamp('humano_assumiu_em')->nullable()->after('humano_assumiu_por');
            $table->string('respondida_por', 16)->nullable()->after('resposta_enviada');
            $table->unsignedBigInteger('respondida_por_user_id')->nullable()->after('respondida_por');
            $table->text('erro_envio')->nullable()->after('respondida_por_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('ml_perguntas', function (Blueprint $table) {
            $table->dropColumn(['humano_assumiu_por', 'humano_assumiu_em', 'respondida_por', 'respondida_por_user_id', 'erro_envio']);
        });
    }
};
