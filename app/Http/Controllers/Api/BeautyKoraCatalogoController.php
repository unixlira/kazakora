<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\BeautyKora\Services\CatalogoBeautyKora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Catálogo do site Beauty Kora vindo do KazaKora (pedido 2026-10-12).
 * GET é público (vitrine); POST recebe o JSON dos produtos com
 * Authorization: Bearer <BEAUTYKORA_CATALOGO_TOKEN>:
 * {"produtos": [{"sku": "BEL-...", "titulo": null, "ordem": 1, "ativo": true}]}
 */
class BeautyKoraCatalogoController extends Controller
{
    public function __construct(private readonly CatalogoBeautyKora $catalogo) {}

    public function index(): JsonResponse
    {
        $produtos = $this->catalogo->produtos();

        return response()->json(['count' => count($produtos), 'offset' => 0, 'limit' => count($produtos), 'products' => $produtos]);
    }

    public function show(string $id): JsonResponse
    {
        $produto = $this->catalogo->produto($id);

        return $produto === null
            ? response()->json(['message' => 'Produto não encontrado.'], 404)
            : response()->json(['product' => $produto]);
    }

    public function sincronizar(Request $request): JsonResponse
    {
        $esperado = (string) config('services.beautykora.catalogo_token');
        $recebido = (string) Str::after((string) $request->header('Authorization'), 'Bearer ');

        if ($esperado === '' || ! hash_equals($esperado, $recebido)) {
            return response()->json(['ok' => false, 'erro' => 'Token inválido.'], 401);
        }

        $dados = $request->validate([
            'produtos' => ['required', 'array', 'min:1', 'max:200'],
            'produtos.*.sku' => ['required', 'string', 'max:100'],
            'produtos.*.titulo' => ['nullable', 'string', 'max:255'],
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
