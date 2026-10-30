<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modal "Avalie sua compra" (pedido do Lira 2026-10-29): o botão "Não avaliar
 * agora" fica registrado e esconde o produto por 15 dias; a rotina
 * avaliacoes:reabrir-lembretes devolve o modal no login seguinte, sempre, até
 * o cliente avaliar. Sem teto de aparições, por isso o contador cresce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacao_lembretes', function (Blueprint $table) {
            $table->unsignedInteger('aparicoes')->default(0)->change();
            $table->unsignedInteger('vezes_nao_avaliar')->default(0)->after('ultima_aparicao_em');
            $table->timestamp('nao_avaliar_em')->nullable()->after('vezes_nao_avaliar');
            $table->timestamp('adiado_ate')->nullable()->after('nao_avaliar_em');
            $table->timestamp('reaberto_em')->nullable()->after('adiado_ate');
            $table->index('adiado_ate');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacao_lembretes', function (Blueprint $table) {
            $table->dropIndex(['adiado_ate']);
            $table->dropColumn(['vezes_nao_avaliar', 'nao_avaliar_em', 'adiado_ate', 'reaberto_em']);
        });
    }
};
