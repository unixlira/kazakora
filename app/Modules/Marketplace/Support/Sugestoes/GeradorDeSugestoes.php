<?php

namespace App\Modules\Marketplace\Support\Sugestoes;

use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MetaAdsVideo;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Models\Sugestao;
use App\Modules\Marketplace\Models\SugestaoRodada;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Services\Meta\MetaAdsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rotina das Sugestões do dia (pedido do Lira 2026-10-20: em vez de um botão
 * por criativo, uma rotina que roda toda noite). Comando sugestoes:gerar,
 * agendado de madrugada (routes/console.php):
 *
 * 1. Lê o funil de cada anúncio do Meta Ads nos últimos 7 dias.
 * 2. Interpreta (DiagnosticoMetaAds): criativo por anúncio; página,
 *    checkout e lucro por produto, com as visitas contadas pelo site.
 * 3. Pesquisa preço de concorrente (PesquisaDePrecos) dos produtos com
 *    anúncio e dos mais vendidos.
 * 4. Cruza tudo (RegrasDePreco + diagnóstico) e grava o board do dia.
 *
 * Nada aqui muda preço, anúncio ou orçamento: só recomenda. Rodar de novo
 * no mesmo dia refaz as abertas e respeita o que já foi marcado; sugestão
 * ignorada não volta por Sugestao::DIAS_SILENCIO dias.
 */
class GeradorDeSugestoes
{
    /** Custos do canal sem o ADS (taxa, imposto, frete) quando o produto ainda não vendeu no site. */
    public const CUSTOS_VARIAVEIS_ESTIMADOS = 0.29;

    public const DIAS_MARGEM = 60;

    public function __construct(
        private readonly MetaAdsService $meta,
        private readonly PesquisaDePrecos $precos,
        private readonly ContributionMargin $margem,
        private readonly DiagnosticoMetaAds $diagnostico,
        private readonly RegrasDePreco $regras,
    ) {}

    public static function maxProdutos(): int
    {
        return (int) config('loja.sugestoes_max_produtos', 25);
    }

    public function gerar(?Carbon $dia = null, bool $comPrecos = true): SugestaoRodada
    {
        $dia = ($dia ?? Carbon::today())->copy()->startOfDay();
        $rodada = SugestaoRodada::query()->create(['dia' => $dia->toDateString(), 'iniciada_em' => now()]);
        $fontes = [];

        try {
            [$anuncios, $fontes['meta']] = $this->anuncios();
            $produtos = $this->produtosParaAnalisar($anuncios, $dia);

            $ofertas = [];
            if ($comPrecos) {
                foreach ($produtos as $produto) {
                    $ofertas[$produto->id] = $this->precos->pesquisar($produto);
                }
                $fontes += $this->precos->fontes();
            }

            $sugestoes = array_merge(
                $this->sugestoesDoMeta($anuncios, $produtos, $ofertas, $dia),
                $this->sugestoesDePreco($produtos, $ofertas),
            );
            $total = $this->gravar($dia, $sugestoes);

            $rodada->update(['terminada_em' => now(), 'total' => $total, 'fontes' => $fontes]);
        } catch (Throwable $e) {
            Log::error('sugestoes.falhou', ['erro' => mb_substr($e->getMessage(), 0, 500)]);
            $rodada->update(['terminada_em' => now(), 'fontes' => $fontes, 'erro' => mb_substr($e->getMessage(), 0, 500)]);

            throw $e;
        }

        return $rodada->refresh();
    }

    /**
     * Anúncios com gasto nos últimos 7 dias, com o produto de cada um
     * (link do criativo; senão o cadastro do anúncio no KazaKora).
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function anuncios(): array
    {
        if (! $this->meta->configurado()) {
            return [[], ['ok' => false, 'consultas' => 0, 'ofertas' => 0, 'erro' => 'Meta Ads não configurado neste servidor.']];
        }

        try {
            $linhas = $this->meta->funilPorAnuncio('last_7d');
        } catch (Throwable $e) {
            return [[], ['ok' => false, 'consultas' => 1, 'ofertas' => 0, 'erro' => mb_substr($e->getMessage(), 0, 200)]];
        }

        $slugs = collect($linhas)->map(fn ($l) => self::slugDoLink($l['link'] ?? null))->filter()->unique()->values();
        $porSlug = $slugs->isEmpty() ? collect() : Product::query()->whereIn('slug', $slugs)->pluck('id', 'slug');
        $doCadastro = MetaAdsVideo::query()->with('campanha:id,produto_id')
            ->whereIn('meta_ad_id', array_column($linhas, 'ad_id'))
            ->get()
            ->mapWithKeys(fn (MetaAdsVideo $v) => [(string) $v->meta_ad_id => $v->produto_id ?? $v->campanha?->produto_id]);

        $linhas = array_map(fn (array $l) => $l + [
            'product_id' => $porSlug[self::slugDoLink($l['link'] ?? null)] ?? $doCadastro[$l['ad_id']] ?? null,
        ], $linhas);

        return [$linhas, ['ok' => true, 'consultas' => 1, 'ofertas' => count($linhas), 'erro' => null]];
    }

    public static function slugDoLink(?string $link): ?string
    {
        if (! $link || ! preg_match('#/produtos/([^/?\#]+)#', $link, $m)) {
            return null;
        }

        return urldecode($m[1]);
    }

    /**
     * Produtos com anúncio no Meta e os mais vendidos dos últimos 30 dias
     * (todos os canais), ativos, até maxProdutos().
     *
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function produtosParaAnalisar(array $anuncios, Carbon $dia)
    {
        $comAnuncio = collect($anuncios)->pluck('product_id')->filter()->unique();
        $maisVendidos = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', ContributionMargin::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $dia->copy()->subDays(30))
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->orderByRaw('SUM(order_items.quantity) DESC')
            ->limit(self::maxProdutos())
            ->pluck('order_items.product_id');

        $ids = $comAnuncio->merge($maisVendidos)->unique()->take(self::maxProdutos())->values();

        // Venda de variação vale para o produto pai (é ele que tem página e anúncio).
        return Product::query()->whereIn('id', $ids)->get()
            ->map(fn (Product $p) => $p->parent_product_id ? (Product::query()->find($p->parent_product_id) ?? $p) : $p)
            ->filter(fn (Product $p) => $p->is_active)
            ->unique('id')
            ->values();
    }

    /** @return list<array<string, mixed>> */
    private function sugestoesDoMeta(array $anuncios, $produtos, array $ofertas, Carbon $dia): array
    {
        if ($anuncios === []) {
            return [];
        }

        $sugestoes = [];
        foreach ($anuncios as $a) {
            if ($s = $this->diagnostico->doAnuncio($a, $a['product_id'])) {
                $sugestoes[] = $s;
            }
        }

        $desde = $dia->copy()->subDays(7);
        $vendas7 = collect($this->margem->porItem($desde, $dia, Order::ORIGIN_STORE)['linhas']);
        $vendas60 = collect($this->margem->porItem($dia->copy()->subDays(self::DIAS_MARGEM), $dia, Order::ORIGIN_STORE)['linhas']);

        foreach (collect($anuncios)->whereNotNull('product_id')->groupBy('product_id') as $productId => $doProduto) {
            $produto = $produtos->firstWhere('id', (int) $productId) ?? Product::query()->find($productId);
            if (! $produto) {
                continue;
            }
            $grupo = $produto->variantGroupIds();
            $preco = $produto->precoSemOferta();
            $daqui = $vendas7->whereIn('product_id', $grupo);
            [$empate, $estimada] = $this->roasEmpate($produto, $vendas60->whereIn('product_id', $grupo));
            $mais = collect($ofertas[$produto->id] ?? [])->first(fn ($o) => $o['preco'] < $preco * RegrasDePreco::BAIXAR_SE_ABAIXO);
            $visitas = SiteVisit::query()->daLoja()
                ->where('origem', 'meta_ads')
                ->where('created_at', '>=', $desde)->where('created_at', '<', $dia)
                ->where('path', 'like', '/produtos/'.$produto->slug.'%');

            $sugestoes = array_merge($sugestoes, $this->diagnostico->doProduto([
                'product_id' => (int) $produto->id,
                'nome' => (string) $produto->name,
                'preco' => $preco,
                'gasto' => (float) $doProduto->sum('gasto'),
                'cliques_link' => (int) $doProduto->sum('cliques_link'),
                'carrinhos' => (int) $doProduto->sum('carrinhos'),
                'compras' => (int) $doProduto->sum('compras'),
                'vendas_meta' => (float) $doProduto->sum('vendas'),
                'visitantes' => (clone $visitas)->distinct()->count('visitor_id'),
                'consentidos' => (clone $visitas)->where('consentiu', true)->distinct()->count('visitor_id'),
                'vendas_site' => (int) $daqui->sum('quantidade'),
                'receita_site' => (float) $daqui->sum('receita'),
                'roas_empate' => $empate,
                'margem_estimada' => $estimada,
                'checagens' => $this->checagensDaPagina($produto),
                'mais_barato' => $mais,
            ]));
        }

        return $sugestoes;
    }

    /**
     * ROAS de empate = 1 ÷ margem antes do ADS (pela ContributionMargin das
     * vendas do produto no site nos últimos 60 dias). Sem venda, estima com
     * o custo do cadastro e CUSTOS_VARIAVEIS_ESTIMADOS. null sem custo.
     *
     * @return array{0: ?float, 1: bool}
     */
    private function roasEmpate(Product $produto, $linhas): array
    {
        $receita = (float) $linhas->sum('receita');
        if ($receita > 0 && $linhas->every(fn ($l) => $l['tem_custo'])) {
            $margem = (float) $linhas->sum('margem') / $receita;

            return [$margem > 0 ? round(1 / $margem, 2) : 0.0, false];
        }

        $preco = $produto->precoSemOferta();
        $custo = (float) ($produto->cost_price ?? 0);
        if ($custo <= 0 || $preco <= 0) {
            return [null, true];
        }
        $margem = 1 - $custo / $preco - self::CUSTOS_VARIAVEIS_ESTIMADOS;

        return [$margem > 0 ? round(1 / $margem, 2) : 0.0, true];
    }

    /**
     * O que dá para conferir na página sem IA, só com o banco: fotos de
     * outro produto (caso Caixa 168 com a galeria do kit de 85 peças),
     * nenhuma avaliação, estoque baixo, descrição curta.
     *
     * @return list<string>
     */
    private function checagensDaPagina(Product $produto): array
    {
        $checagens = [];
        $grupo = $produto->variantGroupIds();
        $caminhos = DB::table('product_images')->where('product_id', $produto->id)->pluck('path');
        if ($caminhos->isNotEmpty()) {
            $outro = DB::table('product_images')
                ->join('products', 'products.id', '=', 'product_images.product_id')
                ->whereIn('product_images.path', $caminhos)
                ->whereNotIn('product_images.product_id', $grupo)
                ->value('products.name');
            if ($outro) {
                $checagens[] = 'a galeria tem fotos do produto "'.$outro.'"';
            }
        }
        if (! $produto->reviews()->exists()) {
            $checagens[] = 'a página não tem nenhuma avaliação';
        }
        $estoque = (int) Product::query()->whereIn('id', $grupo)->sum('stock');
        if ($estoque <= 3) {
            $checagens[] = 'estoque baixo ('.$estoque.')';
        }
        if (mb_strlen(trim(strip_tags((string) $produto->description))) < 300) {
            $checagens[] = 'a descrição está curta';
        }

        return $checagens;
    }

    /** @return list<array<string, mixed>> */
    private function sugestoesDePreco($produtos, array $ofertas): array
    {
        $shopee = ProductChannelListing::query()
            ->whereIn('product_id', $produtos->pluck('id'))
            ->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)
            ->where('is_enabled', true)
            ->get()
            ->keyBy('product_id');

        $sugestoes = [];
        foreach ($produtos as $produto) {
            $listagem = $shopee->get($produto->id);
            $sugestoes = array_merge($sugestoes, $this->regras->avaliar(
                $produto,
                $ofertas[$produto->id] ?? [],
                $listagem?->precoDeVenda($produto),
            ));
        }

        return $sugestoes;
    }

    /**
     * Mesma chave: fica a mais urgente. Abertas do dia são refeitas; marcada
     * hoje fica como está; ignorada nos últimos dias não volta.
     */
    private function gravar(Carbon $dia, array $sugestoes): int
    {
        $porChave = [];
        foreach ($sugestoes as $s) {
            $atual = $porChave[$s['chave']] ?? null;
            if ($atual === null || $s['prioridade'] < $atual['prioridade']) {
                $porChave[$s['chave']] = $s;
            }
        }

        return DB::transaction(function () use ($dia, $porChave) {
            Sugestao::query()->whereDate('dia', $dia)->where('status', Sugestao::STATUS_ABERTA)->delete();
            $marcadasHoje = Sugestao::query()->whereDate('dia', $dia)->pluck('chave')->flip();
            $silenciadas = Sugestao::query()
                ->where('status', Sugestao::STATUS_IGNORADA)
                ->where('resolvida_em', '>=', now()->subDays(Sugestao::DIAS_SILENCIO))
                ->pluck('chave')->flip();

            $total = 0;
            foreach ($porChave as $chave => $s) {
                if ($marcadasHoje->has($chave) || $silenciadas->has($chave)) {
                    continue;
                }
                $dados = $s['dados'] ?? [];
                if (isset($s['valor'])) {
                    $dados['valor_sugerido'] = $s['valor'];
                }
                Sugestao::query()->create([
                    'dia' => $dia->toDateString(),
                    'chave' => $chave,
                    'area' => $s['area'],
                    'tipo' => $s['tipo'],
                    'prioridade' => $s['prioridade'],
                    'titulo' => mb_substr($s['titulo'], 0, 255),
                    'acao' => mb_substr($s['acao'], 0, 500),
                    'motivo' => $s['motivo'] ?? null,
                    'dados' => $dados,
                    'product_id' => $s['product_id'] ?? null,
                    'meta_ad_id' => $s['meta_ad_id'] ?? null,
                ]);
                $total++;
            }

            return $total;
        });
    }
}
