<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // E-mail e WhatsApp do cliente, pra tela de auditoria de
            // pedidos. No Mercado Livre o e-mail vem mascarado (relay
            // @mail.mercadolivre.com, não o e-mail real do comprador) e o
            // WhatsApp vem do campo alternative_phone quando o comprador
            // preenche — nem sempre disponível, por isso nullable.
            $table->string('shipping_email')->nullable()->after('shipping_phone');
            $table->string('shipping_whatsapp')->nullable()->after('shipping_email');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_email', 'shipping_whatsapp']);
        });
    }
};
