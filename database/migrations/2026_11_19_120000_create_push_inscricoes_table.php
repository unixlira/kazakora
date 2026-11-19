<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apps de vendas KazaKora e FerraKora (2026-11-19): aparelhos inscritos no
 * Web Push e a trava de "um aviso por pedido".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_inscricoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('app', 20);
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh', 120);
            $table->string('auth', 60);
            $table->string('user_agent', 300)->nullable();
            // Última vez que o app abriu e confirmou a inscrição.
            $table->timestamp('ultima_vez_em')->nullable();
            $table->timestamp('ultimo_envio_em')->nullable();
            $table->unsignedSmallInteger('falhas')->default(0);
            $table->timestamps();

            $table->index(['app', 'user_id']);
        });

        // Quem grava a linha primeiro manda o aviso; reprocessar o webhook
        // ou finalizar o pedido duas vezes não avisa de novo.
        Schema::create('push_vendas_avisadas', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->primary();
            $table->string('app', 20);
            $table->unsignedSmallInteger('enviados')->default(0);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_vendas_avisadas');
        Schema::dropIfExists('push_inscricoes');
    }
};
