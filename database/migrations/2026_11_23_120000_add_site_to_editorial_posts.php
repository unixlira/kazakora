<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blog do FerraKora (pedido do Lira, 23/11/2026): as matérias ficam na mesma
 * tabela do blog da KazaKora, separadas pela coluna "site". Tudo o que já
 * existe continua na KazaKora (default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('editorial_posts', function (Blueprint $t) {
            $t->string('site', 20)->default('kazakora')->after('id');
            $t->index(['site', 'status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('editorial_posts', function (Blueprint $t) {
            $t->dropIndex(['site', 'status', 'published_at']);
            $t->dropColumn('site');
        });
    }
};
