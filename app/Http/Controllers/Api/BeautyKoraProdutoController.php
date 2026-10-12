<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\BeautyKora\Services\ConteudoProdutoBeautyKora;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Conteúdo da página de produto do site Beauty Kora (pedido 2026-10-12):
 * destaques, chamada, blocos com imagem, comparativo, benefícios e dúvidas,
 * no mesmo formato da página de produto da KazaKora. Público (a página é
 * pública); só gera para produto que existe na vitrine da Hostinger.
 */
class BeautyKoraProdutoController extends Controller
{
    public function conteudo(string $id, ConteudoProdutoBeautyKora $servico): JsonResponse
    {
        // Primeira visita depois de mudar o produto espera o Gemini (texto + fotos).
        @set_time_limit(120);

        try {
            $conteudo = $servico->conteudo($id);
            $produto = $servico->produto($id);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Conteúdo indisponível agora.'], 503);
        }

        if ($produto === null) {
            return response()->json(['message' => 'Produto não encontrado.'], 404);
        }

        return response()->json([
            'imagens' => $produto['imagens'],
            'conteudo' => $conteudo?->only(['destaques', 'chamada', 'blocos', 'comparativo', 'beneficios', 'duvidas', 'fonte']),
        ]);
    }
}
