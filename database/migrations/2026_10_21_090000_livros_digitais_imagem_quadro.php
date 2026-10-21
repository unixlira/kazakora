<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Livros Digitais (Lira, 2026-10-21): cada grupo de livros (caixas de
 * ferramentas, árvore de Natal...) tem a sua imagem de tablets no quadro da
 * página do produto. Caminho no disco `public`; vale a do primeiro livro do
 * grupo. Sem imagem, a página usa a padrão (dor nas costas).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('livros_digitais', 'imagem_quadro')) {
            return;
        }

        Schema::table('livros_digitais', function (Blueprint $table) {
            $table->string('imagem_quadro')->nullable()->after('capa');
        });
    }

    public function down(): void
    {
        Schema::table('livros_digitais', function (Blueprint $table) {
            $table->dropColumn('imagem_quadro');
        });
    }
};
