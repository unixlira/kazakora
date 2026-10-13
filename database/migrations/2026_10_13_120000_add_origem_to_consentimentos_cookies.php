<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origem do OK de cookies (pedido 2026-10-13): o mesmo backend recebe o
 * consentimento da KazaKora e da Beauty Kora. Os registros que já existem
 * são todos da KazaKora, por isso o padrão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consentimentos_cookies', function (Blueprint $table) {
            $table->string('origem', 20)->default('kazakora')->index()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('consentimentos_cookies', function (Blueprint $table) {
            $table->dropColumn('origem');
        });
    }
};
