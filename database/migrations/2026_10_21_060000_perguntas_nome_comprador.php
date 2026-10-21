<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perguntas MK Place (Lira, 2026-10-20): a lista mostra quem perguntou. No
 * Mercado Livre é o apelido público do comprador (GET /users/{id}).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ml_perguntas', 'comprador_nome')) {
            return;
        }

        Schema::table('ml_perguntas', function (Blueprint $table) {
            $table->string('comprador_nome', 120)->nullable()->after('comprador_id');
        });
    }

    public function down(): void
    {
        Schema::table('ml_perguntas', function (Blueprint $table) {
            $table->dropColumn('comprador_nome');
        });
    }
};
