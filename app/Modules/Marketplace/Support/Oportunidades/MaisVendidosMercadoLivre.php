<?php

namespace App\Modules\Marketplace\Support\Oportunidades;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Oportunidades no Mercado Livre (correção do Lira 2026-10-12): produtos de
 * TERCEIROS que mais vendem e buscas em alta, não as vendas da KazaKora.
 *
 * Fontes oficiais da API do ML:
 * - /highlights/MLB/category/{id}: ranking "Mais vendidos" da categoria.
 *   Só as entradas do tipo PRODUCT (produto de catálogo) têm detalhe
 *   liberado; anúncio avulso (ITEM/USER_PRODUCT) de outro vendedor volta 403
 *   desde 2025, por isso entra só na contagem de "ocultos".
 * - /products/{id}/items: vendedores do produto (concorrência e menor preço).
 * - /trends/MLB/{id}: buscas que mais crescem na categoria.
 *
 * Resultado em cache (6 h) porque são ~40 chamadas por categoria.
 *
 * Concorrência e margem (pedido do Lira 2026-10-13): o radar passa a puxar
 * para cima os produtos com POUCA concorrência, para fugir de guerra de
 * preço. Nenhuma chamada nova à API: tudo sai das ofertas que
 * /products/{id}/items já devolve. Regras (ver classificar()):
 * - preco_mediana: mediana dos preços das ofertas do catálogo (até 50).
 * - concorrencia: 'baixa' de 1 a 3 vendedores, 'media' de 4 a 10, 'alta'
 *   acima de 10; null sem dado.
 * - guerra_preco: o menor preço está abaixo de 80% da mediana (alguém já
 *   está derrubando o preço).
 * - custo_maximo = mediana × 0,56: regra da casa no ML (piso de preço =
 *   custo ÷ 0,56 dá 15% de margem de contribuição), ou seja, o máximo a
 *   pagar no fornecedor vendendo pela mediana e ainda ter 15%.
 * - custo / margem_estimada: só quando a KazaKora tem anúncio NESTE produto
 *   de catálogo (oferta com o nosso seller_id) ligado a um produto nosso com
 *   cost_price (product_channel_listings.external_id = item_id da oferta).
 *   margem_estimada = (mediana × 0,56 − custo) / mediana + 0,15. Nada de
 *   casar por nome parecido: sem vínculo exato fica só o custo_maximo.
 * - oportunidade_nicho: concorrência baixa e sem guerra de preço.
 * - Ordem: nicho, depois média sem guerra, depois o resto; dentro de cada
 *   grupo pela posição no ranking do ML (ordenar()).
 */
class MaisVendidosMercadoLivre
{
    public const SITE = 'MLB';

    /** Quantos produtos do ranking detalhar. */
    public const LIMITE = 20;

    private const CACHE_HORAS = 6;

    /** Regra da casa no ML: preço mínimo = custo ÷ 0,56 dá 15% de contribuição. */
    public const FATOR_CUSTO_ML = 0.56;

    public const MARGEM_MINIMA = 0.15;

    /** Menor preço abaixo de 80% da mediana = guerra de preço. */
    public const LIMITE_GUERRA = 0.80;

    public function __construct(private readonly MercadoLivreClient $ml) {}

    /** @return list<array{id: string, nome: string}> Categorias de primeiro nível. */
    public function categorias(): array
    {
        try {
            return Cache::remember('oportunidades:ml:categorias', now()->addDay(), fn () => array_values(array_map(
                fn (array $c) => ['id' => (string) $c['id'], 'nome' => (string) $c['name']],
                $this->ml->get('sites/'.self::SITE.'/categories'),
            )));
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Buscas que mais crescem no Mercado Livre inteiro (/trends/MLB), sem
     * categoria. Usado pelo Radar de Oportunidades (2026-10-12). 1 chamada.
     */
    public function tendenciasGerais(bool $atualizar = false): array
    {
        $chave = 'oportunidades:ml:tendencias:'.self::SITE;
        if ($atualizar) {
            Cache::forget($chave);
        }

        try {
            return Cache::remember($chave, now()->addHours(self::CACHE_HORAS), fn () => [
                'tendencias' => array_values(array_map(
                    fn (array $t) => ['termo' => (string) $t['keyword'], 'link' => (string) ($t['url'] ?? '')],
                    array_slice($this->ml->get('trends/'.self::SITE), 0, 30),
                )),
                'atualizado_em' => now()->toIso8601String(),
                'erro' => null,
            ]);
        } catch (Throwable $e) {
            report($e);

            return ['tendencias' => [], 'atualizado_em' => null,
                'erro' => 'Não foi possível consultar as buscas em alta do Mercado Livre agora.'];
        }
    }

    public function analisar(string $categoria, bool $atualizar = false): array
    {
        // v2 (2026-10-13): campos de concorrência e margem; não reaproveita o cache antigo.
        $chave = "oportunidades:ml:v2:{$categoria}";
        if ($atualizar) {
            Cache::forget($chave);
        }

        try {
            return Cache::remember($chave, now()->addHours(self::CACHE_HORAS), fn () => $this->coletar($categoria));
        } catch (Throwable $e) {
            report($e);

            return ['produtos' => [], 'tendencias' => [], 'ocultos' => 0, 'atualizado_em' => null,
                'erro' => 'Não foi possível consultar o Mercado Livre agora. Tente de novo em alguns minutos.'];
        }
    }

    private function coletar(string $categoria): array
    {
        $nosso = (string) MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)->value('seller_id');
        $ranking = $this->ml->get('highlights/'.self::SITE."/category/{$categoria}")['content'] ?? [];

        $produtos = [];
        $ocultos = 0;
        foreach ($ranking as $entrada) {
            if (($entrada['type'] ?? null) !== 'PRODUCT') {
                $ocultos++;

                continue;
            }
            if (count($produtos) >= self::LIMITE) {
                continue;
            }
            $linha = $this->produto((string) $entrada['id'], (int) ($entrada['position'] ?? 0), $nosso);
            if ($linha) {
                $produtos[] = $linha;
            }
        }

        try {
            $tendencias = array_values(array_map(
                fn (array $t) => ['termo' => (string) $t['keyword'], 'link' => (string) ($t['url'] ?? '')],
                array_slice($this->ml->get('trends/'.self::SITE."/{$categoria}"), 0, 20),
            ));
        } catch (Throwable $e) {
            report($e);
            $tendencias = [];
        }

        // Uma consulta só ao banco para o custo de todos os nossos anúncios.
        $custos = self::custosPorAnuncio(array_merge([], ...array_column($produtos, 'nossos_anuncios')));
        $produtos = array_map(function (array $p) use ($custos) {
            $custo = null;
            foreach ($p['nossos_anuncios'] as $anuncio) {
                $custo ??= $custos[$anuncio] ?? null;
            }
            $precos = $p['precos'];
            unset($p['nossos_anuncios'], $p['precos']);

            return self::classificar($p, $precos, $custo);
        }, $produtos);

        return [
            'produtos' => self::ordenar($produtos),
            'tendencias' => $tendencias,
            'ocultos' => $ocultos,
            'atualizado_em' => now()->toIso8601String(),
            'erro' => null,
        ];
    }

    private function produto(string $id, int $posicao, string $nosso): ?array
    {
        try {
            $produto = $this->ml->get("products/{$id}");
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $precos = [];
        $vendedores = null;
        $voceVende = false;
        $nossosAnuncios = [];
        try {
            $ofertas = $this->ml->get("products/{$id}/items", ['limit' => 50]);
            $vendedores = (int) ($ofertas['paging']['total'] ?? 0);
            foreach ($ofertas['results'] ?? [] as $oferta) {
                if (isset($oferta['price'])) {
                    $precos[] = (float) $oferta['price'];
                }
                if ($nosso !== '' && (string) ($oferta['seller_id'] ?? '') === $nosso) {
                    $voceVende = true;
                    if (! empty($oferta['item_id'])) {
                        $nossosAnuncios[] = (string) $oferta['item_id'];
                    }
                }
            }
        } catch (Throwable $e) {
            // Produto sem vendedor ativo também volta erro: segue sem preço.
        }

        return [
            'id' => $id,
            'posicao' => $posicao,
            'nome' => (string) ($produto['name'] ?? $id),
            'imagem' => $produto['pictures'][0]['url'] ?? null,
            'link' => ($produto['permalink'] ?? '') ?: "https://www.mercadolivre.com.br/p/{$id}",
            'preco_min' => $precos ? min($precos) : null,
            'preco_max' => $precos ? max($precos) : null,
            'vendedores' => $vendedores,
            'voce_vende' => $voceVende,
            // Internos: saem da linha em coletar(), depois de classificar().
            'precos' => $precos,
            'nossos_anuncios' => $nossosAnuncios,
        ];
    }

    /**
     * Acrescenta à linha as regras de concorrência e margem (Lira
     * 2026-10-13, ver o comentário da classe). $custo só com vínculo exato.
     */
    public static function classificar(array $linha, array $precos, ?float $custo = null): array
    {
        $mediana = self::mediana($precos);
        $vendedores = $linha['vendedores'] ?? null;
        $concorrencia = match (true) {
            $vendedores === null || $vendedores < 1 => null,
            $vendedores <= 3 => 'baixa',
            $vendedores <= 10 => 'media',
            default => 'alta',
        };
        $guerra = $mediana !== null && $mediana > 0 && min($precos) < $mediana * self::LIMITE_GUERRA;
        $custo = $custo !== null && $custo > 0 ? round($custo, 2) : null;

        return $linha + [
            'preco_mediana' => $mediana,
            'concorrencia' => $concorrencia,
            'guerra_preco' => $guerra,
            'custo_maximo' => $mediana !== null ? round($mediana * self::FATOR_CUSTO_ML, 2) : null,
            'custo' => $mediana !== null ? $custo : null,
            'margem_estimada' => $mediana !== null && $mediana > 0 && $custo !== null
                ? round(($mediana * self::FATOR_CUSTO_ML - $custo) / $mediana + self::MARGEM_MINIMA, 4)
                : null,
            'oportunidade_nicho' => $concorrencia === 'baixa' && ! $guerra,
        ];
    }

    /** Nicho primeiro, depois média sem guerra, depois o resto; empate pela posição do ranking. */
    public static function ordenar(array $produtos): array
    {
        $grupo = fn (array $p) => match (true) {
            (bool) ($p['oportunidade_nicho'] ?? false) => 0,
            ($p['concorrencia'] ?? null) === 'media' && ! ($p['guerra_preco'] ?? false) => 1,
            default => 2,
        };
        usort($produtos, fn (array $a, array $b) => [$grupo($a), $a['posicao'] ?? PHP_INT_MAX] <=> [$grupo($b), $b['posicao'] ?? PHP_INT_MAX]);

        return $produtos;
    }

    public const NIVEIS_CONCORRENCIA = ['baixa', 'media', 'alta'];

    /**
     * Filtro de concorrência da tela (pedido do Lira 2026-10-13): só os
     * produtos do nível escolhido, do menos para o mais disputado (menos
     * vendedores primeiro); empate pela posição do ranking. Nível vazio ou
     * desconhecido devolve a lista como veio.
     */
    public static function filtrarConcorrencia(array $produtos, ?string $nivel): array
    {
        if (! in_array($nivel, self::NIVEIS_CONCORRENCIA, true)) {
            return $produtos;
        }

        $produtos = array_values(array_filter($produtos, fn (array $p) => ($p['concorrencia'] ?? null) === $nivel));
        usort($produtos, fn (array $a, array $b) => [$a['vendedores'] ?? PHP_INT_MAX, $a['posicao'] ?? PHP_INT_MAX] <=> [$b['vendedores'] ?? PHP_INT_MAX, $b['posicao'] ?? PHP_INT_MAX]);

        return $produtos;
    }

    /** @param list<float> $precos */
    private static function mediana(array $precos): ?float
    {
        if ($precos === []) {
            return null;
        }
        sort($precos);
        $meio = intdiv(count($precos), 2);

        return round(count($precos) % 2 ? $precos[$meio] : ($precos[$meio - 1] + $precos[$meio]) / 2, 2);
    }

    /**
     * Custo de compra dos nossos anúncios do ML, pelo item_id (vínculo exato
     * em product_channel_listings). Anúncio com variações de custos
     * diferentes fica de fora: não dá para saber qual variação é a do catálogo.
     *
     * @param  list<string>  $anuncios
     * @return array<string, float>
     */
    private static function custosPorAnuncio(array $anuncios): array
    {
        if ($anuncios === []) {
            return [];
        }

        $custos = [];
        ProductChannelListing::query()
            ->with('product:id,cost_price')
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->whereIn('external_id', array_values(array_unique($anuncios)))
            ->get(['id', 'product_id', 'external_id'])
            ->groupBy('external_id')
            ->each(function ($listagens, $anuncio) use (&$custos) {
                $valores = $listagens->map(fn ($l) => (float) ($l->product?->cost_price ?? 0))->filter(fn ($c) => $c > 0)->unique();
                if ($valores->count() === 1 && $listagens->count() === $listagens->filter(fn ($l) => (float) ($l->product?->cost_price ?? 0) > 0)->count()) {
                    $custos[(string) $anuncio] = (float) $valores->first();
                }
            });

        return $custos;
    }
}
