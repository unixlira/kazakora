<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\FreteCategoriaPacote;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PricingCalculatorController extends Controller
{
    /**
     * Calculadora de precificação por marketplace — tela sem persistência
     * própria (as taxas por canal e a regra de preço do Lira vivem em
     * `resources/js/Shared/marketplaceFees.js`). Entrega a lista de produtos
     * reais (custo e tamanho do pacote para o preenchimento rápido) e o frete
     * Correios por tamanho, tirado da tabela de frete da loja.
     */
    public function index(): Response
    {
        return Inertia::render('Admin/PricingCalculator/Index', [
            'products' => Product::query()
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'price', 'cost_price', 'categoria_frete']),
            'categorias' => array_map(
                fn (array $c) => ['chave' => $c['chave'], 'nome' => $c['nome']],
                FreteCategoriaPacote::todas(),
            ),
            'fretes' => $this->fretesCorreios(),
        ]);
    }

    /**
     * Frete Correios (o menor entre PAC e SEDEX) por tamanho do pacote, da
     * tabela `frete_tabela` (cotada pelos Correios, uma linha por CEP de
     * destino): média dos destinos em SP (CEP abaixo de 20000-000), média do
     * Brasil e o mais caro (pior caso). É o frete que a loja paga na venda
     * pelo site e na Amazon (envio pelos Correios).
     *
     * @return array<string, array{sp: ?float, brasil: ?float, pior: ?float}>
     */
    private function fretesCorreios(): array
    {
        return Cache::remember('precificacao-fretes-correios', now()->addHours(6), function () {
            $menor = 'CASE WHEN pac_preco IS NULL THEN sedex_preco '
                .'WHEN sedex_preco IS NULL OR pac_preco <= sedex_preco THEN pac_preco ELSE sedex_preco END';

            return DB::table('frete_tabela')
                ->where(fn ($q) => $q->whereNotNull('pac_preco')->orWhereNotNull('sedex_preco'))
                ->groupBy('categoria')
                ->get([
                    'categoria',
                    DB::raw("AVG(CASE WHEN cep_destino < '20000000' THEN {$menor} END) as sp"),
                    DB::raw("AVG({$menor}) as brasil"),
                    DB::raw("MAX({$menor}) as pior"),
                ])
                ->mapWithKeys(fn ($l) => [$l->categoria => [
                    'sp' => $l->sp !== null ? round((float) $l->sp, 2) : null,
                    'brasil' => $l->brasil !== null ? round((float) $l->brasil, 2) : null,
                    'pior' => $l->pior !== null ? round((float) $l->pior, 2) : null,
                ]])
                ->all();
        });
    }
}
