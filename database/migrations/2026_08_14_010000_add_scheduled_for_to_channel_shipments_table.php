<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito 2026-08-14: achado ao vivo (pedido #278) que uma venda
 * do Mercado Livre com `logistic_type=xd_drop_off` (Coleta/Places) pode vir
 * com `shipping_option.buffering.date` no futuro — o Mercado Livre já
 * decidiu de propósito só liberar a etiqueta perto dessa data (o pedido
 * era agendado pra sair no dia 17, não um problema/travamento de verdade).
 * Sem guardar essa data em algum lugar, CheckShipmentLabelJob ficava
 * martelando a API por até 4h, sempre "não pronta", até desistir e avisar
 * os admins como se fosse um erro real — e ninguém no time tinha como
 * saber, só olhando o pedido, que aquilo era esperado.
 *
 * Null = envio normal, sem agendamento conhecido (a grande maioria).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_shipments', function (Blueprint $table) {
            $table->timestamp('scheduled_for')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('channel_shipments', function (Blueprint $table) {
            $table->dropColumn('scheduled_for');
        });
    }
};
