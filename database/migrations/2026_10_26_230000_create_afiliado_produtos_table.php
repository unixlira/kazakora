<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Afiliados (pedido do Lira em 2026-10-26): produtos escolhidos para virar
 * anúncio de afiliado num canal (por enquanto só a Shopee). Uma linha = o
 * produto foi escolhido para ser clonado; o clone em si ainda não é feito.
 *
 * `custo` e `preco` são os números do momento da escolha (preço = custo ×
 * 1,5, para pagar 10% ao afiliado). `sku_afiliado` = SKU do original + "-AF".
 * `external_id` fica para o anúncio clonado, quando existir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('afiliado_produtos', function (Blueprint $table) {
            $table->id();
            $table->string('canal', 30);
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku_afiliado')->nullable();
            $table->decimal('custo', 12, 2);
            $table->decimal('preco', 12, 2);
            $table->string('external_id')->nullable();
            $table->foreignId('escolhido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['canal', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('afiliado_produtos');
    }
};
