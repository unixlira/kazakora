<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_claims', function (Blueprint $table) {
            $table->id();
            // Nullable: o claim pode chegar antes de resolvermos o pedido
            // local (resource_id do Mercado Livre não bateu com nenhum
            // external_order_id ainda, ex.: corrida rara com o import do
            // pedido) — guardado do mesmo jeito pra não perder o dado.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel');
            $table->string('external_claim_id');
            // type/stage/status/reason_id: vocabulário é do próprio Mercado
            // Livre (ex.: type=mediations, stage=claim|dispute|recontact,
            // status=opened|closed) — guardado como veio, sem tentar
            // traduzir num enum próprio agora; só o front mapeia pra label
            // amigável quando reconhece o valor.
            $table->string('type')->nullable();
            $table->string('stage')->nullable();
            $table->string('status')->nullable();
            $table->string('reason_id')->nullable();
            $table->json('resolution')->nullable();
            // Resposta bruta do detalhe do claim — território novo (webhook
            // post_purchase nunca tinha sido processado antes), guardar o
            // payload inteiro ajuda a debugar sem precisar reproduzir a
            // chamada à API depois.
            $table->json('raw_payload')->nullable();
            $table->timestamp('claim_created_at')->nullable();
            $table->timestamp('claim_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_claim_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_claims');
    }
};
