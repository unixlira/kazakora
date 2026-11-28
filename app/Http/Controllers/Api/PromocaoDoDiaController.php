<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Support\LeveDois;
use App\Support\SiteAtual;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/promocao-do-dia (pedido do Lira em 27/11/2026): código do cupom
 * do dia e o preço da 2ª unidade de cada produto da KazaKora, para a máquina
 * de redes sociais montar os posts de oferta. Só leitura, público (nada de
 * custo nem margem na resposta). 10 minutos em cache.
 *
 * preco_normal é o preço de cadastro sem o frete somado na loja: na loja o
 * cliente vê esse preço mais o frete da região dele.
 */
class PromocaoDoDiaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $codigo = LeveDois::codigoDoDia();

        $dados = Cache::remember('leve2:api:'.$codigo, now()->addMinutes(10), fn () => app(SiteAtual::class)->durante(SiteAtual::KAZAKORA, function () {
            $cupom = LeveDois::cupomDoDia();
            if (! $cupom) {
                return ['cupom' => null, 'valido_ate' => null, 'chamada' => null, 'produtos' => []];
            }

            $produtos = LeveDois::produtos()->map(function (array $linha) {
                /** @var Product $produto */
                $produto = $linha['produto'];
                $pagina = $produto->parent ?? $produto;
                $normal = $produto->precoSemOferta();

                return [
                    'id' => $produto->id,
                    'nome' => $produto->nomeParaPedido(),
                    'url' => $pagina->slug ? route('produtos.ver', $pagina->slug) : null,
                    'imagem' => $produto->images->first()?->url,
                    'preco_normal' => round($normal, 2),
                    'preco_segunda_unidade' => $linha['preco'],
                    'economia' => round($normal - $linha['preco'], 2),
                    'economia_pct' => (int) floor(($normal - $linha['preco']) / $normal * 100),
                    'preco_do_par' => round($normal + $linha['preco'], 2),
                ];
            })->all();

            return [
                'cupom' => $cupom->code,
                'valido_ate' => $cupom->expires_at?->toIso8601String(),
                'chamada' => 'Compre 1, o 2º sai por menos com o cupom '.$cupom->code,
                'regra' => 'Com o cupom, na compra de 2 unidades do mesmo produto a 2ª sai pelo preco_segunda_unidade (4ª, 6ª... também). Não vale com Oferta do Dia/Relâmpago. Só na kazakora.com, só hoje.',
                'produtos' => $produtos,
            ];
        }));

        return response()->json(['data' => $dados, 'gerado_em' => now()->toIso8601String()]);
    }
}
