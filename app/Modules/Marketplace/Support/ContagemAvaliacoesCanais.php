<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Marketplace\Models\ContagemAvaliacoesCanal;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\MercadoLivre\MercadoLivreClient;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Avaliações › Cadastro e controle (pedido do Lira 2026-10-18): quantas
 * avaliações reais cada produto ativo tem nos anúncios da Shopee e do
 * Mercado Livre, contra quantas aparecem no site.
 *
 * Os anúncios são os vínculos de product_channel_listings (os mesmos que a
 * clonagem e a importação de avaliações usam). A contagem é por anúncio:
 * o mesmo item da Shopee atende várias variações, então o produto soma os
 * anúncios DISTINTOS dele e das variações, sem contar o mesmo item duas vezes.
 * Fontes: Shopee get_item_extra_info (comment_count, rating_star) e ML
 * /reviews/item/{id} (paging.total, rating_average).
 */
class ContagemAvaliacoesCanais
{
    public const CANAIS = [MarketplaceAccount::CHANNEL_SHOPEE, MarketplaceAccount::CHANNEL_MERCADO_LIVRE];

    public function __construct(
        private readonly ShopeeClient $shopee,
        private readonly MercadoLivreClient $ml,
    ) {}

    /**
     * Lê a contagem de todos os anúncios vinculados e grava em
     * contagem_avaliacoes_canais.
     *
     * @return array{shopee: int, mercado_livre: int, erros: int}
     */
    public function atualizar(): array
    {
        $ids = $this->anunciosVinculados()->groupBy('channel')
            ->map(fn (Collection $g) => $g->pluck('external_id')->map(fn ($id) => (string) $id)->unique()->values());

        $resumo = ['shopee' => 0, 'mercado_livre' => 0, 'erros' => 0];

        foreach (($ids[MarketplaceAccount::CHANNEL_SHOPEE] ?? collect())->chunk(50) as $lote) {
            try {
                $itens = collect($this->shopee->get('/api/v2/product/get_item_extra_info', ['item_id_list' => $lote->implode(',')])['response']['item_list'] ?? [])
                    ->keyBy(fn ($i) => (string) $i['item_id']);
            } catch (Throwable $e) {
                $lote->each(fn ($id) => $this->gravarErro(MarketplaceAccount::CHANNEL_SHOPEE, $id, $e));
                $resumo['erros'] += $lote->count();

                continue;
            }

            foreach ($lote as $id) {
                $item = $itens->get($id);
                if (! $item) {
                    $this->gravarErro(MarketplaceAccount::CHANNEL_SHOPEE, $id, 'Anúncio não encontrado na Shopee');
                    $resumo['erros']++;

                    continue;
                }
                $this->gravar(MarketplaceAccount::CHANNEL_SHOPEE, $id, (int) ($item['comment_count'] ?? 0), (float) ($item['rating_star'] ?? 0));
                $resumo['shopee']++;
            }
        }

        foreach ($ids[MarketplaceAccount::CHANNEL_MERCADO_LIVRE] ?? [] as $id) {
            try {
                $r = $this->ml->get("/reviews/item/{$id}", ['limit' => 1]);
                $this->gravar(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, $id, (int) ($r['paging']['total'] ?? 0), (float) ($r['rating_average'] ?? 0));
                $resumo['mercado_livre']++;
            } catch (Throwable $e) {
                $this->gravarErro(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, $id, $e);
                $resumo['erros']++;
            }
        }

        return $resumo;
    }

    /**
     * Uma linha por produto ativo (produto pai, variações somadas).
     *
     * @return list<array<string, mixed>>
     */
    public function porProduto(): array
    {
        $produtos = Product::query()
            ->whereNull('parent_product_id')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'slug']);

        $familia = Product::query()
            ->whereIn('parent_product_id', $produtos->pluck('id'))
            ->pluck('parent_product_id', 'id')
            ->all();
        $paiDe = fn (int $id) => $familia[$id] ?? $id;

        $anuncios = $this->anunciosVinculados()->groupBy(fn ($l) => $paiDe((int) $l->product_id));
        $contagens = ContagemAvaliacoesCanal::query()->get()->keyBy(fn ($c) => "{$c->channel}:{$c->external_id}");

        $noSite = Review::query()
            ->selectRaw('product_id, channel, count(*) as total')
            ->groupBy('product_id', 'channel')
            ->get()
            ->groupBy(fn ($r) => $paiDe((int) $r->product_id));

        return $produtos->map(function (Product $p) use ($anuncios, $contagens, $noSite) {
            $linha = ['id' => $p->id, 'nome' => $p->name, 'sku' => $p->sku, 'slug' => $p->slug];

            foreach (self::CANAIS as $canal) {
                $doCanal = ($anuncios[$p->id] ?? collect())->where('channel', $canal)->pluck('external_id')->map(fn ($id) => (string) $id)->unique();
                $lidos = $doCanal->map(fn ($id) => $contagens->get("{$canal}:{$id}"))->filter(fn ($c) => $c && $c->conferido_em);
                $total = $lidos->sum('total');
                $linha[$canal] = [
                    'anuncios' => $doCanal->values()->all(),
                    'total' => $doCanal->isEmpty() || $lidos->isEmpty() ? null : $total,
                    'nota' => $total > 0 ? round($lidos->sum(fn ($c) => $c->total * (float) $c->nota_media) / $total, 1) : null,
                    'sem_leitura' => $doCanal->count() - $lidos->count(),
                ];
            }

            $site = ($noSite[$p->id] ?? collect())->groupBy(fn ($r) => $r->channel ?? 'site')->map(fn ($g) => (int) $g->sum('total'));
            $linha['site'] = [
                'total' => (int) $site->sum(),
                'shopee' => $site['shopee'] ?? 0,
                'mercado_livre' => $site['mercado_livre'] ?? 0,
                'manual' => $site[Review::CHANNEL_MANUAL] ?? 0,
                'clientes' => $site['site'] ?? 0,
                'outros' => (int) $site->except(['shopee', 'mercado_livre', Review::CHANNEL_MANUAL, 'site'])->sum(),
            ];

            return $linha;
        })->values()->all();
    }

    public function ultimaLeitura(): ?string
    {
        return ContagemAvaliacoesCanal::query()->max('conferido_em');
    }

    private function anunciosVinculados(): Collection
    {
        return ProductChannelListing::query()
            ->whereIn('channel', self::CANAIS)
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '')
            ->get(['product_id', 'channel', 'external_id']);
    }

    private function gravar(string $canal, string $id, int $total, float $nota): void
    {
        ContagemAvaliacoesCanal::query()->updateOrCreate(
            ['channel' => $canal, 'external_id' => $id],
            ['total' => $total, 'nota_media' => $total > 0 ? round($nota, 2) : null, 'conferido_em' => now(), 'erro' => null],
        );
    }

    /** Erro não apaga a última contagem boa: só registra o motivo. */
    private function gravarErro(string $canal, string $id, Throwable|string $erro): void
    {
        ContagemAvaliacoesCanal::query()->updateOrCreate(
            ['channel' => $canal, 'external_id' => $id],
            ['erro' => Str::limit(is_string($erro) ? $erro : $erro->getMessage(), 250)],
        );
    }
}
