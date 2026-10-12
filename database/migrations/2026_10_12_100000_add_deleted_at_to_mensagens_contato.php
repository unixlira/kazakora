<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mails do site (pedido 2026-10-12): excluir mensagem vai para a lixeira
 * (soft delete), para dar para restaurar se apagar errado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('mensagens_contato', 'deleted_at')) {
            return;
        }

        Schema::table('mensagens_contato', function (Blueprint $table) {
            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        Schema::table('mensagens_contato', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });
    }
};
