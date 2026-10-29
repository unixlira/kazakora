<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pré-postagem de DEVOLUÇÃO (pedido do Lira 2026-10-29, pedido 1821): a
 * cliente é o remetente e a KazaKora o destinatário. Não é envio do pedido:
 * não conta como etiqueta, rastreio, frete pago nem "pedido enviado".
 * Todas as existentes são envio (default false).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correios_pre_postagens', function (Blueprint $table) {
            $table->boolean('devolucao')->default(false)->after('amazon_confirmado_em');
        });
    }

    public function down(): void
    {
        Schema::table('correios_pre_postagens', function (Blueprint $table) {
            $table->dropColumn('devolucao');
        });
    }
};
