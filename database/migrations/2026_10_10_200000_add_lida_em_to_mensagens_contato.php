<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Caixa de e-mails do site no admin (pedido 2026-10-10): lida/não lida. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mensagens_contato', function (Blueprint $table) {
            $table->timestamp('lida_em')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('mensagens_contato', function (Blueprint $table) {
            $table->dropIndex(['lida_em']);
            $table->dropColumn('lida_em');
        });
    }
};
