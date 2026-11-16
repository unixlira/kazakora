<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Situação de cada pré-postagem nos Correios (pedido do Lira 2026-11-16,
 * AP623701379BR do pedido Amazon #3181): a etiqueta expirou sem ninguém ver,
 * porque o `status` só diz gerada/erro/cancelada. O comando
 * correios:acompanhar-pre-postagens grava aqui o que o rastreio diz. Tudo
 * nulo até a primeira consulta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correios_pre_postagens', function (Blueprint $table) {
            $table->string('rastreio_situacao', 20)->nullable()->after('devolucao');
            $table->string('rastreio_ultimo_evento')->nullable()->after('rastreio_situacao');
            $table->json('rastreio_eventos')->nullable()->after('rastreio_ultimo_evento');
            $table->timestamp('postada_em')->nullable()->after('rastreio_eventos');
            $table->timestamp('entregue_em')->nullable()->after('postada_em');
            $table->timestamp('expirada_em')->nullable()->after('entregue_em');
            $table->timestamp('rastreio_consultado_em')->nullable()->after('expirada_em');
        });
    }

    public function down(): void
    {
        Schema::table('correios_pre_postagens', function (Blueprint $table) {
            $table->dropColumn([
                'rastreio_situacao',
                'rastreio_ultimo_evento',
                'rastreio_eventos',
                'postada_em',
                'entregue_em',
                'expirada_em',
                'rastreio_consultado_em',
            ]);
        });
    }
};
