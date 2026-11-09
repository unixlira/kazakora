<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acompanhamento do envio pelo Melhor Envio (2026-11-09, árvore #3978):
 * status do Melhor Envio, postagem, entrega e cancelamento, lidos da API
 * pelo melhorenvio:acompanhar, e o prazo da transportadora para a previsão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('melhor_envio_envios', function (Blueprint $table) {
            $table->string('situacao', 20)->nullable()->after('status'); // status no Melhor Envio: released, posted, delivered...
            $table->unsignedSmallInteger('prazo_dias')->nullable()->after('preco');
            $table->timestamp('postada_em')->nullable()->after('gerada_em');
            $table->timestamp('entregue_em')->nullable()->after('postada_em');
            $table->timestamp('cancelada_em')->nullable()->after('entregue_em');
        });
    }

    public function down(): void
    {
        Schema::table('melhor_envio_envios', function (Blueprint $table) {
            $table->dropColumn(['situacao', 'prazo_dias', 'postada_em', 'entregue_em', 'cancelada_em']);
        });
    }
};
