<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Brinde físico da oferta (pedido do Lira em 21/11/2026): o que vai de graça
 * na caixa. Com ele preenchido, toda venda do produto sai com uma segunda
 * NF-e, de bonificação (BonificacaoDoPedido). Nome vazio desliga.
 */
class ProductBrindeFisicoController extends Controller
{
    public function update(Request $request, Product $product): RedirectResponse
    {
        $dados = $request->validate([
            'nome' => ['nullable', 'string', 'max:120'],
            'quantidade' => ['nullable', 'integer', 'min:1', 'max:20'],
            'ncm' => ['nullable', 'string', 'regex:/^\d{4}\.?\d{2}\.?\d{2}$/'],
            'origem' => ['nullable', 'integer', 'min:0', 'max:8'],
        ], ['ncm.regex' => 'O NCM tem 8 dígitos (ex.: 9503.00.99).']);

        $nome = trim((string) ($dados['nome'] ?? ''));

        $product->update(['brinde_fisico' => $nome === '' ? null : [
            'nome' => $nome,
            'quantidade' => (int) ($dados['quantidade'] ?? 1),
            'ncm' => preg_replace('/\D/', '', (string) ($dados['ncm'] ?? '')) ?: '95030099',
            'origem' => isset($dados['origem']) ? (int) $dados['origem'] : null,
        ]]);

        return back()->with('success', $nome === '' ? 'Brinde físico removido: as vendas saem só com a nota de venda.' : 'Brinde físico salvo: cada venda sai também com a nota de bonificação dele.');
    }
}
