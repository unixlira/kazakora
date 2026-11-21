<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sequência de carrinho abandonado (pedido do Lira 21/11/2026): um registro
 * por pessoa e loja, com o calendário D+1, D+3, D+5, D+7, D+20 e D+30 por
 * WhatsApp e e-mail, sem desconto. Ver App\Modules\Cart\Support\Sequencia e
 * docs/carrinho-abandonado.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('adm_abandoned_carts')) {
            return;
        }

        Schema::create('adm_abandoned_carts', function (Blueprint $table) {
            $table->id();
            // kazakora | ferrakora
            $table->string('site', 20)->index();
            // lead_checkout | cart_snapshot
            $table->string('origem', 20);
            $table->unsignedBigInteger('origem_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nome')->nullable();
            $table->string('email')->nullable()->index();
            // E.164 só dígitos (55 + DDD + número).
            $table->string('telefone', 20)->nullable()->index();
            $table->json('itens')->nullable();
            // Item principal: o de maior valor no carrinho.
            $table->unsignedBigInteger('produto_id')->nullable();
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->timestamp('abandonado_em')->nullable();
            $table->timestamp('inicio_sequencia_em')->nullable();
            $table->unsignedTinyInteger('disparos_enviados')->default(0);
            $table->timestamp('ultimo_disparo_em')->nullable();
            $table->timestamp('proximo_disparo_em')->nullable()->index();
            $table->boolean('parado')->default(false)->index();
            // comprou | pediu_parar | sem_contato | sem_promocoes | fim
            $table->string('motivo_parada', 20)->nullable();
            $table->timestamp('convertido_em')->nullable();
            // Cada disparo: número, canal, status, erro, texto, link, quando.
            $table->json('historico')->nullable();
            $table->timestamps();

            $table->unique(['origem', 'origem_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adm_abandoned_carts');
    }
};
