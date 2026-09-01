<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito 2026-09-01: "mude tbm o nome do mercado livre, colocar
 * destinatario e o nome do usuario entre parenteses".
 *
 * shipping_name continua sendo o nome do COMPRADOR (é ele que casa com o
 * CPF de buyer_document na NF-e — ver NFeXmlBuilderService; trocar aquilo
 * pelo destinatário arriscaria rejeição da SEFAZ por nome x CPF
 * divergentes). Os dois campos novos existem só pra EXIBIÇÃO: o
 * destinatário de verdade da etiqueta (receiver_address.receiver_name do
 * shipment) e o apelido/usuário do comprador no canal (buyer.nickname) —
 * ex.: "Genivaldo Jose Filho (GENIVALDOJOSEFILHOFILHO)" no KoraSync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_recipient_name')->nullable()->after('shipping_name');
            $table->string('channel_buyer_nickname')->nullable()->after('shipping_recipient_name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_recipient_name', 'channel_buyer_nickname']);
        });
    }
};
