<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suporte a avaliações importadas dos marketplaces (pedido explícito
 * 2026-08-16) — um job de cron busca nota/comentário/nome do comprador em
 * cada canal conectado (ver ReviewImportService) e grava aqui junto com as
 * avaliações reais do site, sem diferenciar visualmente pro público (o
 * campo `channel` é só pra controle interno do admin — Review::$hidden).
 *
 * `user_id` nullable: avaliação importada não tem conta Kazakora por trás,
 * só existe do lado do canal (mesmo raciocínio já aplicado em
 * orders.user_id, ver 2026_08_01_030000_make_order_user_id_nullable.php).
 * `reviewer_name` guarda o nome/apelido que o canal devolveu, usado como
 * fallback de exibição quando não há `user` relacionado.
 * `channel`+`external_id` identificam a avaliação de origem (canal +
 * comment_id) — únicos juntos, pra reimportar a mesma avaliação em toda
 * execução do cron sem duplicar (updateOrCreate por esse par).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dropa o FK e o unique(user_id,product_id) ANTES do ->change() —
        // user_id é o 1º campo de um índice composto, e alterar sua
        // nulabilidade com o índice ainda em pé arrisca o DBAL recriar o
        // índice errado no meio do caminho (mesmo cuidado extra que o FK já
        // recebia no precedente de orders.user_id, aqui estendido pro
        // índice composto que aquele caso não tinha).
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id', 'product_id']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'product_id']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->string('reviewer_name')->nullable()->after('user_id');
            $table->string('channel')->nullable()->after('product_id');
            $table->string('external_id')->nullable()->after('channel');
            $table->unique(['channel', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['channel', 'external_id']);
            $table->dropColumn(['reviewer_name', 'channel', 'external_id']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id', 'product_id']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'product_id']);
        });
    }
};
