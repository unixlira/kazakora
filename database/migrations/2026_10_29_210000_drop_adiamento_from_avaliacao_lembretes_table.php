<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modal "Avalie sua compra" (Lira mudou em 2026-10-29): sem a espera de 15
 * dias, o modal volta em todo login até o cliente avaliar. As colunas do
 * adiamento (adiado_ate, reaberto_em) saem; o registro do "Não avaliar
 * agora" (vezes_nao_avaliar, nao_avaliar_em) continua.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('avaliacao_lembretes', 'adiado_ate')) {
            return;
        }

        Schema::table('avaliacao_lembretes', function (Blueprint $table) {
            $table->dropIndex(['adiado_ate']);
            $table->dropColumn(['adiado_ate', 'reaberto_em']);
        });
    }

    public function down(): void
    {
        Schema::table('avaliacao_lembretes', function (Blueprint $table) {
            $table->timestamp('adiado_ate')->nullable()->after('nao_avaliar_em');
            $table->timestamp('reaberto_em')->nullable()->after('adiado_ate');
            $table->index('adiado_ate');
        });
    }
};
