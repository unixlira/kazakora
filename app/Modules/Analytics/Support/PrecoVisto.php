<?php

namespace App\Modules\Analytics\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\FerraKora\Services\CatalogoFerraKora;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Preço que a página do produto mostra ao abrir, gravado na visita
 * (site_visits.preco_visto, pedido do Lira 2026-11-12). Não refaz conta de
 * preço: lê o mesmo valor que vai para a tela e só repete a escolha de qual
 * variação aparece antes de o cliente clicar em alguma.
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
    /** Atributo da requisição: a página do produto deixa o preço para o TrackSiteVisit gravar. */
    public const ATRIBUTO = 'preco_visto';

    /** @param  Collection<int, Product>  $variacoes  as outras variações do grupo, como vão para a página */
    public static function daLoja(Product $produto, Collection $variacoes): ?float
    {
        $opcoes = $variacoes->isEmpty() ? collect([$produto]) : collect([$produto])->concat($variacoes);
        $comEstoque = $variacoes->isEmpty() ? collect() : $opcoes->filter(fn (Product $p) => (int) $p->stock > 0);

        $preco = ($comEstoque->isNotEmpty() ? $comEstoque : $opcoes)->min(fn (Product $p) => (float) $p->final_price);

        return $preco !== null && $preco > 0 ? round((float) $preco, 2) : null;
    }

    public static function guardar(Request $request, Product $produto, Collection $variacoes): void
    {
        $request->attributes->set(self::ATRIBUTO, rescue(fn () => self::daLoja($produto, $variacoes), null, false));
    }

    /**
     * Página /produto/<slug> do FerraKora: o preço da API do catálogo para a
     * região deste visitante (o aviso de visita vem do navegador dele, pela
     * Cloudflare, igual à chamada do catálogo). Outra página ou produto fora
     * do site: null.
     */
    public static function doFerraKora(string $pagina): ?float
    {
        if (preg_match('#^/produto/([a-z0-9-]{1,200})/?(?:[?\#].*)?$#', $pagina, $achado) !== 1) {
            return null;
        }

        app(FreteEmbutido::class)->ligar();
        $preco = app(CatalogoFerraKora::class)->produto($achado[1])['variants'][0]['prices'][0] ?? null;
        if ($preco === null) {
            return null;
        }

        $centavos = $preco['sale_amount'] !== null && $preco['sale_amount'] < $preco['amount'] ? $preco['sale_amount'] : $preco['amount'];

        return $centavos > 0 ? round($centavos / 100, 2) : null;
    }
}
