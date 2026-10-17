<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de cada cupom do vendedor enviado ao Mercado Livre pela tela
 * Integrações › Mercado Livre › Criar cupom: quem enviou, o que foi
 * enviado e o que o ML respondeu (pedido do Lira em 16/10/2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mercado_livre_cupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nome');
            $table->string('ml_promotion_id')->nullable()->index();
            $table->string('status', 20);
            $table->json('payload');
            $table->json('resposta')->nullable();
            $table->unsignedInteger('itens_adicionados')->default(0);
            $table->unsignedInteger('itens_com_erro')->default(0);
            $table->text('erro')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mercado_livre_cupons');
    }
};
