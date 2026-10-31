<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A coluna já existe em produção (criada direto no banco em 20/10/2026); esta migração a garante nos outros bancos.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reviews', 'outra_loja')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->boolean('outra_loja')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        // Sem volta: em produção a coluna é anterior a esta migração.
    }
};
