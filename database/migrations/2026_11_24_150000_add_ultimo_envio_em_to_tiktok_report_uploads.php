<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Última vez que o arquivo chegou pela API (24/11/2026). Arquivo igual ao
 * anterior não vira linha nova; sem esta coluna não dá para saber se o agente
 * continua mandando ou se parou (o vigia loja:vigiar-coletas usa ela).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_report_uploads', function (Blueprint $table) {
            $table->timestamp('ultimo_envio_em')->nullable()->after('status');
        });

        DB::table('tiktok_report_uploads')->update(['ultimo_envio_em' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('tiktok_report_uploads', function (Blueprint $table) {
            $table->dropColumn('ultimo_envio_em');
        });
    }
};
