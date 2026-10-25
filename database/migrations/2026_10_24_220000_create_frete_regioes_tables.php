<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frete por cidade/região (pedido do Lira em 2026-10-24,
 * Documents/frete-por-cidade-estrutura-2026-10-24.md da base do Homer).
 *
 * frete_regioes: a regra (o que fazer com a cotação dos Correios).
 * frete_regiao_locais: onde ela vale, uma linha por alvo (faixa de CEP,
 * município do IBGE ou UF). Os lugares vêm de ibge_estados/ibge_municipios.
 * Nasce vazia: sem regra, o frete continua o dos Correios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frete_regioes', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 120);
            $table->boolean('ativo')->default(true);
            // Desempate entre regras do mesmo tipo de local (maior ganha).
            $table->integer('prioridade')->default(0);
            // fixo | acrescimo | desconto | teto, sobre a cotação dos Correios.
            $table->string('tipo_preco', 20);
            $table->decimal('valor', 10, 2)->default(0);
            // acrescimo/desconto em % da cotação em vez de R$.
            $table->boolean('percentual')->default(false);
            // Troca o prazo dos Correios (dias úteis); null = mantém.
            $table->unsignedSmallInteger('prazo_dias')->nullable();
            // pac | sedex; null = os dois.
            $table->string('servico', 10)->nullable();
            // Faixa de peso tarifável (g); null = sem limite.
            $table->unsignedInteger('peso_min_g')->nullable();
            $table->unsignedInteger('peso_max_g')->nullable();
            $table->text('observacao')->nullable();
            $table->timestamps();

            $table->index('ativo');
        });

        Schema::create('frete_regiao_locais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('frete_regiao_id')->constrained('frete_regioes')->cascadeOnDelete();
            // Um alvo por linha: uf OU municipio_id OU cep_inicio/cep_fim.
            $table->char('uf', 2)->nullable();
            $table->unsignedInteger('municipio_id')->nullable();
            $table->char('cep_inicio', 8)->nullable();
            $table->char('cep_fim', 8)->nullable();
            $table->timestamps();

            $table->foreign('uf')->references('sigla')->on('ibge_estados');
            $table->foreign('municipio_id')->references('id')->on('ibge_municipios');
            $table->index('uf');
            $table->index('municipio_id');
            $table->index(['cep_inicio', 'cep_fim']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frete_regiao_locais');
        Schema::dropIfExists('frete_regioes');
    }
};
