<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minha Bancada do FerraKora (pedido do Lira 2026-10-15): a lista de
 * favoritos do site, com nome de oficina para o público masculino. Cada
 * linha é um produto pai (o mesmo "kz-<id>" do catálogo) guardado por um
 * cliente do FerraKora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ferrakora_bancada', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('ferrakora_clientes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['cliente_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ferrakora_bancada');
    }
};
