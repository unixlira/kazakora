<?php

namespace App\Modules\Marketplace\Support\Sugestoes;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrecoConcorrente;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\Oportunidades\InteligenciaMercadoLivre;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Preço de concorrente na internet para as Sugestões do dia (2026-10-20).
 *
 * Fontes que funcionam do servidor (testadas em 2026-10-20):
 * - Mercado Livre, catálogo: quando o nosso anúncio está num produto de
 *   catálogo, products/{id}/items dá o preço de todos os vendedores do MESMO
 *   produto (só 5 dos 72 anúncios tinham catálogo).
 * - Zoom (zoom.com.br, comparador do grupo Buscapé): junta Magalu, Amazon,
 *   Americanas, Casas Bahia e outros. A busca devolve a lista no
 *   __NEXT_DATA__ da página. Busca por nome, então passa por filtro de
 *   semelhança (mesmas regras do botão Inteligência do ML), por número do
 *   nome (168 peças não é 60 peças) e por faixa de preço (corta acessório e
 *   kit grande).
 *
 * Não funcionam: busca do ML (sites/MLB/search responde 403 para o app) e a
 * pesquisa do Google pelo Gemini (cota esgotada na chave da loja).
 *
 * Só leitura: não mexe em preço nem anúncio em lugar nenhum.
 */
class PesquisaDePrecos
{
    public const ZOOM_URL = 'https://www.zoom.com.br';

    /** Fora desta faixa do nosso preço é outro produto (acessório, kit maior). */
    public const FAIXA_MIN = 0.5;

    public const FAIXA_MAX = 2.0;

    /** Voltagem e números comuns que não dizem qual é o produto. */
    private const NUMEROS_NEUTROS = ['110', '127', '220', '2025', '2026'];

    /** @var array<string, array{ok: bool, consultas: int, ofertas: int, erro: ?string}> */
    private array $fontes = [];

    public function __construct(private readonly MercadoLivreClient $ml) {}

    /**
     * Pesquisa e guarda as ofertas do produto. Erro de uma fonte não para a
     * outra: fica anotado em fontes().
     *
     * @return list<array{fonte: string, loja: string, titulo: string, preco: float, link: ?string, similaridade: ?float}>
     */
    public function pesquisar(Product $produto): array
    {
        $nosso = $produto->precoSemOferta();
        $ofertas = [];

        foreach ([PrecoConcorrente::FONTE_MERCADO_LIVRE => fn () => $this->mercadoLivre($produto), PrecoConcorrente::FONTE_ZOOM => fn () => $this->zoom($produto)] as $fonte => $buscar) {
            $this->fontes[$fonte] ??= ['ok' => true, 'consultas' => 0, 'ofertas' => 0, 'erro' => null];
            try {
                $achadas = $buscar();
                if ($achadas === null) {
                    continue;
                }
                $this->fontes[$fonte]['consultas']++;
                $achadas = array_values(array_filter($achadas, fn ($o) => $nosso <= 0
                    || ($o['preco'] >= $nosso * self::FAIXA_MIN && $o['preco'] <= $nosso * self::FAIXA_MAX)));
                $this->fontes[$fonte]['ofertas'] += count($achadas);
                $ofertas = array_merge($ofertas, $achadas);
            } catch (Throwable $e) {
                $this->fontes[$fonte]['ok'] = false;
                $this->fontes[$fonte]['erro'] = mb_substr($e->getMessage(), 0, 200);
            }
        }

        usort($ofertas, fn ($a, $b) => $a['preco'] <=> $b['preco']);

        $agora = now();
        foreach ($ofertas as $o) {
            PrecoConcorrente::query()->create(['product_id' => $produto->id, 'coletado_em' => $agora] + $o);
        }

        return $ofertas;
    }

    /** @return array<string, array{ok: bool, consultas: int, ofertas: int, erro: ?string}> */
    public function fontes(): array
    {
        return $this->fontes;
    }

    /**
     * Vendedores do mesmo produto de catálogo. null = o produto não tem
     * anúncio no ML ou o anúncio não está em catálogo (não conta consulta).
     *
     * @return list<array<string, mixed>>|null
     */
    private function mercadoLivre(Product $produto): ?array
    {
        $anuncio = ProductChannelListing::query()
            ->where('product_id', $produto->id)
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->whereNotNull('external_id')
            ->value('external_id');
        if (! $anuncio) {
            return null;
        }

        $catalogo = $this->ml->get('items', ['ids' => $anuncio, 'attributes' => 'id,catalog_product_id'])[0]['body']['catalog_product_id'] ?? null;
        if (! $catalogo) {
            return null;
        }

        $nosso = (string) MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)->value('seller_id');
        $resposta = $this->ml->get("products/{$catalogo}/items", ['limit' => 50]);

        $ofertas = [];
        foreach ($resposta['results'] ?? [] as $item) {
            if (! isset($item['price']) || ($nosso !== '' && (string) ($item['seller_id'] ?? '') === $nosso)) {
                continue;
            }
            $id = (string) ($item['item_id'] ?? '');
            $ofertas[] = [
                'fonte' => PrecoConcorrente::FONTE_MERCADO_LIVRE,
                'loja' => 'Mercado Livre'.(($item['shipping']['free_shipping'] ?? false) ? ' (frete grátis)' : ''),
                'titulo' => (string) $produto->name,
                'preco' => round((float) $item['price'], 2),
                'link' => $id !== '' ? 'https://produto.mercadolivre.com.br/'.preg_replace('/^MLB/', 'MLB-', $id) : null,
                // Mesmo produto de catálogo: é o mesmo item, não "parecido".
                'similaridade' => 1.0,
            ];
        }

        return $ofertas;
    }

    /** @return list<array<string, mixed>> */
    private function zoom(Product $produto): array
    {
        $termo = self::termoDeBusca((string) $produto->name);
        if ($termo === '') {
            return [];
        }

        $resposta = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
            'Accept-Language' => 'pt-BR,pt;q=0.9',
        ])->timeout(20)->get(self::ZOOM_URL.'/search', ['q' => $termo]);

        if (! $resposta->successful()) {
            throw new RuntimeException("Zoom respondeu HTTP {$resposta->status()}.");
        }

        $pausa = (int) config('loja.sugestoes_pausa_ms', 1500);
        if ($pausa > 0) {
            usleep($pausa * 1000);
        }

        return self::ofertasDoZoom($resposta->body(), (string) $produto->name);
    }

    /**
     * Lê a lista de resultados do HTML do Zoom e fica só com o que é o mesmo
     * produto. Público para o teste.
     *
     * @return list<array<string, mixed>>
     */
    public static function ofertasDoZoom(string $html, string $nossoNome): array
    {
        if (! preg_match('/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $m)) {
            throw new RuntimeException('Zoom mudou a página: lista de resultados não encontrada.');
        }
        $dados = json_decode($m[1], true);
        $hits = $dados['props']['initialReduxState']['hits']['hits'] ?? null;
        if (! is_array($hits)) {
            throw new RuntimeException('Zoom mudou a página: lista de resultados não encontrada.');
        }

        $nossos = InteligenciaMercadoLivre::tokens($nossoNome);
        $numeros = self::numeros($nossoNome);
        $ofertas = [];
        $vistas = [];

        foreach ($hits as $hit) {
            $titulo = (string) ($hit['name'] ?? '');
            $preco = (float) ($hit['price'] ?? 0);
            $loja = (string) ($hit['merchantName'] ?? $hit['bestOffer']['merchantName'] ?? '');
            if ($titulo === '' || $preco <= 0 || Str::contains(Str::lower($loja), 'kazakora')) {
                continue;
            }
            // Produto do Zoom com várias lojas: o preço é o menor delas.
            if ($loja === '') {
                $loja = ($hit['storeCount'] ?? 0) > 1 ? 'Várias lojas ('.$hit['storeCount'].')' : 'Loja no Zoom';
            }

            $nota = InteligenciaMercadoLivre::similaridade($nossos, InteligenciaMercadoLivre::tokens($titulo));
            if ($nota < InteligenciaMercadoLivre::SIMILARIDADE_MINIMA) {
                continue;
            }
            // Número que define o produto (168 peças, 48V, 2,10 m) tem que bater.
            if ($numeros !== [] && array_intersect($numeros, self::numeros($titulo)) === []) {
                continue;
            }

            // O mesmo anúncio de marketplace aparece várias vezes na busca.
            $repetida = mb_strtolower($loja.'|'.$titulo.'|'.$preco);
            if (isset($vistas[$repetida])) {
                continue;
            }
            $vistas[$repetida] = true;

            $url = (string) ($hit['url'] ?? '');
            $ofertas[] = [
                'fonte' => PrecoConcorrente::FONTE_ZOOM,
                'loja' => mb_substr($loja, 0, 120),
                'titulo' => mb_substr($titulo, 0, 300),
                'preco' => round($preco, 2),
                'link' => $url === '' ? null : (str_starts_with($url, 'http') ? $url : self::ZOOM_URL.$url),
                'similaridade' => round($nota, 2),
            ];
        }

        return $ofertas;
    }

    /** Até 8 palavras do nome, sem voltagem e sem pontuação. */
    public static function termoDeBusca(string $nome): string
    {
        $limpo = preg_replace('/\b(bivolt|110v|127v|220v|110|220)\b/iu', ' ', $nome);
        $palavras = preg_split('/\s+/', trim(preg_replace('/[\/|,()\-–+]+/u', ' ', (string) $limpo))) ?: [];

        return implode(' ', array_slice(array_filter($palavras), 0, 8));
    }

    /** Números do nome que identificam o produto (168, 48v, 210). @return list<string> */
    private static function numeros(string $nome): array
    {
        preg_match_all('/\d+(?:[.,]\d+)?\s*(?:v|w|m|cm|mm|l|ml|pecas|peças|pcs|p)?\b/iu', Str::lower($nome), $m);

        $numeros = [];
        foreach ($m[0] as $bruto) {
            $digitos = preg_replace('/\D/', '', $bruto);
            if (strlen($digitos) < 2 || in_array($digitos, self::NUMEROS_NEUTROS, true)) {
                continue;
            }
            $numeros[] = $digitos;
        }

        return array_values(array_unique($numeros));
    }
}
