<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correios ou Melhor Envio (pedido do Lira em 2026-11-03, App\Support\FreteProvedor):
 * a tabela de frete por localidade guarda a cotação dos dois, uma linha por
 * provedor. Trocar o provedor em Logística passa a ler as linhas do outro na
 * hora, sem apagar nada. As linhas que já existem são dos Correios.
 *
 * No Melhor Envio "pac" é o serviço mais barato e "sedex" o mais rápido, de
 * qualquer transportadora da conta; o nome vai em pac_servico/sedex_servico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('frete_tabela', function (Blueprint $table) {
            $table->string('provedor', 20)->default('correios')->after('cep_origem');
            $table->string('pac_servico', 80)->nullable()->after('pac_prazo');
            $table->string('sedex_servico', 80)->nullable()->after('sedex_prazo');
        });

        Schema::table('frete_tabela', function (Blueprint $table) {
            $table->dropUnique('frete_tabela_cep_destino_categoria_unique');
            $table->unique(['cep_destino', 'categoria', 'provedor'], 'frete_tabela_cep_categoria_provedor_unique');
        });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('frete_tabela')->where('provedor', '!=', 'correios')->delete();

        Schema::table('frete_tabela', function (Blueprint $table) {
            $table->dropUnique('frete_tabela_cep_categoria_provedor_unique');
            $table->unique(['cep_destino', 'categoria']);
        });

        Schema::table('frete_tabela', function (Blueprint $table) {
            $table->dropColumn(['provedor', 'pac_servico', 'sedex_servico']);
        });
    }
};
