<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat da Shopee (sellerchat): conversas e mensagens espelhadas na tela
 * Atendimento > Shopee. Quem manda no estado é a Shopee; o sync a cada 2 min
 * só copia para cá. Nada é enviado ao cliente sem ação do atendente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shopee_chat_conversas')) {
            Schema::create('shopee_chat_conversas', function (Blueprint $table) {
                $table->id();
                $table->string('conversation_id', 40)->unique();
                $table->unsignedBigInteger('comprador_id')->index();
                $table->string('comprador_nome')->nullable();
                $table->string('comprador_avatar', 500)->nullable();
                $table->unsignedInteger('nao_lidas')->default(0)->index();
                $table->string('ultima_mensagem_id', 40)->nullable();
                $table->string('ultima_mensagem_tipo', 40)->nullable();
                $table->text('ultima_mensagem_texto')->nullable();
                $table->boolean('ultima_do_cliente')->default(true);
                $table->timestamp('ultima_mensagem_em')->nullable()->index();
                // Última mensagem cujo conteúdo já foi copiado; diferente de
                // ultima_mensagem_id = ainda há mensagens para buscar.
                $table->string('mensagens_sync_id', 40)->nullable();
                $table->timestamp('sincronizada_em')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shopee_chat_mensagens')) {
            Schema::create('shopee_chat_mensagens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversa_id')->constrained('shopee_chat_conversas')->cascadeOnDelete();
                $table->string('message_id', 40)->unique();
                $table->string('tipo', 40)->index();
                $table->boolean('do_cliente')->default(true);
                $table->text('texto')->nullable();
                $table->json('conteudo')->nullable();
                $table->string('origem', 30)->nullable();
                $table->timestamp('enviada_em')->nullable();
                $table->foreignId('enviada_por_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['conversa_id', 'enviada_em']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_chat_mensagens');
        Schema::dropIfExists('shopee_chat_conversas');
    }
};
