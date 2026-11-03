<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devoluções do TikTok Shop entram sozinhas no controle de devoluções
 * (pedido do Lira 03/11/2026). A plataforma manda o produto (nome, cor, SKU)
 * e a observação do cliente; os dois ficam no caso, que aparece mesmo quando
 * o pedido ainda não está no Kazakora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_returns', function (Blueprint $table) {
            $table->string('product_label', 500)->nullable()->after('reason_label');
            $table->text('buyer_note')->nullable()->after('product_label');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_returns', function (Blueprint $table) {
            $table->dropColumn(['product_label', 'buyer_note']);
        });
    }
};
