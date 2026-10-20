<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Página própria de avaliação (pedido do Lira 2026-10-18): o e-mail pós-compra
 * leva a /avaliar-pedido/{token}, sem login, e o cupom só nasce depois da nota.
 * token = link do e-mail; nota = estrelas dadas (média quando o pedido tem mais
 * de um produto); desconto_percentual = quanto o cupom deu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacao_solicitacoes', function (Blueprint $table) {
            $table->string('token', 64)->nullable()->unique()->after('email');
            $table->unsignedTinyInteger('nota')->nullable()->after('avaliado_em');
            $table->decimal('desconto_percentual', 5, 2)->nullable()->after('nota');
        });

        DB::table('avaliacao_solicitacoes')->whereNull('token')->pluck('id')->each(
            fn ($id) => DB::table('avaliacao_solicitacoes')->where('id', $id)->update(['token' => Str::random(48)])
        );
    }

    public function down(): void
    {
        Schema::table('avaliacao_solicitacoes', function (Blueprint $table) {
            $table->dropUnique(['token']);
            $table->dropColumn(['token', 'nota', 'desconto_percentual']);
        });
    }
};
