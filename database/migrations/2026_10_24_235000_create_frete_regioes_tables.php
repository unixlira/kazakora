<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Frete por cidade (pedido do Lira em 2026-10-24, estrutura levantada no
 * mesmo dia): frete_regioes é a regra (o que acontece com o frete) e
 * frete_regiao_locais diz onde ela vale (UF, município do IBGE ou faixa de
 * CEP). O cadastro de lugares reaproveita ibge_estados e ibge_municipios.
 *
 * Primeira regra: Mauá (Grande SP, IBGE 3529401) com frete grátis no
 * KazaKora, para testar o fluxo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frete_regioes', function (Blueprint $table) {
            $table->id();
            $table->string('site', 20)->default('kazakora'); // SiteAtual: kazakora, ferrakora, beautykora
            $table->string('nome', 120);
            $table->unsignedSmallInteger('prioridade')->default(0); // maior ganha quando duas valem no mesmo nível
            $table->boolean('ativo')->default(true);
            $table->string('tipo_preco', 12); // fixo | acrescimo | desconto | teto
            $table->decimal('valor', 10, 2)->default(0);
            $table->unsignedSmallInteger('prazo_dias')->nullable();
            $table->string('servico', 12)->nullable(); // pac | sedex | motoboy (informativo por enquanto)
            $table->unsignedInteger('peso_min_g')->nullable();
            $table->unsignedInteger('peso_max_g')->nullable();
            $table->timestamps();

            $table->index(['site', 'ativo']);
        });

        Schema::create('frete_regiao_locais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('frete_regiao_id')->constrained('frete_regioes')->cascadeOnDelete();
            $table->char('uf', 2)->nullable();
            $table->unsignedInteger('municipio_id')->nullable();
            $table->char('cep_inicio', 8)->nullable();
            $table->char('cep_fim', 8)->nullable();
            $table->timestamps();

            $table->foreign('uf')->references('sigla')->on('ibge_estados');
            $table->foreign('municipio_id')->references('id')->on('ibge_municipios');
            $table->index('municipio_id');
            $table->index('uf');
            $table->index(['cep_inicio', 'cep_fim']);
        });

        if (DB::table('ibge_municipios')->where('id', 3529401)->exists()) {
            $agora = now();
            $id = DB::table('frete_regioes')->insertGetId([
                'site' => 'kazakora', 'nome' => 'Mauá - SP', 'prioridade' => 0, 'ativo' => true,
                'tipo_preco' => 'fixo', 'valor' => 0, 'created_at' => $agora, 'updated_at' => $agora,
            ]);
            DB::table('frete_regiao_locais')->insert([
                'frete_regiao_id' => $id, 'municipio_id' => 3529401, 'created_at' => $agora, 'updated_at' => $agora,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('frete_regiao_locais');
        Schema::dropIfExists('frete_regioes');
    }
};
