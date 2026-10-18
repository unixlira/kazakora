<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API de Conversões da Meta (pedido 2026-10-18, ver App\Services\Meta\ConversoesMeta):
 * - meta_rastreio: o que o servidor precisa para mandar o Purchase quando o
 *   pagamento aprova (às vezes dias depois, sem o navegador do cliente):
 *   _fbp, _fbc, IP, navegador e página. Só grava com o OK de cookies; apagado
 *   assim que o Purchase sai (ou aos 30 dias, privacidade:limpar).
 * - meta_purchase_enviado_em: trava de "uma vez só por pedido".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('meta_rastreio')->nullable();
            $table->timestamp('meta_purchase_enviado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['meta_rastreio', 'meta_purchase_enviado_em']);
        });
    }
};
