<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });

        Schema::table('print_jobs', function (Blueprint $table) {
            // Etiqueta gerada manualmente (tela de admin, sem pedido real
            // associado) precisa existir sem order_id — o pipeline
            // automático continua sempre preenchendo, então order_id nulo
            // é o próprio sinal de "veio da tela manual" na listagem.
            $table->foreignId('order_id')->nullable()->change();
            $table->string('channel')->nullable()->after('order_id');
            $table->boolean('is_thank_you')->default(false)->after('channel');
        });

        Schema::table('print_jobs', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropColumn(['channel', 'is_thank_you']);
        });

        Schema::table('print_jobs', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable(false)->change();
        });

        Schema::table('print_jobs', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }
};
