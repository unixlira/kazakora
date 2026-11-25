<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Newsletter da loja (pedido do Lira 24/11/2026). Inscritos com duplo opt-in
 * e prova do consentimento (LGPD art. 8º § 2º), e o registro de cada envio
 * do resumo do blog, para nunca mandar o mesmo resumo duas vezes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_inscritos', function (Blueprint $t) {
            $t->id();
            $t->string('email', 160)->unique();
            $t->string('nome', 120)->nullable();
            $t->string('status', 20)->default('pendente'); // pendente, ativo, descadastrado
            $t->string('token', 64)->unique();
            $t->string('origem', 255)->nullable();
            $t->string('consentimento_versao', 20)->nullable();
            $t->text('consentimento_texto')->nullable();
            $t->string('consentimento_ip', 45)->nullable();
            $t->string('consentimento_navegador', 255)->nullable();
            $t->timestamp('consentimento_em')->nullable();
            $t->timestamp('confirmacao_enviada_em')->nullable();
            $t->timestamp('confirmado_em')->nullable();
            $t->timestamp('descadastrado_em')->nullable();
            $t->timestamp('ultimo_envio_em')->nullable();
            $t->timestamps();
            $t->index(['status', 'id']);
        });

        Schema::create('newsletter_envios', function (Blueprint $t) {
            $t->id();
            $t->string('referencia', 80)->unique();
            $t->string('assunto');
            $t->json('post_ids');
            $t->unsignedInteger('destinatarios')->default(0);
            $t->unsignedInteger('lotes')->default(0);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_envios');
        Schema::dropIfExists('newsletter_inscritos');
    }
};
