<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Checkout\Support\PrazoPorCep;
use App\Modules\FerraKora\Services\CatalogoFerraKora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
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
        // A lista (cards, menu, busca) não usa a descrição nem "media" (cópia
        // de "images"): saem para a resposta e o cache do navegador ficarem
        // leves (2026-10-15). A página do produto (show) continua com tudo.
        // "termos" (2026-11-19): as palavras da descrição completa e dos
        // slugs (FerraKora e KazaKora), sem repetição, para a busca do site
        // achar o produto mesmo com título persuasivo sem a palavra básica.
        $produtos = array_map(
            fn (array $produto) => [...Arr::except($produto, ['description', 'media']), 'termos' => self::termosDeBusca($produto)],
            $this->catalogo->produtos(),
        );

        return $this->semCache(response()->json(['count' => count($produtos), 'offset' => 0, 'limit' => count($produtos), 'products' => $produtos]));
    }

    /** Palavras (3+ letras, sem acento, minúsculas, sem repetir) da descrição e dos slugs do produto. */
    public static function termosDeBusca(array $produto): string
    {
        $texto = implode(' ', [
            html_entity_decode(strip_tags(str_replace('<', ' <', (string) ($produto['description'] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            (string) ($produto['slug'] ?? ''),
            basename((string) parse_url((string) ($produto['kazakora_url'] ?? ''), PHP_URL_PATH)),
        ]);
        preg_match_all('/[a-z0-9]{3,}/', Str::lower(Str::ascii($texto)), $palavras);

        return implode(' ', array_values(array_unique($palavras[0])));
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
     * Endereço antigo do produto (2026-11-18): o Nginx do ferrakora.com manda
     * para cá o /produto/<slug>/ que não tem página no build; slug que o
     * produto já teve responde 301 para o endereço atual, o resto 404 (e o
     * Nginx entrega o site, que mostra "não encontrado").
     */
    public function produtoAntigo(string $slug): Response
    {
        $atual = $this->catalogo->slugAtualDoAntigo($slug);

        return $atual === null
            ? response('', 404)
            : response('', 301, ['Location' => '/produto/'.$atual.'/', 'Cache-Control' => 'public, max-age=600']);
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

    /**
     * Prazo pelo CEP na página do produto do site, igual ao do KazaKora:
     * Full na área da entrega expressa, Flex quando os Correios entregam em
     * até 3 dias úteis, senão só o prazo normal. Só produto publicado no site.
     */
    public function prazo(Request $request, string $id): JsonResponse
    {
        $cep = preg_replace('/\D/', '', (string) $request->query('cep'));

        if (strlen($cep) !== 8) {
            return response()->json(['message' => 'Informe um CEP com 8 números.'], 422);
        }

        $produto = $this->catalogo->produto($id);

        if ($produto === null) {
            return response()->json(['message' => 'Produto não encontrado.'], 404);
        }

        $pai = Product::query()->with('fiscalData')->find((int) Str::after($produto['id'], CatalogoFerraKora::PREFIXO_PRODUTO));
        $prazo = app(PrazoPorCep::class)->consultar($cep, $pai);

        // Tabela de entrega (pedido 2026-10-23): prazo do grátis e TURBO pela
        // mesma conta do checkout, como na página do produto do KazaKora.
        $frete = app(FreteEmbutido::class);
        $frete->usarCep($cep);
        $entrega = $pai
            ? rescue(fn () => $frete->entregaDoCarrinho(collect([['product' => $pai, 'quantity' => 1]])), null, false)
            : null;

        return $this->semCache(response()->json([...$prazo, 'opcoes' => PrazoPorCep::opcoes($prazo, $entrega)]));
    }

    /** Avaliações do produto, 12 por página (pedido 2026-10-28). */
    public function avaliacoes(Request $request, string $id): JsonResponse
    {
        $pai = $this->catalogo->paiPublicado($id);

        if ($pai === null) {
            return response()->json(['message' => 'Produto não encontrado.'], 404);
        }

        $pagina = max(1, min(500, (int) $request->query('pagina', 1)));

        return response()->json($this->catalogo->avaliacoes($pai, $pagina, 12))->header('Cache-Control', 'public, max-age=300');
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
