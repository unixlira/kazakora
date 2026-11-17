<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelamento depois da postagem (Lira, 2026-11-17): com o pacote já
 * postado, o botão "Cancelar pedido" de Meus pedidos não cancela mais; ele
 * registra aqui uma solicitação (ligada a um chamado do suporte) para a
 * equipe tratar em Atendimento > Cancelamentos. Uma linha por pedido
 * (order_id único): clique repetido não cria outra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancelamento_solicitacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chamado_id')->nullable()->constrained('suporte_chamados')->nullOnDelete();
            $table->text('motivo');
            // De onde veio a postagem (Melhor Envio, Correios, canal, status do pedido).
            $table->string('postagem_fonte', 40)->nullable();
            $table->timestamp('postado_em')->nullable();
            $table->string('rastreio', 60)->nullable();
            // pendente | resolvido
            $table->string('status', 20)->default('pendente')->index();
            $table->timestamp('solicitado_em')->nullable();
            $table->timestamp('resolvido_em')->nullable();
            $table->foreignId('resolvido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancelamento_solicitacoes');
    }
};
