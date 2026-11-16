<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Onde levar o pacote (ponto de postagem), mostrado no card do KoraSync (2026-11-09). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('melhor_envio_envios', function (Blueprint $table) {
            $table->json('postagem')->nullable()->after('cancelada_em');
        });
    }

    public function down(): void
    {
        Schema::table('melhor_envio_envios', function (Blueprint $table) {
            $table->dropColumn('postagem');
        });
    }
};
