<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Endereço que o cliente digitou na compra (Lira, 2026-11-17, pedido #4246):
 * gravado uma vez só, na primeira alteração do endereço de entrega. As
 * alterações seguintes ficam na linha do tempo (address_changed), mas o
 * original nunca é sobrescrito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('endereco_digitado')->nullable()->after('amostra_em');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('endereco_digitado');
        });
    }
};
