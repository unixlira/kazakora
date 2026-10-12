<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Services\CorreiosTabelaPorEstado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Logística → Fretes por estado (pedido do Lira em 2026-10-12): PAC e SEDEX
 * dos Correios para as 27 capitais, com o preço do contrato da KazaKora, para
 * um pacote escolhido (produto cadastrado ou medidas digitadas). Só consulta:
 * não cria postagem nem muda frete do site.
 */
class FretesPorEstadoController extends Controller
{
    /** Pacote inicial da tela: uma caixa pequena típica da loja. */
    private const PACOTE_PADRAO = ['peso_g' => 500, 'comprimento' => 20, 'largura' => 15, 'altura' => 10];

    public function index(): Response
    {
        $produtos = Product::query()
            ->where('is_active', true)
            ->whereHas('fiscalData', fn ($q) => $q->where('peso_bruto', '>', 0)
                ->where('altura_cm', '>', 0)->where('largura_cm', '>', 0)->where('profundidade_cm', '>', 0))
            ->with('fiscalData:id,product_id,peso_bruto,altura_cm,largura_cm,profundidade_cm')
            ->orderBy('name')
            ->get(['id', 'name', 'sku'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'nome' => $p->name,
                'sku' => $p->sku,
                'pacote' => self::pacoteDoProduto($p),
            ])
            ->values();

        return Inertia::render('Admin/FretesPorEstado/Index', [
            'produtos' => $produtos,
            'pacotePadrao' => self::PACOTE_PADRAO,
        ]);
    }

    public function cotar(Request $request, CorreiosTabelaPorEstado $tabela): JsonResponse
    {
        // Limites do PAC/SEDEX para "Pacote": até 30 kg, 16x11x2 cm no mínimo
        // e 100 cm por lado no máximo.
        $dados = $request->validate([
            'peso_g' => ['required', 'integer', 'min:1', 'max:30000'],
            'comprimento' => ['required', 'numeric', 'min:16', 'max:100'],
            'largura' => ['required', 'numeric', 'min:11', 'max:100'],
            'altura' => ['required', 'numeric', 'min:2', 'max:100'],
        ], [
            'peso_g.max' => 'O peso máximo do PAC e do SEDEX é 30 kg (30.000 g).',
            'comprimento.min' => 'O comprimento mínimo dos Correios é 16 cm.',
            'largura.min' => 'A largura mínima dos Correios é 11 cm.',
            'altura.min' => 'A altura mínima dos Correios é 2 cm.',
            'comprimento.max' => 'Cada lado pode ter no máximo 100 cm.',
            'largura.max' => 'Cada lado pode ter no máximo 100 cm.',
            'altura.max' => 'Cada lado pode ter no máximo 100 cm.',
        ]);

        return response()->json($tabela->cotar([
            'peso_g' => (int) $dados['peso_g'],
            'comprimento' => (float) $dados['comprimento'],
            'largura' => (float) $dados['largura'],
            'altura' => (float) $dados['altura'],
        ]));
    }

    /** Mesma regra do checkout (CorreiosFreightQuoteService): mínimos de 16x11x2 cm. */
    private static function pacoteDoProduto(Product $p): array
    {
        $f = $p->fiscalData;

        return [
            'peso_g' => max(1, (int) round((float) $f->peso_bruto * 1000)),
            'comprimento' => max(16.0, (float) $f->profundidade_cm),
            'largura' => max(11.0, (float) $f->largura_cm),
            'altura' => max(2.0, (float) $f->altura_cm),
        ];
    }
}
