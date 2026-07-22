<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('cpf', 14)->nullable()->unique()->after('phone');
            $table->date('birth_date')->nullable()->after('cpf');
            $table->string('avatar_path')->nullable()->after('birth_date');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'cpf', 'birth_date', 'avatar_path']);
        });
    }
};
