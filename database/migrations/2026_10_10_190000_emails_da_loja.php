<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido 2026-10-10:
 * - users.deve_trocar_senha: conta criada no checkout com senha temporária;
 *   a loja pede, num modal, para trocar por uma senha pessoal.
 * - cart_snapshots: e-mail, itens e controle dos lembretes de carrinho
 *   abandonado (1º aos 50 min, depois 1 por dia por 7 dias).
 * - mensagens_contato: o que chega pelo formulário "Fale conosco" (fica
 *   guardado mesmo se o e-mail falhar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('deve_trocar_senha')->default(false);
        });

        Schema::table('cart_snapshots', function (Blueprint $table) {
            $table->string('email')->nullable()->index();
            $table->json('itens')->nullable();
            $table->timestamp('ultima_atividade_em')->nullable();
            $table->unsignedTinyInteger('lembretes_enviados')->default(0);
            $table->timestamp('ultimo_lembrete_em')->nullable();
            $table->boolean('lembretes_parados')->default(false);
        });

        Schema::create('mensagens_contato', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 120);
            $table->string('email', 160);
            $table->string('telefone', 30)->nullable();
            $table->string('assunto', 120);
            $table->text('mensagem');
            $table->string('ip', 45)->nullable();
            $table->timestamp('enviado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensagens_contato');

        Schema::table('cart_snapshots', function (Blueprint $table) {
            $table->dropIndex(['email']);
            $table->dropColumn(['email', 'itens', 'ultima_atividade_em', 'lembretes_enviados', 'ultimo_lembrete_em', 'lembretes_parados']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deve_trocar_senha');
        });
    }
};
