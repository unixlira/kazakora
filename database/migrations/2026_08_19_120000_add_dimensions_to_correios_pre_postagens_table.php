<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dimensões eram só montadas em memória (CorreiosPrePostagemService::
 * buildDimensions()) e mandadas pra API, nunca gravadas — então uma
 * pré-postagem que falhou (ex.: erro de validação recuperável, como o
 * "conteúdo > 60 caracteres") não dava pra reabrir preenchida de verdade
 * pra corrigir e tentar de novo (pedido explícito 2026-08-19: "isso pode
 * ser editável até gerar o qrcode"). Guarda o snapshot completo, igual já
 * é feito pros outros campos de envio nesta tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correios_pre_postagens', function (Blueprint $table) {
            $table->string('dimension_format', 1)->default('2')->after('weight_grams');
            $table->decimal('dimension_height', 8, 2)->nullable()->after('dimension_format');
            $table->decimal('dimension_width', 8, 2)->nullable()->after('dimension_height');
            $table->decimal('dimension_length', 8, 2)->nullable()->after('dimension_width');
            $table->decimal('dimension_diameter', 8, 2)->nullable()->after('dimension_length');
        });
    }

    public function down(): void
    {
        Schema::table('correios_pre_postagens', function (Blueprint $table) {
            $table->dropColumn(['dimension_format', 'dimension_height', 'dimension_width', 'dimension_length', 'dimension_diameter']);
        });
    }
};
