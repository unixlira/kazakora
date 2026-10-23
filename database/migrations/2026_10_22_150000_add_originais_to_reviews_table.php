<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Avaliação importada da Shopee/ML entra com nome sorteado e texto sem
 * citar marketplace (pedido do Lira 2026-10-22, ver AnonimizaAvaliacao).
 * O que veio do canal fica guardado aqui, fora do site (Review::$hidden).
 *
 * Na produção os apelidos trocados à mão em 22/10 estão na tabela avulsa
 * reviews_apelidos_originais: copiados para nome_original, assim o
 * reviews:coletar não sorteia de novo um nome que já está no ar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('reviews', 'nome_original')) {
                $table->string('nome_original')->nullable()->after('reviewer_name');
            }

            if (! Schema::hasColumn('reviews', 'comentario_original')) {
                $table->text('comentario_original')->nullable()->after('comment');
            }
        });

        if (Schema::hasTable('reviews_apelidos_originais')) {
            DB::statement("UPDATE reviews r JOIN reviews_apelidos_originais a ON a.review_id = r.id
                SET r.nome_original = COALESCE(a.apelido, '')
                WHERE r.nome_original IS NULL");
        }
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['nome_original', 'comentario_original']);
        });
    }
};
