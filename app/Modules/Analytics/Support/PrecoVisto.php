<?php

namespace App\Modules\Analytics\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CatalogoOutroSite;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\FerraKora\Services\CatalogoFerraKora;
use App\Support\SiteAtual;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Preço que a página do produto mostra ao abrir e o frete que está dentro
 * dele, gravados na visita (site_visits.preco_visto e frete_visto, pedidos do
 * Lira em 2026-11-12). Não refaz conta de preço: lê o mesmo valor que vai
 * para a tela e só repete a escolha de qual variação aparece antes de o
 * cliente clicar em alguma. O frete é o embutido dessa mesma variação
 * (Product::freteEmbutido), para a região do visitante.
 *
 * - KazaKora (ProductDetailV2.vue, "maisBarata"): produto sem variação mostra
 *   o preço dele; com variação, o da mais barata, contando só as com estoque
 *   quando houver alguma.
 * - FerraKora (ProdutoPage.jsx): a primeira variante da API do catálogo, com
 *   o preço promocional quando ele é menor.
 *
 * Se a regra da tela mudar, muda aqui junto.
 */
class PrecoVisto
{
    /** Atributos da requisição: a página do produto deixa os valores para o TrackSiteVisit gravar. */
    public const ATRIBUTO = 'preco_visto';

    public const ATRIBUTO_FRETE = 'frete_visto';

    /**
     * @param  Collection<int, Product>  $variacoes  as outras variações do grupo, como vão para a página
     * @return array{preco_visto: ?float, frete_visto: ?float}
     */
    public static function daLoja(Product $produto, Collection $variacoes): array
    {
        $opcoes = collect([$produto])->concat($variacoes);
        $comEstoque = $variacoes->isEmpty() ? collect() : $opcoes->filter(fn (Product $p) => (int) $p->stock > 0);

        /** @var Product $mostrada */
        $mostrada = ($comEstoque->isNotEmpty() ? $comEstoque : $opcoes)->sortBy(fn (Product $p) => (float) $p->final_price)->first();
        $preco = round((float) $mostrada->final_price, 2);

        return $preco > 0
            ? ['preco_visto' => $preco, 'frete_visto' => round($mostrada->freteEmbutido(), 2)]
            : self::nada();
    }

    public static function guardar(Request $request, Product $produto, Collection $variacoes): void
    {
        $valores = rescue(fn () => self::daLoja($produto, $variacoes), self::nada(), false);

        $request->attributes->set(self::ATRIBUTO, $valores['preco_visto']);
        $request->attributes->set(self::ATRIBUTO_FRETE, $valores['frete_visto']);
    }

    /**
     * O que a página de produto deixou nesta requisição (nulo nas outras páginas).
     *
     * @return array{preco_visto: ?float, frete_visto: ?float}
     */
    public static function daRequisicao(Request $request): array
    {
        return ['preco_visto' => $request->attributes->get(self::ATRIBUTO), 'frete_visto' => $request->attributes->get(self::ATRIBUTO_FRETE)];
    }

    /**
     * Página /produto/<slug> do FerraKora: o preço da API do catálogo para a
     * região deste visitante (o aviso de visita vem do navegador dele, pela
     * Cloudflare, igual à chamada do catálogo). Outra página ou produto fora
     * do site: tudo nulo.
     *
     * @return array{preco_visto: ?float, frete_visto: ?float}
     */
    public static function doFerraKora(string $pagina): array
    {
        if (preg_match('#^/produto/([a-z0-9-]{1,200})/?(?:[?\#].*)?$#', $pagina, $achado) !== 1) {
            return self::nada();
        }

        app(FreteEmbutido::class)->ligar();
        $variante = app(CatalogoFerraKora::class)->produto($achado[1])['variants'][0] ?? null;
        $preco = $variante['prices'][0] ?? null;
        if ($preco === null) {
            return self::nada();
        }

        $centavos = $preco['sale_amount'] !== null && $preco['sale_amount'] < $preco['amount'] ? $preco['sale_amount'] : $preco['amount'];
        if ($centavos <= 0) {
            return self::nada();
        }

        // Frete embutido da mesma variante, com as regras do site FerraKora.
        $produto = Product::query()->find((int) Str::after((string) $variante['id'], CatalogoOutroSite::PREFIXO_VARIACAO));
        $frete = $produto ? rescue(fn () => app(SiteAtual::class)->durante(SiteAtual::FERRAKORA, fn () => round($produto->freteEmbutido(), 2)), null, false) : null;

        return ['preco_visto' => round($centavos / 100, 2), 'frete_visto' => $frete];
    }

    /** @return array{preco_visto: null, frete_visto: null} */
    private static function nada(): array
    {
        return ['preco_visto' => null, 'frete_visto' => null];
    }
}
