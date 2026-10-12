<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beauty Kora (pedido do Lira 2026-10-12): login e pedidos do site Beauty
 * Kora (beautykora.devlira.com.br) passam a viver no KazaKora, pela API
 * /api/beautykora/*. Tabelas próprias de propósito: cliente do Beauty Kora
 * não vira usuário da loja e pedido dele não entra em orders, então não
 * mexe no estoque, nas notas nem no ContributionMargin da KazaKora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beautykora_clientes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 191)->unique();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('beautykora_enderecos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('beautykora_clientes')->cascadeOnDelete();
            $table->string('cep', 9);
            $table->string('logradouro', 191);
            $table->string('numero', 20);
            $table->string('complemento', 120)->nullable();
            $table->string('bairro', 120);
            $table->string('cidade', 120);
            $table->string('estado', 2);
            $table->timestamps();
        });

        Schema::create('beautykora_pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('beautykora_clientes')->cascadeOnDelete();
            $table->foreignId('endereco_id')->nullable()->constrained('beautykora_enderecos')->nullOnDelete();
            $table->string('produto_id', 64);
            $table->string('produto_nome', 191);
            $table->string('produto_imagem', 500)->nullable();
            $table->unsignedInteger('quantidade')->default(1);
            $table->unsignedBigInteger('valor_pago_centavos');
            $table->string('status', 20)->default('pending');
            // Cópia do endereço no momento da compra (o cliente pode mudar depois).
            $table->json('endereco')->nullable();
            $table->timestamps();

            $table->index(['cliente_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beautykora_pedidos');
        Schema::dropIfExists('beautykora_enderecos');
        Schema::dropIfExists('beautykora_clientes');
    }
};
