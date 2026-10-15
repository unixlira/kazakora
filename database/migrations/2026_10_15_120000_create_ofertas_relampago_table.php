<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ofertas Relâmpago por empresa (pedido 2026-10-14): desconto % num produto
 * pai, de um horário a outro, só no site escolhido (KazaKora, FerraKora ou
 * Beauty Kora).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ofertas_relampago', function (Blueprint $table) {
            $table->id();
            $table->string('site', 20);
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('desconto_pct', 5, 2);
            $table->dateTime('inicio_em');
            $table->dateTime('fim_em');
            $table->boolean('ativo')->default(true);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['site', 'inicio_em', 'fim_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ofertas_relampago');
    }
};
