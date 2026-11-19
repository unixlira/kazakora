<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medição do funil dos infoprodutos (2026-11-19).
 *
 * - infoproduto_eventos: eventos anônimos das páginas (sem IP, sem cookie;
 *   sessão = código aleatório que só vive na aba). Apagados aos 90 dias.
 * - infoproduto_diarios: totais por dia, produto, campanha, anúncio e país,
 *   que ficam depois que os brutos são apagados.
 * - infoproduto_vendas: vendas da Kiwify pelo id da venda, sem nome, e-mail
 *   ou telefone do comprador.
 * - infoproduto_kiwify_avisos: um registro por (venda, evento) para não
 *   gravar o mesmo aviso duas vezes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infoproduto_eventos', function (Blueprint $table) {
            $table->id();
            $table->string('sessao', 32);
            // Infoproduto (slug) ou página de produto das lojas: kz/<slug>, fk/<slug>.
            $table->string('produto', 130);
            $table->string('pagina', 10);
            $table->string('idioma', 2)->nullable();
            $table->string('tipo', 10);
            $table->string('valor', 40)->nullable();
            $table->unsignedInteger('ms')->nullable();
            // Clique (mapa de cliques): x em % da largura, y em px do topo do
            // documento, largura da janela, link/botão/morto, seção e texto curto.
            $table->unsignedTinyInteger('x')->nullable();
            $table->unsignedMediumInteger('y')->nullable();
            $table->unsignedSmallInteger('largura')->nullable();
            $table->char('alvo', 1)->nullable();
            $table->string('secao', 40)->nullable();
            $table->string('elemento', 40)->nullable();
            // Nome estável do elemento (data-kzm), ex.: comprar, galeria:3.
            $table->string('nome', 40)->nullable();
            // Saída: % máximo da página que a pessoa viu.
            $table->unsignedTinyInteger('rolagem_max')->nullable();
            // Só no pageview: de onde veio.
            $table->char('pais', 2)->nullable();
            $table->string('dispositivo', 10)->nullable();
            $table->string('referrer', 100)->nullable();
            $table->string('utm_source', 150)->nullable();
            $table->string('utm_medium', 150)->nullable();
            $table->string('utm_campaign', 150)->nullable();
            $table->string('utm_content', 150)->nullable();
            $table->string('utm_term', 150)->nullable();
            $table->boolean('fbclid')->default(false);
            $table->boolean('teste')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['produto', 'created_at']);
            $table->index(['sessao', 'tipo']);
            $table->index(['tipo', 'produto', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('infoproduto_diarios', function (Blueprint $table) {
            $table->id();
            $table->date('data');
            $table->string('produto', 130);
            $table->string('utm_source', 150)->default('');
            $table->string('utm_campaign', 150)->default('');
            $table->string('utm_content', 150)->default('');
            $table->string('pais', 2)->default('');
            $table->unsignedInteger('visitas')->default(0);
            $table->unsignedInteger('rolou_50')->default(0);
            $table->unsignedInteger('play')->default(0);
            $table->unsignedInteger('video_fim')->default(0);
            $table->unsignedInteger('previa')->default(0);
            $table->unsignedInteger('clique_compra')->default(0);
            $table->unsignedBigInteger('segundos')->default(0);
            $table->timestamps();

            $table->unique(['data', 'produto', 'utm_source', 'utm_campaign', 'utm_content', 'pais'], 'infoproduto_diarios_chave');
        });

        Schema::create('infoproduto_vendas', function (Blueprint $table) {
            $table->id();
            $table->string('kiwify_order_id', 64)->unique();
            $table->string('kiwify_produto_id', 64)->nullable();
            $table->string('kiwify_produto_nome', 200)->nullable();
            $table->string('produto', 40)->nullable();
            $table->string('tipo', 12)->default('principal');
            // aprovada, pendente (pix/boleto gerado), recusada, reembolsada, chargeback
            $table->string('status', 12);
            $table->string('metodo', 20)->nullable();
            $table->decimal('valor', 12, 2)->nullable();
            $table->char('moeda', 3)->nullable();
            $table->decimal('valor_liquido', 12, 2)->nullable();
            $table->char('moeda_liquido', 3)->nullable();
            $table->char('pais', 2)->nullable();
            $table->unsignedSmallInteger('order_bumps')->default(0);
            $table->string('utm_source', 150)->nullable();
            $table->string('utm_medium', 150)->nullable();
            $table->string('utm_campaign', 150)->nullable();
            $table->string('utm_content', 150)->nullable();
            $table->string('utm_term', 150)->nullable();
            $table->string('src', 150)->nullable();
            $table->string('sck', 150)->nullable();
            $table->timestamp('criada_em')->nullable();
            $table->timestamp('aprovada_em')->nullable();
            $table->timestamp('reembolsada_em')->nullable();
            $table->timestamps();

            $table->index(['produto', 'aprovada_em']);
            $table->index('criada_em');
        });

        Schema::create('infoproduto_kiwify_avisos', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 64);
            $table->string('evento', 40);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['chave', 'evento']);
        });

        Schema::create('infoproduto_carrinhos', function (Blueprint $table) {
            $table->id();
            $table->string('kiwify_id', 64)->unique();
            $table->string('kiwify_produto_id', 64)->nullable();
            $table->string('produto', 40)->nullable();
            $table->char('pais', 2)->nullable();
            $table->timestamp('criado_em')->nullable();
            $table->timestamps();

            $table->index(['produto', 'criado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infoproduto_carrinhos');
        Schema::dropIfExists('infoproduto_kiwify_avisos');
        Schema::dropIfExists('infoproduto_vendas');
        Schema::dropIfExists('infoproduto_diarios');
        Schema::dropIfExists('infoproduto_eventos');
    }
};
