<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela de frete própria por localidade (pedido do Lira em 2026-10-25): o
 * frete do site sai do banco, não da API dos Correios na hora.
 *
 * - frete_categorias_pacote (Pequena, Média, Grande e Extra Grande, das
 *   regras por cidade) ganha a caixa de referência que vai para a cotação. O
 *   Lira classifica os produtos (products.categoria_frete).
 * - frete_localidades: todas as localidades dos Correios (municípios,
 *   distritos e povoados, API de CEP /v1/localidades), com o CEP usado na
 *   cotação. Distrito sem CEP próprio usa o do município.
 * - frete_localidade_faixas: faixas de CEP de cada município, para achar a
 *   localidade do CEP do cliente.
 * - frete_tabela: PAC e SEDEX (preço do contrato e prazo) por CEP de
 *   cotação e categoria. Localidades com o mesmo CEP dividem as 4 linhas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Caixa de referência de cada categoria (a que vai para a cotação),
        // nas mesmas categorias das regras por cidade (frete_categorias_pacote).
        Schema::table('frete_categorias_pacote', function (Blueprint $table) {
            $table->unsignedInteger('ref_peso_g')->nullable()->after('lado_max_cm');
            $table->decimal('ref_comprimento_cm', 6, 1)->nullable()->after('ref_peso_g');
            $table->decimal('ref_largura_cm', 6, 1)->nullable()->after('ref_comprimento_cm');
            $table->decimal('ref_altura_cm', 6, 1)->nullable()->after('ref_largura_cm');
            $table->string('exemplo', 120)->nullable()->after('ref_altura_cm');
            $table->unsignedInteger('versao')->default(1)->after('exemplo'); // sobe a cada mudança da caixa: a cotação antiga vence
        });

        Schema::create('frete_localidades', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('numero_correios')->unique();
            $table->char('uf', 2);
            $table->string('nome', 120);
            $table->string('nome_normalizado', 120);
            $table->char('tipo', 1); // M município, D distrito, P povoado
            $table->unsignedInteger('municipio_id')->nullable(); // código IBGE do município
            $table->unsignedInteger('superior_numero')->nullable(); // localidade superior nos Correios
            $table->string('municipio_nome', 120)->nullable();
            $table->char('cep', 8)->nullable(); // CEP geral da localidade, quando tem
            $table->char('cep_cotacao', 8)->nullable();
            $table->boolean('cep_herdado')->default(false); // sem CEP próprio: usa o do município
            $table->timestamps();

            $table->index('cep');
            $table->index('cep_cotacao');
            $table->index(['uf', 'nome_normalizado']);
            $table->index('municipio_id');
        });

        Schema::create('frete_localidade_faixas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('frete_localidade_id')->constrained('frete_localidades')->cascadeOnDelete();
            $table->char('cep_inicial', 8);
            $table->char('cep_final', 8);

            $table->index(['cep_inicial', 'cep_final']);
        });

        Schema::create('frete_tabela', function (Blueprint $table) {
            $table->id();
            $table->char('cep_destino', 8);
            $table->string('categoria', 20);
            $table->unsignedInteger('categoria_versao')->default(1);
            $table->char('cep_origem', 8);
            $table->decimal('pac_preco', 8, 2)->nullable();
            $table->unsignedSmallInteger('pac_prazo')->nullable();
            $table->decimal('sedex_preco', 8, 2)->nullable();
            $table->unsignedSmallInteger('sedex_prazo')->nullable();
            $table->string('erro', 255)->nullable();
            $table->timestamp('cotado_em')->nullable();
            $table->timestamps();

            $table->unique(['cep_destino', 'categoria']);
            $table->index('cotado_em');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('categoria_frete', 20)->nullable()->after('stock');
        });

        // Caixas de referência (editáveis na tela), no limite de cada
        // categoria. Limite dos Correios para PAC/SEDEX: 30 kg, 100 cm por lado
        // e 200 cm somando os três. Extra Grande = escada telescópica de 7 m
        // fechada (cerca de 95 x 48 x 10 cm e 17 kg), que os Correios levam.
        $caixas = [
            'pequena' => [1000, 30, 20, 10, 'Até 1 kg e 30 cm'],
            'media' => [5000, 60, 30, 20, 'Até 5 kg e 60 cm'],
            'grande' => [12000, 80, 50, 40, 'Até 12 kg e 80 cm'],
            'extra_grande' => [17000, 95, 48, 10, 'Escada telescópica de 7 m (fechada)'],
        ];
        foreach ($caixas as $chave => [$peso, $c, $l, $a, $exemplo]) {
            DB::table('frete_categorias_pacote')->where('chave', $chave)->update([
                'ref_peso_g' => $peso, 'ref_comprimento_cm' => $c, 'ref_largura_cm' => $l, 'ref_altura_cm' => $a, 'exemplo' => $exemplo,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('categoria_frete');
        });
        Schema::dropIfExists('frete_tabela');
        Schema::dropIfExists('frete_localidade_faixas');
        Schema::dropIfExists('frete_localidades');
        Schema::table('frete_categorias_pacote', function (Blueprint $table) {
            $table->dropColumn(['ref_peso_g', 'ref_comprimento_cm', 'ref_largura_cm', 'ref_altura_cm', 'exemplo', 'versao']);
        });
    }
};
