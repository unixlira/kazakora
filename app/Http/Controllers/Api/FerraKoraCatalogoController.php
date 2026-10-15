<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\FerraKora\Services\CatalogoFerraKora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Catálogo do site FerraKora vindo do KazaKora (pedido 2026-10-13), igual
 * ao do Beauty Kora. GET é público (vitrine); POST recebe o JSON dos
 * produtos com Authorization: Bearer <FERRAKORA_CATALOGO_TOKEN>:
 * {"produtos": [{"sku": "...", "titulo": null, "categoria": "Trenas", "ordem": 1, "ativo": true}]}
 */
class FerraKoraCatalogoController extends Controller
{
    public function __construct(private readonly CatalogoFerraKora $catalogo) {}

    public function index(): JsonResponse
    {
        $this->ligarFreteEmbutido();
        $produtos = $this->catalogo->produtos();

        return $this->semCache(response()->json(['count' => count($produtos), 'offset' => 0, 'limit' => count($produtos), 'products' => $produtos]));
    }

    public function show(string $id): JsonResponse
    {
        $this->ligarFreteEmbutido();
        // Página do produto: leva o conteúdo gerado pelo Gemini (2026-10-14).
        $produto = $this->catalogo->produtoComConteudo($id);

        return $produto === null
            ? response()->json(['message' => 'Produto não encontrado.'], 404)
            : $this->semCache(response()->json(['product' => $produto]));
    }

    /**
     * Preço com o frete embutido da região do visitante, igual à página do
     * produto no KazaKora (2026-10-13). O middleware AtivarFreteEmbutido pula
     * api/*: aqui liga só nas leituras do catálogo do FerraKora. A região sai
     * do CF-IPCountry/CF-Region-Code (a chamada vem do navegador do visitante)
     * ou, fora do kazakora.com, de ?simular_uf=XX.
     */
    private function ligarFreteEmbutido(): void
    {
        app(FreteEmbutido::class)->ligar();
    }

    /** O preço muda com a região do visitante: nenhum cache guarda a resposta. */
    private function semCache(JsonResponse $resposta): JsonResponse
    {
        $resposta->headers->set('Cache-Control', 'private, no-store');
        $resposta->setVary(['CF-Region-Code', 'CF-IPCountry'], false);

        return $resposta;
    }

    public function sincronizar(Request $request): JsonResponse
    {
        $esperado = (string) config('services.ferrakora.catalogo_token');
        $recebido = (string) Str::after((string) $request->header('Authorization'), 'Bearer ');

        if ($esperado === '' || ! hash_equals($esperado, $recebido)) {
            return response()->json(['ok' => false, 'erro' => 'Token inválido.'], 401);
        }

        $dados = $request->validate([
            'produtos' => ['required', 'array', 'min:1', 'max:200'],
            'produtos.*.sku' => ['required', 'string', 'max:100'],
            'produtos.*.titulo' => ['nullable', 'string', 'max:255'],
            'produtos.*.categoria' => ['nullable', 'string', 'max:100'],
            'produtos.*.ordem' => ['nullable', 'integer'],
            'produtos.*.ativo' => ['nullable', 'boolean'],
        ]);

        $resultado = $this->catalogo->sincronizar($dados['produtos']);

        if ($resultado['nao_encontrados'] !== []) {
            return response()->json(['ok' => false, 'erro' => 'SKU não cadastrado no KazaKora.', ...$resultado], 422);
        }

        return response()->json(['ok' => true, ...$resultado]);
    }
}
