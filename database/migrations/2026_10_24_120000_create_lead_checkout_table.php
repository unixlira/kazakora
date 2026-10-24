<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recuperação de carrinho abandonado (pedido do Lira 2026-10-24): o
     * contato de quem preencheu a Identificação do checkout e clicou em "Ir
     * para Entrega", com os itens do carrinho naquele momento. Antes, quem
     * não finalizava sumia. Uma linha aberta por pessoa/sessão (upsert); vira
     * "convertido" com o order_id quando o pedido sai. Dado pessoal (LGPD):
     * só no admin, nunca em log nem rota pública. Ver
     * App\Modules\Checkout\Support\LeadsDoCheckout.
     */
    public function up(): void
    {
        Schema::create('lead_checkout', function (Blueprint $table) {
            $table->id();
            // Mesmo vocabulário do App\Support\SiteAtual (kazakora, ferrakora, beautykora).
            $table->string('site', 20)->default('kazakora');
            $table->string('session_id', 100)->nullable()->index();
            // Igual ao checkout_eventos/site_visits (kazakora_visitor ou "s-" + hash da sessão).
            $table->string('visitor_id', 36)->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nome')->nullable();
            $table->string('email')->nullable()->index();
            // Só dígitos.
            $table->string('cpf', 11)->nullable()->index();
            $table->string('telefone', 13)->nullable()->index();
            // [{product_id, parent_product_id, nome, variacao, quantidade, preco_unitario, subtotal}]
            $table->json('itens')->nullable();
            $table->unsignedInteger('quantidade_itens')->default(0);
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->string('status', 12)->default('aberto')->index();
            // Última etapa alcançada: entrega (clicou "Ir para Entrega") ou pagamento.
            $table->string('etapa', 12)->default('entrega');
            $table->string('origem', 20)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 150)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('convertido_em')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['site', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_checkout');
    }
};
