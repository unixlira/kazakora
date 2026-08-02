<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_channel_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->decimal('gross_amount', 10, 2);
            $table->decimal('fee_amount', 10, 2);
            // net_amount não é guardado — é sempre gross_amount - fee_amount,
            // guardar duas fontes de verdade pra um valor derivado é como bugs
            // de "dado divergente" acontecem.
            // "api": veio de um valor real (ex.: sale_fee do Mercado Livre no
            // pedido real). Nenhum outro source existe hoje — se um canal sem
            // integração de taxa tentar usar isso, é bug, não um valor "estimado".
            $table->string('source')->default('api');
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['order_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_channel_fees');
    }
};
