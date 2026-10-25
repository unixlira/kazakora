<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensagens de comprador da Amazon (e-mail do Buyer-Seller Messaging) e do
 * TikTok Shop (Customer Service API) nas abas de Perguntas MK Place (pedido do
 * Lira 2026-10-25). Uma tabela para os dois canais (coluna canal); a Shopee
 * continua em shopee_chat_*. Nada é enviado ao cliente sem ação do atendente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_chat_conversas')) {
            Schema::create('marketplace_chat_conversas', function (Blueprint $table) {
                $table->id();
                $table->string('canal', 20)->index();
                // Amazon: e-mail anônimo do comprador (@marketplace.amazon.com.br).
                // TikTok: conversation_id.
                $table->string('externo_id', 191);
                $table->string('comprador_nome')->nullable();
                $table->string('comprador_avatar', 500)->nullable();
                $table->string('pedido_numero', 40)->nullable()->index();
                $table->string('assunto', 500)->nullable();
                $table->unsignedInteger('nao_lidas')->default(0)->index();
                $table->text('ultima_mensagem_texto')->nullable();
                $table->boolean('ultima_do_cliente')->default(true);
                $table->timestamp('ultima_mensagem_em')->nullable()->index();
                // TikTok: última mensagem já copiada (diferente da última da lista = buscar).
                $table->string('mensagens_sync_id', 64)->nullable();
                $table->timestamp('sincronizada_em')->nullable();
                $table->timestamps();

                $table->unique(['canal', 'externo_id']);
            });
        }

        if (! Schema::hasTable('marketplace_chat_mensagens')) {
            Schema::create('marketplace_chat_mensagens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversa_id')->constrained('marketplace_chat_conversas')->cascadeOnDelete();
                $table->string('canal', 20);
                // Amazon: Message-ID do e-mail. TikTok: id da mensagem.
                $table->string('externo_id', 191);
                $table->string('tipo', 40)->default('text');
                $table->boolean('do_cliente')->default(true);
                $table->text('texto')->nullable();
                $table->json('conteudo')->nullable();
                $table->timestamp('enviada_em')->nullable();
                $table->foreignId('enviada_por_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['canal', 'externo_id']);
                $table->index(['conversa_id', 'enviada_em']);
            });
        }

        // Conexão da loja com o app próprio do TikTok Shop (OAuth). Uma linha.
        if (! Schema::hasTable('tiktok_shop_conexoes')) {
            Schema::create('tiktok_shop_conexoes', function (Blueprint $table) {
                $table->id();
                $table->text('access_token');
                $table->text('refresh_token');
                $table->timestamp('access_expira_em')->nullable();
                $table->timestamp('refresh_expira_em')->nullable();
                $table->string('shop_cipher', 191)->nullable();
                $table->string('shop_nome')->nullable();
                $table->string('vendedor_nome')->nullable();
                $table->foreignId('conectado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_shop_conexoes');
        Schema::dropIfExists('marketplace_chat_mensagens');
        Schema::dropIfExists('marketplace_chat_conversas');
    }
};
