<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito 2026-08-22: login self-service (usuário/senha -> JWT)
 * pra parceiro de API, além do token estático já existente (emitido só
 * pelo admin, ver ApiPartnerController::issueToken()). `slug` já existente
 * funciona como "usuário" — não precisa de coluna nova pra isso, só a
 * senha. Nullable: um parceiro pode continuar só com token estático,
 * sem nunca ganhar senha, se o admin não definir uma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_partners', function (Blueprint $table) {
            $table->string('password')->nullable()->after('contact_email');
        });
    }

    public function down(): void
    {
        Schema::table('api_partners', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
