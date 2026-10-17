<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista da Oferta Relâmpago da Shopee (pedido 2026-10-17): o que o
 * shopee:relampago-agendar põe, às 22h, na Relâmpago do dia seguinte.
 * dia vazio = entra todo dia; com dia = só nesse dia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_relampago_itens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopee_item_id');
            $table->unsignedBigInteger('shopee_model_id')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nome')->nullable();
            $table->decimal('preco', 10, 2)->nullable();
            $table->decimal('desconto_pct', 5, 2)->nullable();
            $table->unsignedInteger('unidades');
            $table->unsignedSmallInteger('limite_comprador')->default(0); // 0 = sem limite
            $table->date('dia')->nullable();
            $table->boolean('ativo')->default(true);
            $table->date('ultimo_dia')->nullable();
            $table->string('ultimo_resultado', 20)->nullable();
            $table->text('ultima_mensagem')->nullable();
            $table->unsignedBigInteger('ultimo_flash_sale_id')->nullable();
            $table->timestamps();
            $table->index(['ativo', 'dia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_relampago_itens');
    }
};
