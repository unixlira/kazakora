<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_fiscal_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();

            // Classificação fiscal
            $table->string('ncm', 8)->nullable();
            $table->string('cest', 7)->nullable();
            $table->string('cfop', 4)->nullable();
            $table->unsignedTinyInteger('origem')->default(0);
            $table->string('gtin', 14)->nullable();
            $table->string('unidade_tributavel', 6)->default('UN');

            // ICMS
            $table->string('icms_situacao_tributaria', 3)->nullable();
            $table->decimal('icms_aliquota', 5, 2)->nullable();

            // IPI
            $table->string('ipi_situacao_tributaria', 3)->nullable();
            $table->decimal('ipi_aliquota', 5, 2)->nullable();

            // PIS / COFINS
            $table->string('pis_situacao_tributaria', 3)->nullable();
            $table->decimal('pis_aliquota', 5, 2)->nullable();
            $table->string('cofins_situacao_tributaria', 3)->nullable();
            $table->decimal('cofins_aliquota', 5, 2)->nullable();

            // Logística (nota fiscal e frete de marketplace)
            $table->decimal('peso_bruto', 8, 3)->nullable();
            $table->decimal('peso_liquido', 8, 3)->nullable();
            $table->decimal('altura_cm', 8, 2)->nullable();
            $table->decimal('largura_cm', 8, 2)->nullable();
            $table->decimal('profundidade_cm', 8, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_fiscal_data');
    }
};
