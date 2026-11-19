<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nome interno do produto (18/11/2026): o `name` virou título de vitrine
 * ("Acorde Sem Dor no Pescoço + Guia PDF ..."), que não pode sair na NF-e
 * nem na separação. Pedido da loja própria grava nome_interno ?? name no
 * item; marketplace grava o título do anúncio que o canal manda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('nome_interno')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('nome_interno');
        });
    }
};
