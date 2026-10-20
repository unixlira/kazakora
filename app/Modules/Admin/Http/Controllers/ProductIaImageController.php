<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductIaImage;
use App\Modules\Catalog\Services\ImagensIaAnuncioService;
use App\Modules\WhatsApp\Services\GeminiUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Gerar imagens com IA" na edição do produto (pedido 2026-10-19): a tela
 * pede o plano do lote e depois uma imagem por vez, revisa e aprova.
 */
class ProductIaImageController extends Controller
{
    public function __construct(private readonly ImagensIaAnuncioService $servico) {}

    public function planejar(Request $request, Product $product): JsonResponse
    {
        // Validação à mão: no admin a ValidationException redireciona, e esta tela fala JSON.
        $quantidade = filter_var($request->input('quantidade'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => ImagensIaAnuncioService::MIN, 'max_range' => ImagensIaAnuncioService::MAX],
        ]);

        if ($quantidade === false) {
            return response()->json(['message' => 'Escolha de '.ImagensIaAnuncioService::MIN.' a '.ImagensIaAnuncioService::MAX.' imagens.'], 422);
        }

        return $this->responder(fn () => ['imagens' => $this->servico->planejar($product, $quantidade)->values()]);
    }

    public function gerar(Product $product, ProductIaImage $imagem): JsonResponse
    {
        abort_if($imagem->product_id !== $product->id, 404);

        return $this->responder(fn () => ['imagem' => $this->servico->gerar($imagem)], $imagem);
    }

    public function aprovar(Product $product, ProductIaImage $imagem): JsonResponse
    {
        abort_if($imagem->product_id !== $product->id, 404);

        return $this->responder(fn () => ['imagem' => $this->servico->aprovar($imagem)]);
    }

    public function descartar(Product $product, ProductIaImage $imagem): JsonResponse
    {
        abort_if($imagem->product_id !== $product->id, 404);

        return $this->responder(function () use ($imagem) {
            $this->servico->descartar($imagem);

            return ['ok' => true];
        });
    }

    /** Erro vira mensagem para a tela; com o rascunho, devolve ele também (status erro). */
    private function responder(callable $acao, ?ProductIaImage $imagem = null): JsonResponse
    {
        try {
            return response()->json($acao());
        } catch (GeminiUnavailableException) {
            return response()->json(['message' => 'A IA está sem cota ou ocupada agora. Tente de novo em 1 minuto.', 'imagem' => $imagem?->fresh()], 503);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => Str::limit($exception->getMessage(), 300), 'imagem' => $imagem?->fresh()], 422);
        }
    }
}
