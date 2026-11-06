<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leads (pedido do Lira 2026-11-06): o que o cliente digita no checkout,
     * campo a campo, salvo por ajax assim que cada campo é preenchido (nome,
     * e-mail, CPF, telefone e CEP), com os produtos do carrinho. Diferente do
     * lead_checkout (Carrinhos abandonados), que só grava nos cliques "Ir para
     * Entrega" e "Ir para Pagamento". Uma linha aberta por sessão; vira
     * "convertido" com o order_id quando o pedido sai. Dado pessoal (LGPD):
     * só no admin, nunca em log; mesmo prazo de guarda do leads:limpar.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('site', 20)->default('kazakora');
            $table->string('session_id', 100)->nullable()->index();
            $table->string('visitor_id', 36)->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            // Como o cliente digitou (e-mail pode estar pela metade); CPF, telefone e CEP só dígitos.
            $table->string('nome')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('cpf', 11)->nullable()->index();
            $table->string('telefone', 13)->nullable()->index();
            $table->string('cep', 8)->nullable();
            // Último campo que chegou (nome, email, cpf, telefone ou cep).
            $table->string('ultimo_campo', 12)->nullable();
            // [{product_id, parent_product_id, nome, variacao, quantidade, preco_unitario, subtotal}]
            $table->json('itens')->nullable();
            $table->unsignedInteger('quantidade_itens')->default(0);
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->string('status', 12)->default('aberto')->index();
            $table->string('origem', 20)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 150)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('convertido_em')->nullable();
            $table->timestamps();

            $table->index('updated_at');
            $table->index(['site', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
