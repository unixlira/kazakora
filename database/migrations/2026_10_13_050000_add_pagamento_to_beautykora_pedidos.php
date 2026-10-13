<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beauty Kora, pagamento confirmado (pedido do Lira 2026-10-12): o pedido
 * nasce aqui antes do checkout da Hostinger e a Hostinger não avisa quando o
 * pagamento entra. O conciliador (beautykora:conciliar-pagamentos) acha o
 * pedido pago lá pelo e-mail do checkout, pelas variantes e pela hora, e
 * marca o lote inteiro como pago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beautykora_pedidos', function (Blueprint $table) {
            // Mesmo valor em todos os itens de um mesmo checkout.
            $table->uuid('lote')->nullable()->after('endereco');
            $table->string('variante_id', 64)->nullable()->after('produto_id');
            $table->string('email_checkout', 191)->nullable()->after('lote');
            $table->string('hostinger_pedido_id', 64)->nullable()->after('email_checkout');
            $table->unsignedInteger('hostinger_numero')->nullable()->after('hostinger_pedido_id');
            $table->timestamp('pago_em')->nullable()->after('hostinger_numero');

            $table->index('lote');
            $table->index('hostinger_pedido_id');
        });
    }

    public function down(): void
    {
        Schema::table('beautykora_pedidos', function (Blueprint $table) {
            $table->dropIndex(['lote']);
            $table->dropIndex(['hostinger_pedido_id']);
            $table->dropColumn(['lote', 'variante_id', 'email_checkout', 'hostinger_pedido_id', 'hostinger_numero', 'pago_em']);
        });
    }
};
