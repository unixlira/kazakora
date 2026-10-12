<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desconto da plataforma (pedido 2026-10-12, frete embutido): carrinho com
 * vários itens vai numa caixa só, então o frete somado item a item no preço
 * passa do frete real. A diferença volta para o cliente como desconto. Também
 * entra em discount_amount (desconto total do pedido, vDesc da NF-e), como o
 * desconto do Pix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('desconto_plataforma_amount', 10, 2)->default(0)->after('pix_discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('desconto_plataforma_amount');
        });
    }
};
