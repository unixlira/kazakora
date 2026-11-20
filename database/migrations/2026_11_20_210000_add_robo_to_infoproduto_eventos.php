<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Robô de revisão de anúncios da Meta (2026-11-20): logo depois de criar os
 * anúncios do Jarvis (19/11, 22:53 a 23:08) vieram 37 cliques em "comprar"
 * de US/IE/SE, todos de 2a03:2880::/32 (rede da Meta), antes de a campanha
 * rodar. A sessão inteira fica marcada (robo = 'meta-revisao') e sai das
 * contagens por padrão. Idempotente: a coluna pode já existir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('infoproduto_eventos', 'robo')) {
            return;
        }
        Schema::table('infoproduto_eventos', function (Blueprint $table) {
            $table->string('robo', 20)->nullable()->after('teste');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('infoproduto_eventos', 'robo')) {
            Schema::table('infoproduto_eventos', function (Blueprint $table) {
                $table->dropColumn('robo');
            });
        }
    }
};
