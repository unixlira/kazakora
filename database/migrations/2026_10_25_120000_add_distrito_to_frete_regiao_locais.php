<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frete por distrito (pedido do Lira em 2026-10-25): dentro de um município,
 * cada distrito pode ter a própria regra. Ex.: Afonso Cláudio (ES) tem o CEP
 * geral 29600-000 e os distritos têm outros (Serra Pelada 29603-000, Pontões
 * 29604-000, Piracema 29607-000).
 *
 * O local do distrito tem o município, o nome do distrito (o que o ViaCEP
 * devolve em "bairro" para o CEP dele), o código do IBGE (9 dígitos, quando
 * veio da lista do IBGE) e o CEP ou a faixa de CEP. Local sem distrito
 * continua valendo para o município inteiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('frete_regiao_locais', function (Blueprint $table) {
            $table->unsignedInteger('distrito_id')->nullable()->after('municipio_id');
            $table->string('distrito', 120)->nullable()->after('distrito_id');
        });
    }

    public function down(): void
    {
        Schema::table('frete_regiao_locais', function (Blueprint $table) {
            $table->dropColumn(['distrito_id', 'distrito']);
        });
    }
};
