<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financeiro_lancamentos', function (Blueprint $table) {
            $table->id();
            $table->date('data')->index();
            $table->string('canal', 40);
            $table->string('tipo', 40);
            $table->string('descricao')->nullable();
            // Positivo é custo, negativo é estorno da cobrança.
            $table->decimal('valor', 12, 2);
            $table->string('external_id', 80)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financeiro_lancamentos');
    }
};
