<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_eventos', function (Blueprint $table) {
            $table->string('jornada_chave', 64)->nullable()->unique();
            $table->json('jornada')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checkout_eventos', function (Blueprint $table) {
            $table->dropUnique(['jornada_chave']);
            $table->dropColumn(['jornada_chave', 'jornada']);
        });
    }
};