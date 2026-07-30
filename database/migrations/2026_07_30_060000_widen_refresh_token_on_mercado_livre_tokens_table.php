<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mercado_livre_tokens', function (Blueprint $table) {
            $table->text('refresh_token')->change();
        });
    }

    public function down(): void
    {
        Schema::table('mercado_livre_tokens', function (Blueprint $table) {
            $table->string('refresh_token')->change();
        });
    }
};
