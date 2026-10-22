<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Termos de uso do livro digital (Lira, 2026-10-22): o cliente concorda antes
 * de baixar o PDF. Guarda o primeiro aceite de cada livro do pedido (quando e
 * o IP) para provar o aceite se o arquivo for compartilhado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedido_livros_digitais', function (Blueprint $table) {
            $table->timestamp('termos_aceitos_em')->nullable()->after('ultimo_download_em');
            $table->string('termos_aceitos_ip', 45)->nullable()->after('termos_aceitos_em');
        });
    }

    public function down(): void
    {
        Schema::table('pedido_livros_digitais', function (Blueprint $table) {
            $table->dropColumn(['termos_aceitos_em', 'termos_aceitos_ip']);
        });
    }
};
