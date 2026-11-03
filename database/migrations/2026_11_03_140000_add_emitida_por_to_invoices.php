<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quem emitiu a NF-e do pedido (pedido do Lira em 2026-11-03): a nota que o
 * TikTok emite pela própria plataforma (série 6) passa a ser registrada no
 * pedido, e a sincronização do Bling não pode sobrescrevê-la. Vazio = regra
 * antiga pela série (2 KazaKora, 3 Bling).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('emitida_por', 20)->nullable()->after('origem');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('emitida_por');
        });
    }
};
