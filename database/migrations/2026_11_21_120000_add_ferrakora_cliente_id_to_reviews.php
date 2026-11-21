<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Modal "Avalie sua compra" na FerraKora (pedido do Lira 2026-11-21): a conta
// do site é de ferrakora_clientes, não de users. A avaliação guarda quem foi.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('ferrakora_cliente_id')->nullable()->after('user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['ferrakora_cliente_id']);
            $table->dropColumn('ferrakora_cliente_id');
        });
    }
};
