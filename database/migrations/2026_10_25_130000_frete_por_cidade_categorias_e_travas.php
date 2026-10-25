<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Frete por cidade, segunda parte (pedido do Lira em 2026-10-24):
 *
 * - frete_categorias_pacote: as quatro categorias de tamanho (Pequena,
 *   Média, Grande e Extra Grande) pelo peso tarifável e pelo maior lado da
 *   caixa. A Extra Grande é a da escada telescópica de 7 m (cerca de
 *   95 x 48 x 10 cm e 17 kg): fica com tudo o que passa da Grande.
 * - frete_regioes.categoria: a regra da cidade pode valer para todas as
 *   categorias (null) ou para uma só.
 * - frete_travas: frete que o visitante viu para um produto e um CEP, pelo IP
 *   e pela hash do aparelho; enquanto valer, o frete dele não sobe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frete_categorias_pacote', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 20)->unique(); // pequena | media | grande | extra_grande
            $table->string('nome', 40);
            $table->unsignedTinyInteger('ordem');
            $table->unsignedInteger('peso_max_g')->nullable(); // peso tarifável (real ou cúbico)
            $table->unsignedSmallInteger('lado_max_cm')->nullable(); // maior lado da caixa
            $table->timestamps();
        });

        $agora = now();
        DB::table('frete_categorias_pacote')->insert([
            ['chave' => 'pequena', 'nome' => 'Pequena', 'ordem' => 1, 'peso_max_g' => 1000, 'lado_max_cm' => 30, 'created_at' => $agora, 'updated_at' => $agora],
            ['chave' => 'media', 'nome' => 'Média', 'ordem' => 2, 'peso_max_g' => 5000, 'lado_max_cm' => 60, 'created_at' => $agora, 'updated_at' => $agora],
            ['chave' => 'grande', 'nome' => 'Grande', 'ordem' => 3, 'peso_max_g' => 12000, 'lado_max_cm' => 80, 'created_at' => $agora, 'updated_at' => $agora],
            ['chave' => 'extra_grande', 'nome' => 'Extra Grande', 'ordem' => 4, 'peso_max_g' => null, 'lado_max_cm' => null, 'created_at' => $agora, 'updated_at' => $agora],
        ]);

        Schema::table('frete_regioes', function (Blueprint $table) {
            $table->string('categoria', 20)->nullable()->after('tipo_preco');
        });

        Schema::create('frete_travas', function (Blueprint $table) {
            $table->id();
            $table->string('site', 20);
            $table->char('dispositivo_hash', 64);
            $table->string('ip', 45);
            $table->char('dispositivo_id', 36)->nullable(); // cookie do aparelho (reserva quando o IP muda)
            $table->char('cep', 8);
            $table->unsignedBigInteger('product_id');
            $table->decimal('embutido', 10, 2);
            $table->unsignedSmallInteger('prazo_dias')->nullable();
            $table->decimal('correios', 10, 2)->nullable();
            $table->string('regra', 120)->nullable();
            $table->timestamp('expira_em');
            $table->timestamps();

            $table->unique(['site', 'dispositivo_hash', 'ip', 'cep', 'product_id'], 'frete_travas_unica');
            $table->index(['site', 'cep', 'dispositivo_id']);
            $table->index('expira_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frete_travas');
        Schema::table('frete_regioes', fn (Blueprint $table) => $table->dropColumn('categoria'));
        Schema::dropIfExists('frete_categorias_pacote');
    }
};
