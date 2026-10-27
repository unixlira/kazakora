<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico das campanhas da Meta excluídas (pedido do Lira em 2026-10-27,
 * tela Gasto e resultados): as 13 campanhas da conta foram para DELETED na
 * Meta em 27/10 para a reestruturação. Os números são da foto tirada antes
 * da exclusão (~/projects/homer/workspace/dados/meta-ads-historico-2026-10-27,
 * espelho da Meta com período "desde o início"), copiados como estão.
 * Inclui as 4 criadas direto no Gerenciador, que não estavam no banco.
 */
return new class extends Migration
{
    private const COLUNAS = ['meta_id', 'nome', 'status_final', 'objetivo', 'orcamento_nivel', 'orcamento_diario', 'inicio', 'fim', 'gasto', 'impressoes', 'alcance', 'cliques_link', 'ctr_link', 'carrinho', 'checkout', 'compras', 'vendas', 'roas', 'conjuntos', 'anuncios'];

    public function up(): void
    {
        Schema::create('meta_ads_campanhas_historico', function (Blueprint $table) {
            $table->id();
            $table->string('meta_id', 40)->unique();
            $table->string('nome');
            $table->string('status_final', 20);
            $table->string('objetivo', 40)->nullable();
            $table->string('orcamento_nivel', 20)->nullable();
            $table->decimal('orcamento_diario', 10, 2)->default(0);
            $table->date('inicio')->nullable();
            $table->date('fim')->nullable();
            $table->decimal('gasto', 12, 2)->default(0);
            $table->unsignedInteger('impressoes')->default(0);
            $table->unsignedInteger('alcance')->default(0);
            $table->unsignedInteger('cliques_link')->default(0);
            $table->decimal('ctr_link', 6, 2)->default(0);
            $table->unsignedInteger('carrinho')->default(0);
            $table->unsignedInteger('checkout')->default(0);
            $table->unsignedInteger('compras')->default(0);
            $table->decimal('vendas', 12, 2)->default(0);
            $table->decimal('roas', 8, 2)->default(0);
            $table->unsignedSmallInteger('conjuntos')->default(0);
            $table->unsignedSmallInteger('anuncios')->default(0);
            $table->date('excluida_em')->nullable();
            $table->timestamps();

            $table->index('inicio');
        });

        $agora = now();
        $excluidaEm = '2026-10-27';

        foreach ($this->campanhas() as $valores) {
            DB::table('meta_ads_campanhas_historico')->insert(array_combine(self::COLUNAS, $valores) + [
                'excluida_em' => $excluidaEm,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ads_campanhas_historico');
    }

    /** @return list<list<mixed>> */
    private function campanhas(): array
    {
        return [
            ['120251240256240271', 'KK | Vendas | Triturador 4x1 | SP | Teste 01', 'ARCHIVED', 'Vendas', 'campanha', 40, '2026-06-15', '2026-06-27', 255.35, 5588, 4204, 453, 8.11, 2, 0, 0, 0, 0, 1, 9],
            ['120247094956630271', '[VENDAS] Umidificador e Difusor de Aromas Chama de Carvão', 'ARCHIVED', 'Vendas', 'conjunto', 0, '2026-04-27', '2026-05-01', 228.08, 2276, 2145, 96, 4.22, 3, 0, 1, 145.72, 0.64, 4, 4],
            ['120255247500460271', 'Parafusadeira 48V Kicool - Vendas', 'PAUSED', 'Vendas', 'campanha', 20, '2026-10-18', null, 167.95, 4351, 3535, 215, 4.94, 15, 19, 2, 330.52, 1.97, 1, 2],
            ['120255241018870271', 'Vendas | Árvores Natal | Out26', 'ARCHIVED', 'Vendas', 'conjunto', 0, '2026-10-18', '2026-10-25', 160.67, 2262, 1194, 82, 3.63, 48, 25, 0, 0, 0, 1, 5],
            ['120255247403710271', 'Caixa Sanfonada 168 Peças - Vendas', 'PAUSED', 'Vendas', 'campanha', 15, '2026-10-18', null, 136.69, 2794, 2303, 76, 2.72, 6, 6, 0, 0, 0, 1, 7],
            ['120255247562730271', 'KazaKora | Vendas | Kit Macaco Elétrico 4 em 1 | Out/26', 'PAUSED', 'Vendas', 'campanha', 15, '2026-10-18', null, 119.15, 1747, 1335, 115, 6.58, 6, 6, 0, 0, 0, 1, 4],
            ['120255270252560271', 'Vendas | Bike Spinning', 'PAUSED', 'Vendas', 'campanha', 15, '2026-10-19', null, 83.23, 2433, 2064, 86, 3.53, 11, 13, 0, 0, 0, 1, 3],
            ['120252733169070271', 'Cozedor de ovo', 'ARCHIVED', 'Vendas', 'campanha', 10, '2026-07-03', '2026-07-06', 43.22, 1216, 1177, 78, 6.41, 1, 0, 0, 0, 0, 1, 2],
            ['120255412189060271', 'Parafusadeira 18V Leve | Mulheres | Vendas', 'PAUSED', 'Vendas', 'campanha', 20, '2026-10-25', null, 35.64, 190, 152, 21, 11.05, 0, 0, 0, 0, 0, 1, 5],
            ['120252731429600271', 'Organizador De Ovo', 'ARCHIVED', 'Vendas', 'campanha', 10, '2026-07-03', '2026-07-06', 32.44, 1163, 1041, 72, 6.19, 0, 0, 0, 0, 0, 1, 2],
            ['120255318792070271', 'Vendas | Brinquedo Pia', 'PAUSED', 'Vendas', 'campanha', 9.99, '2026-10-21', null, 16.51, 186, 165, 2, 1.08, 0, 0, 0, 0, 0, 1, 1],
            ['120255316231810271', 'Vendas | Cortador Ralador Legumes Verde', 'PAUSED', 'Vendas', 'campanha', 10, '2026-10-21', null, 14.29, 327, 287, 18, 5.5, 1, 1, 0, 0, 0, 1, 1],
            ['120255323319730271', 'Vendas | Brinquedo Caminhao Dinossauro Dispara Carros', 'PAUSED', 'Vendas', 'campanha', 10, '2026-10-21', null, 9.11, 205, 194, 0, 0, 0, 0, 0, 0, 0, 1, 1],
        ];
    }
};
