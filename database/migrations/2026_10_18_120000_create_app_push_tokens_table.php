<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App nativo da KazaKora (2026-10-18).
 *
 * app_push_tokens: token de notificação (Expo) de cada aparelho do cliente.
 * Só guarda; nenhum disparo nesta versão.
 *
 * orders.app_plataforma: pedido feito pelo app (ios, android ou web). Só
 * marcação: o pedido continua origin "loja" com site_origem nulo, então
 * margem, KoraSync, painel e o e-mail das 23h50 contam como venda do
 * kazakora.com, sem mudar nenhuma conta. Nulo = site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 255)->unique();
            $table->string('plataforma', 10);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('app_plataforma', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('app_plataforma');
        });

        Schema::dropIfExists('app_push_tokens');
    }
};
