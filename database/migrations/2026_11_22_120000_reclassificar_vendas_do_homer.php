<?php

use App\Modules\Infoprodutos\Support\ProdutoKiwify;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * 22/11: as vendas do Homer chegaram antes da regra do nome na config e
 * ficaram sem produto ("outro"). Refaz a classificação só dessas, pelo
 * id e nome da Kiwify já gravados. Não mexe em venda que já tem produto.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('infoproduto_vendas')->whereNull('produto')->orderBy('id')
            ->get(['id', 'kiwify_produto_id', 'kiwify_produto_nome', 'tipo'])
            ->each(function ($v) {
                [$produto, $tipo] = ProdutoKiwify::classificar($v->kiwify_produto_id, $v->kiwify_produto_nome);
                if ($produto !== null) {
                    DB::table('infoproduto_vendas')->where('id', $v->id)->update([
                        'produto' => $produto,
                        // Complemento já marcado pelo parent_order_id continua complemento.
                        'tipo' => $v->tipo === 'order_bump' && $tipo === 'principal' ? 'order_bump' : $tipo,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Sem volta: a classificação antiga era só "sem produto".
    }
};
