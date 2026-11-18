<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pop-up de cookies com "Aceito" e "Somente o básico" (pedido do Lira
 * 2026-11-18): a mesma tabela guarda a prova do aceite e a da recusa. Tudo o
 * que existe até aqui foi aceite, por isso o padrão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consentimentos_cookies', function (Blueprint $table) {
            $table->string('escolha', 10)->default('aceito')->after('versao');
        });
    }

    public function down(): void
    {
        Schema::table('consentimentos_cookies', function (Blueprint $table) {
            $table->dropColumn('escolha');
        });
    }
};
