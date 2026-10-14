<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\BeautyKora\Services\CatalogoBeautyKora;
use App\Modules\Checkout\Support\EntregaExpressa;
use App\Modules\Checkout\Support\FreteEmbutido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Catálogo do site Beauty Kora vindo do KazaKora (pedido 2026-10-12).
 * GET é público (vitrine); POST recebe o JSON dos produtos com
 * Authorization: Bearer <BEAUTYKORA_CATALOGO_TOKEN>:
 * {"produtos": [{"sku": "BEL-...", "titulo": null, "ordem": 1, "ativo": true, "clonado": true}]}
 *
 * Desde 2026-10-15 o preço é o da região do visitante (frete embutido, igual
 * ao FerraKora) e GET regiao diz ao site, assim que ele abre, se a região tem
 * frete grátis e entrega FULL (o raio no card). Região: ?cep= (o CEP que o
 * cliente já digitou no site) → estado e cidade pelo IP (Cloudflare) → nenhuma.
 * Fora do kazakora.com, ?simular_uf=SP&simular_cidade=Osasco testa sem Cloudflare.
 */
class BeautyKoraCatalogoController extends Controller
{
    public function __construct(private readonly CatalogoBeautyKora $catalogo) {}

    public function index(Request $request): JsonResponse
    {
        $this->ligarFreteEmbutido($request);
        $produtos = $this->catalogo->produtos();

        return $this->semCache(response()->json(['count' => count($produtos), 'offset' => 0, 'limit' => count($produtos), 'products' => $produtos]));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->ligarFreteEmbutido($request);
        $produto = $this->catalogo->produto($id);

        return $produto === null
            ? response()->json(['message' => 'Produto não encontrado.'], 404)
            : $this->semCache(response()->json(['product' => $produto]));
    }

    /**
     * {frete_gratis, origem: "cep"|"ip"|null, uf, cidade, full: {tipo, mensagem, local}|null, turbo}
     * full = entrega FULL na Grande SP (raio); fora dela, frete grátis com a opção TURBO no checkout.
     */
    public function regiao(Request $request, EntregaExpressa $expressa): JsonResponse
    {
        $frete = $this->ligarFreteEmbutido($request);
        $destino = $frete->destino();
        $cidade = $destino['origem'] === 'ip' ? $this->cidadePeloIp($request) : null;

        $full = match ($destino['origem']) {
            'cep' => $expressa->consultar($destino['cep']),
            'ip' => $destino['uf'] === 'SP' ? $expressa->consultarCidade($cidade) : null,
            default => null,
        };

        return $this->semCache(response()->json([
            'frete_gratis' => $frete->ligado(),
            'origem' => $destino['origem'],
            'uf' => $destino['uf'],
            'cidade' => $cidade,
            'full' => $full,
            'turbo' => $full === null,
        ]));
    }

    /**
     * Preço com o frete embutido da região do visitante, igual à página do
     * produto no KazaKora. O middleware AtivarFreteEmbutido pula api/*: aqui
     * liga só nas leituras do Beauty Kora. CEP válido na URL vale acima do IP.
     */
    private function ligarFreteEmbutido(Request $request): FreteEmbutido
    {
        $frete = app(FreteEmbutido::class);
        $frete->ligar();

        $cep = preg_replace('/\D/', '', (string) $request->query('cep'));
        if (strlen($cep) === 8) {
            $frete->usarCep($cep);
        }

        return $frete;
    }

    /** CF-IPCity (regra "Add visitor location headers"); fora do kazakora.com, ?simular_cidade=. */
    private function cidadePeloIp(Request $request): ?string
    {
        $cidade = parse_url((string) config('app.url'), PHP_URL_HOST) !== 'kazakora.com'
            ? (string) $request->query('simular_cidade', '')
            : '';

        if ($cidade === '') {
            $cidade = (string) $request->header('CF-IPCity', '');
            $cidade = str_contains($cidade, '%') ? rawurldecode($cidade) : $cidade;
        }

        $cidade = trim(Str::limit($cidade, 80, ''));

        return $cidade !== '' && mb_check_encoding($cidade, 'UTF-8') ? $cidade : null;
    }

    /** O preço e a região mudam com o visitante: nenhum cache guarda a resposta. */
    private function semCache(JsonResponse $resposta): JsonResponse
    {
        $resposta->headers->set('Cache-Control', 'private, no-store');
        $resposta->setVary(['CF-Region-Code', 'CF-IPCountry', 'CF-IPCity'], false);

        return $resposta;
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
            'produtos.*.clonado' => ['nullable', 'boolean'],
        ]);

        $resultado = $this->catalogo->sincronizar($dados['produtos']);

        if ($resultado['nao_encontrados'] !== []) {
            return response()->json(['ok' => false, 'erro' => 'SKU não cadastrado no KazaKora.', ...$resultado], 422);
        }

        return response()->json(['ok' => true, ...$resultado]);
    }
}
