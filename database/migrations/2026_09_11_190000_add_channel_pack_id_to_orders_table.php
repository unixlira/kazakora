<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carrinho do Mercado Livre (pack) passa a ser conhecido pelo pedido.
 *
 * BUG REAL 2026-09-11 (pack 2000014906196989, pedidos #1588/#1589): a
 * compradora levou 2 anúncios num carrinho só, o ML guarda isso como 2
 * pedidos ligados por um pack_id, e o Kazakora importava pedido por pedido
 * sem nunca olhar o pack. Saiu uma NF-e por pedido, o ML recusou as duas
 * ("The NFe value does not equal the purchase value" — o envio exige UMA
 * nota no valor do carrinho) e a venda ficou 4 dias parada sem etiqueta.
 *
 * Com o pack_id no pedido, a emissão sabe quem são os irmãos e sai uma nota
 * só (ver MercadoLivrePackInvoiceGate e PackDoPedido).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('channel_pack_id', 40)->nullable()->after('external_order_id');
            $table->index(['origin', 'channel_pack_id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['origin', 'channel_pack_id']);
            $table->dropColumn('channel_pack_id');
        });
    }
};
