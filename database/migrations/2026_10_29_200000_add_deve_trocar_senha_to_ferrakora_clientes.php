<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boas-vindas da FerraKora com senha temporária (pedido do Lira 2026-10-29,
 * igual à KazaKora): conta criada no checkout entra com a senha do e-mail e o
 * site pede uma senha pessoal no primeiro acesso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ferrakora_clientes', function (Blueprint $table) {
            $table->boolean('deve_trocar_senha')->default(false)->after('senha_definida');
        });
    }

    public function down(): void
    {
        Schema::table('ferrakora_clientes', function (Blueprint $table) {
            $table->dropColumn('deve_trocar_senha');
        });
    }
};
