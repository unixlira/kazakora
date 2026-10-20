<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos da Amazon (Notifications API, ORDER_CHANGE) lidos da fila SQS por
 * amazon:notificacoes-ouvir. notification_id único: a SQS Standard pode
 * entregar a mesma mensagem mais de uma vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amazon_notificacoes', function (Blueprint $table) {
            $table->id();
            $table->string('notification_id')->unique();
            $table->string('notification_type', 60);
            $table->string('amazon_order_id', 40)->nullable()->index();
            $table->string('order_change_type', 40)->nullable();
            $table->string('order_status', 40)->nullable();
            $table->timestamp('event_time')->nullable();
            $table->json('payload');
            $table->string('status', 20)->default('recebida')->index();
            $table->text('resultado')->nullable();
            $table->timestamp('processada_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_notificacoes');
    }
};
