<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Texto do quadro editável em Vender › Promoções (pedido do Lira
 * 2026-10-21, junto com a saída de Entregáveis do menu): título e texto do
 * quadro do sorteio (Airfryer) e do quadro dos livros (PDFs), e a linha de
 * destaque dos livros ("Adquirindo hoje: 100% GRÁTIS"). Null = o texto de
 * sempre (o do sorteio cadastrado; o fixo dos livros).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promocoes', function (Blueprint $table) {
            $table->string('quadro_titulo')->nullable()->after('condicoes');
            $table->text('quadro_texto')->nullable()->after('quadro_titulo');
            $table->string('quadro_destaque', 120)->nullable()->after('quadro_texto');
        });
    }

    public function down(): void
    {
        Schema::table('promocoes', function (Blueprint $table) {
            $table->dropColumn(['quadro_titulo', 'quadro_texto', 'quadro_destaque']);
        });
    }
};
