<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico do rastreio dos Correios (pedido do Lira 2026-10-19): cada
 * objeto acompanhado (rastreios) e cada evento do SRO Rastro
 * (rastreio_eventos), para a tela Logística › Rastreio e para parar de
 * consultar o que já foi entregue ou devolvido.
 *
 * LGPD: só o necessário para a linha do tempo (data, código, descrição,
 * unidade, cidade/UF). Recebedor, destinatário, remetente, carteiro e
 * coordenadas que a API devolve nunca são gravados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rastreios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('codigo', 13)->unique();
            // Situação atual (RegistroDeRastreio::SITUACOES) e o último evento.
            $table->string('situacao', 30)->nullable();
            $table->string('ultimo_evento', 255)->nullable();
            $table->timestamp('ultimo_evento_em')->nullable();
            $table->date('previsao_entrega')->nullable();
            $table->timestamp('postado_em')->nullable();
            $table->timestamp('entregue_em')->nullable();
            // Parou de consultar: entregue ao cliente ou devolvido à loja.
            $table->timestamp('encerrado_em')->nullable();
            $table->timestamp('consultado_em')->nullable();
            $table->unsignedInteger('consultas')->default(0);
            $table->string('ultimo_erro', 255)->nullable();
            $table->timestamps();
            $table->index(['encerrado_em', 'consultado_em']);
        });

        Schema::create('rastreio_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rastreio_id')->constrained('rastreios')->cascadeOnDelete();
            $table->string('codigo', 5);
            $table->string('tipo', 5)->default('');
            $table->dateTime('ocorrido_em');
            $table->string('descricao', 255);
            $table->string('detalhe', 255)->nullable();
            $table->string('unidade', 120)->nullable();
            $table->string('cidade', 80)->nullable();
            $table->string('uf', 2)->nullable();
            $table->string('destino_cidade', 80)->nullable();
            $table->string('destino_uf', 2)->nullable();
            $table->timestamps();
            // Mesmo evento consultado de novo não duplica.
            $table->unique(['rastreio_id', 'codigo', 'tipo', 'ocorrido_em'], 'rastreio_eventos_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rastreio_eventos');
        Schema::dropIfExists('rastreios');
    }
};
