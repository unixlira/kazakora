<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Validação de criativos (pedido do Lira 2026-10-21): texto curto embaixo de
 * cada vídeo dizendo do que se trata e de qual campanha é, editável ali mesmo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('criativos_validacao', function (Blueprint $table) {
            $table->string('identificacao', 255)->nullable()->after('titulo');
        });
    }

    public function down(): void
    {
        Schema::table('criativos_validacao', function (Blueprint $table) {
            $table->dropColumn('identificacao');
        });
    }
};
