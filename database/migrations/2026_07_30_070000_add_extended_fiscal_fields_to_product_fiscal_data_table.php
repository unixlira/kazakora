<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_fiscal_data', function (Blueprint $table) {
            $table->string('cfop_outros_estados', 4)->nullable()->after('cfop');
            $table->unsignedTinyInteger('tipo_operacao')->default(1)->after('cofins_aliquota');
            $table->string('recopi_numero', 30)->nullable()->after('tipo_operacao');
            $table->string('ex_tipi', 10)->nullable()->after('recopi_numero');
            $table->string('fci_numero', 40)->nullable()->after('ex_tipi');
            $table->text('informacoes_adicionais')->nullable()->after('fci_numero');
            $table->boolean('item_agrupavel')->default(false)->after('informacoes_adicionais');
            $table->decimal('percentual_aproximado_tributos', 5, 2)->nullable()->after('item_agrupavel');
        });
    }

    public function down(): void
    {
        Schema::table('product_fiscal_data', function (Blueprint $table) {
            $table->dropColumn([
                'cfop_outros_estados',
                'tipo_operacao',
                'recopi_numero',
                'ex_tipi',
                'fci_numero',
                'informacoes_adicionais',
                'item_agrupavel',
                'percentual_aproximado_tributos',
            ]);
        });
    }
};
