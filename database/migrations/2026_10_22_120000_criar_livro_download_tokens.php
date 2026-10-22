<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Download protegido dos livros de brinde (Lira, 2026-10-22): cada livro do
 * pedido tem um link com token próprio, gravado aqui. O token vale para UM
 * download (usado_em) e para poucas aberturas da página (tentativas); depois
 * disso o link para de funcionar e não dá para compartilhar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('livro_download_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('livro_digital_id')->constrained('livros_digitais')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->timestamp('usado_em')->nullable();
            $table->string('usado_ip', 45)->nullable();
            $table->timestamp('revogado_em')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'livro_digital_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livro_download_tokens');
    }
};
