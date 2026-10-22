<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promoções › Texto e regras (pedido do Lira 2026-10-21): regras de negócio
 * próprias, além do período e do valor mínimo. Vazio = sem limite; as chaves
 * livres (`regras`) continuam para o que não tem campo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promocoes', function (Blueprint $table) {
            $table->unsignedSmallInteger('itens_minimos')->nullable()->after('valor_minimo');
            $table->unsignedSmallInteger('limite_por_cliente')->nullable()->after('itens_minimos');
            $table->boolean('primeira_compra')->default(false)->after('limite_por_cliente');
            $table->boolean('acumula_cupom')->default(true)->after('primeira_compra');
        });
    }

    public function down(): void
    {
        Schema::table('promocoes', function (Blueprint $table) {
            $table->dropColumn(['itens_minimos', 'limite_por_cliente', 'primeira_compra', 'acumula_cupom']);
        });
    }
};
