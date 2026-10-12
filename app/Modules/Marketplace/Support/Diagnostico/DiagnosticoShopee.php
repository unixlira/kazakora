<?php

namespace App\Modules\Marketplace\Support\Diagnostico;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Cauê (Shopee) olhando anúncio por anúncio (pedido 2026-10-11): o que
 * ajustar para vender mais, com o número que disparou cada alerta. Regras
 * da skill caue-shopee-manager: ROAS de empate/meta pela margem real, CTR
 * abaixo de 2% = capa, conversão por faixa, ADS que gasta sem vender.
 */
class DiagnosticoShopee
{
    private const DIAS = 30;

    public function __construct(
        private readonly ShopeeClient $shopee,
        private readonly EconomiaDosAnuncios $economia,
    ) {}

    public function gerar(): array
    {
        $desde = Carbon::today()->subDays(self::DIAS - 1);
        $vendas = $this->economia->porAnuncio('shopee', $desde);
        $taxaMedia = $this->economia->taxaMedia('shopee', $desde);

        $anuncios = ProductChannelListing::query()
            ->where('channel', 'shopee')
            ->whereNotNull('external_id')
            ->whereIn('status', ['published', 'active'])
            ->get(['product_id', 'external_id']);
        $ids = $anuncios->pluck('external_id')->map(fn ($id) => (string) $id)->merge(array_keys($vendas))->unique()->values();
        $custos = Product::query()->whereIn('id', $anuncios->pluck('product_id'))->pluck('cost_price', 'id');
        $custoPorItem = $anuncios->groupBy('external_id')->map(fn ($g) => $g->map(fn ($l) => $custos[$l->product_id] ?? null)->filter()->avg());
        $estoques = Product::query()->whereIn('id', $anuncios->pluck('product_id'))->pluck('stock', 'id');
        $estoquePorItem = $anuncios->groupBy('external_id')->map(fn ($g) => $g->sum(fn ($l) => (int) ($estoques[$l->product_id] ?? 0)));

        $base = $this->emLotes($ids, 50, fn ($lote) => $this->shopee->get('/api/v2/product/get_item_base_info', ['item_id_list' => $lote->implode(',')])['response']['item_list'] ?? []);
        $extra = $this->emLotes($ids, 50, fn ($lote) => $this->shopee->get('/api/v2/product/get_item_extra_info', ['item_id_list' => $lote->implode(',')])['response']['item_list'] ?? []);
        $base = collect($base)->keyBy(fn ($i) => (string) $i['item_id']);
        $extra = collect($extra)->keyBy(fn ($i) => (string) $i['item_id']);
        $ads = $this->adsPorItem($desde);

        $alertas = [];
        $metasPonderadas = [];
        foreach ($ids as $id) {
            $b = $base->get($id);
            if (! $b) {
                continue;
            }
            $status = $b['item_status'] ?? '';
            // Apagado pelo vendedor (duplicado, produto que saiu): não é problema.
            if (in_array($status, ['DELETED', 'SELLER_DELETE'], true)) {
                continue;
            }
            $e = $extra->get($id, []);
            $preco = (float) ($b['price_info'][0]['current_price'] ?? 0);
            $eco = $vendas[$id] ?? $this->economia->estimar($preco, $custoPorItem[$id] ?? null, $taxaMedia);
            $ad = $ads[$id] ?? null;
            $produto = [
                'item_id' => $id,
                'nome' => (string) ($b['item_name'] ?? ''),
                'imagem' => $b['image']['image_url_list'][0] ?? null,
                'link' => "https://seller.shopee.com.br/portal/product/{$id}",
                'preco' => $preco,
                'vendas_30d' => $eco['unidades'] ?? 0,
                'receita_30d' => $eco['receita'] ?? 0,
                'roas_empate' => $eco['roas_empate'],
                'roas_meta' => $eco['roas_meta'],
                'margem' => $eco['margem'],
            ];
            if (($eco['receita'] ?? 0) > 0 && $eco['roas_meta'] && $eco['roas_meta'] <= 25) {
                $metasPonderadas[] = [$eco['roas_meta'], $eco['receita']];
            }

            foreach ($this->regras($b, $e, $eco, $ad, $status, (int) ($estoquePorItem[$id] ?? 0)) as $alerta) {
                $alertas[] = $alerta + ['produto' => $produto];
            }
        }

        $gasto = array_sum(array_column($ads, 'gasto'));
        $gmvAds = array_sum(array_column($ads, 'gmv'));

        return [
            'canal' => 'shopee',
            'especialista' => 'Cauê',
            'gerado_em' => now()->toIso8601String(),
            'dias' => self::DIAS,
            'kpis' => [
                'pedidos' => array_sum(array_column($vendas, 'pedidos')),
                'faturamento' => round(array_sum(array_column($vendas, 'receita')), 2),
                'ads_gasto' => round($gasto, 2),
                'ads_roas' => $gasto > 0 ? round($gmvAds / $gasto, 1) : null,
                'roas_meta_loja' => self::mediaPonderada($metasPonderadas),
                'anuncios' => $base->count(),
                'em_ads' => count(array_filter($ads, fn ($a) => $a['ativo'])),
            ],
            'alertas' => Prioridade::ordenar($alertas),
        ];
    }

    /** @return list<array{prioridade: string, tipo: string, texto: string, numero: ?string}> */
    private function regras(array $b, array $e, array $eco, ?array $ad, string $status, int $estoque): array
    {
        $r = [];
        $fotos = count($b['image']['image_url_list'] ?? []);
        $video = ! empty($b['video_info']);
        $views = (int) ($e['views'] ?? 0);
        $vendidos = (int) ($e['sale'] ?? 0);
        $conversao = $views > 0 ? $vendidos / $views * 100 : null;
        $nota = (float) ($e['rating_star'] ?? 0);
        $avaliacoes = (int) ($e['comment_count'] ?? 0);
        $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
        $um = fn ($v) => number_format((float) $v, 1, ',', '.');
        // Meta acima de 25 não é praticável: aí a margem é que está errada.
        $metaPraticavel = $eco['roas_meta'] && $eco['roas_meta'] <= 25 ? $eco['roas_meta'] : null;

        if ($status === 'BANNED') {
            $r[] = Prioridade::alerta('P0', 'status', 'Anúncio banido pela Shopee. Veja o motivo no Seller Center e corrija para voltar a vender.');
        } elseif ($status === 'UNLIST' && $estoque > 0) {
            $r[] = Prioridade::alerta('P0', 'status', "Anúncio fora da lista com {$estoque} em estoque. Reative para voltar a vender.");
        } elseif ($status === 'REVIEWING') {
            $r[] = Prioridade::alerta('P1', 'status', 'Anúncio em análise pela Shopee: confira se falta algum dado.');
        }
        if (($eco['sem_custo'] ?? false)) {
            $r[] = Prioridade::alerta('P1', 'custo', 'Produto sem custo cadastrado: não dá para saber o lucro nem a meta de ROAS. Cadastre o custo.');
        } elseif ($eco['lucro_unidade'] <= 0) {
            $minimo = $eco['preco_minimo'] ? " Para sobrar 15%, o preço precisa ser pelo menos R$ {$fmt($eco['preco_minimo'])}." : '';
            $quem = ($eco['estimado'] ?? false) ? 'No preço atual, cada venda daria prejuízo antes do ADS (estimado com a taxa média do canal):' : 'Vende com prejuízo antes do ADS:';
            $r[] = Prioridade::alerta('P0', 'preco', "{$quem} R$ {$fmt($eco['lucro_unidade'])} por unidade.{$minimo}");
        } elseif (! $eco['roas_meta'] && $eco['preco_minimo']) {
            $r[] = Prioridade::alerta('P1', 'preco', "Margem de {$um($eco['margem'])}% não aguenta ADS com 15% de lucro. Preço mínimo para isso: R$ {$fmt($eco['preco_minimo'])}.");
        }

        if ($ad) {
            if ($ad['gasto'] >= 20 && $ad['pedidos'] === 0) {
                $r[] = Prioridade::alerta('P0', 'ads', "ADS gastou R$ {$fmt($ad['gasto'])} em 30 dias sem nenhuma venda. Pause ou troque capa e preço antes de continuar.");
            } elseif ($ad['gasto'] >= 10 && $eco['roas_empate'] && $ad['roas'] < $eco['roas_empate'] && ! $metaPraticavel) {
                $minimo = $eco['preco_minimo'] ? " Preço mínimo para aguentar ADS com lucro: R$ {$fmt($eco['preco_minimo'])}." : '';
                $r[] = Prioridade::alerta('P0', 'ads', "ADS dando prejuízo (ROAS {$um($ad['roas'])}, empate {$um($eco['roas_empate'])}) e a margem é fina demais para ADS. Pause o ADS deste produto.{$minimo}");
            } elseif ($ad['gasto'] >= 10 && $eco['roas_empate'] && $ad['roas'] < $eco['roas_empate']) {
                $meta = $um($metaPraticavel);
                $r[] = Prioridade::alerta('P0', 'ads', "ADS dando prejuízo: ROAS real {$um($ad['roas'])} e o empate é {$um($eco['roas_empate'])}. Suba a meta para {$meta} ou pause.");
            } elseif ($ad['ativo'] && $metaPraticavel && $ad['meta'] > 0 && $ad['meta'] < $metaPraticavel) {
                $r[] = Prioridade::alerta('P1', 'ads', "Meta de ROAS configurada ({$um($ad['meta'])}) abaixo do mínimo para 15% de lucro ({$um($metaPraticavel)}). Ajuste a meta.");
            }
            if ($ad['impressoes'] >= 500 && $ad['ctr'] < 2) {
                $r[] = Prioridade::alerta('P1', 'capa', "Capa fraca: só {$um($ad['ctr'])}% de quem vê clica (bom é 2%+). Troque a foto de capa.");
            }
        }

        if ($conversao !== null && $views >= 100 && $conversao < 1) {
            $r[] = Prioridade::alerta('P1', 'conversao', 'Muita visita e pouca venda: conversão de '.number_format($conversao, 1, ',', '.')."% ({$views} visitas). Revise preço, fotos, descrição e frete.");
        }
        if ($fotos < 7) {
            $r[] = Prioridade::alerta('P2', 'fotos', "Só {$fotos} fotos. Complete até 9 (uso, medidas, detalhes): anúncio completo converte mais.");
        }
        if (! $video) {
            $r[] = Prioridade::alerta('P2', 'video', 'Sem vídeo. Vídeo curto mostrando o produto aumenta a confiança e a conversão.');
        }
        if ($avaliacoes >= 5 && $nota > 0 && $nota < 4.5) {
            $r[] = Prioridade::alerta('P1', 'avaliacao', "Nota {$um($nota)} em {$avaliacoes} avaliações. Abaixo de 4,5 derruba a conversão: veja as reclamações.");
        }

        // Oportunidade (regras do Cauê): converte bem e a margem aguenta ADS.
        $semAds = ! $ad || ! $ad['ativo'];
        if ($semAds && $conversao !== null && $views >= 50 && $metaPraticavel && $eco['lucro_unidade'] > 0
            && (($conversao >= 2 && $eco['roas_empate'] <= 8) || ($conversao >= 1.65 && $eco['roas_empate'] <= 6))) {
            $r[] = Prioridade::alerta('OK', 'oportunidade', 'Oportunidade: converte '.number_format($conversao, 1, ',', '.')."% e a margem aguenta ADS. Coloque em ADS com meta de ROAS {$um($metaPraticavel)}.");
        }

        // "Margem não aguenta ADS" repete o alerta de ADS que já manda pausar.
        if (collect($r)->contains(fn ($a) => $a['tipo'] === 'ads' && str_contains($a['texto'], 'fina demais'))) {
            $r = array_values(array_filter($r, fn ($a) => ! ($a['tipo'] === 'preco' && $a['prioridade'] === 'P1')));
        }

        return $r;
    }

    /** Gasto, GMV e meta de ROAS de cada item com campanha de ADS por produto, nos últimos 30 dias. */
    private function adsPorItem(Carbon $desde): array
    {
        try {
            $campanhas = [];
            for ($offset = 0; $offset < 5000; $offset += 100) {
                $res = $this->shopee->get('/api/v2/ads/get_product_level_campaign_id_list', ['offset' => $offset, 'limit' => 100])['response'] ?? [];
                foreach ($res['campaign_list'] ?? [] as $c) {
                    $campanhas[] = (string) $c['campaign_id'];
                }
                if (empty($res['has_next_page'])) {
                    break;
                }
            }

            $porItem = [];
            $itemDaCampanha = [];
            foreach (array_chunk($campanhas, 100) as $lote) {
                $info = $this->shopee->get('/api/v2/ads/get_product_level_campaign_setting_info', ['campaign_id_list' => implode(',', $lote), 'info_type_list' => '1,3'])['response']['campaign_list'] ?? [];
                foreach ($info as $c) {
                    $item = (string) ($c['common_info']['item_id_list'][0] ?? '');
                    if ($item === '') {
                        continue;
                    }
                    $itemDaCampanha[(string) $c['campaign_id']] = $item;
                    $ativo = in_array($c['common_info']['campaign_status'] ?? '', ['ongoing', 'scheduled'], true);
                    $porItem[$item] ??= ['ativo' => false, 'meta' => 0.0, 'gasto' => 0.0, 'gmv' => 0.0, 'pedidos' => 0, 'cliques' => 0, 'impressoes' => 0];
                    $porItem[$item]['ativo'] = $porItem[$item]['ativo'] || $ativo;
                    if ($ativo) {
                        $porItem[$item]['meta'] = (float) ($c['auto_bidding_info']['roas_target'] ?? 0);
                    }
                }
                $desempenho = $this->shopee->get('/api/v2/ads/get_product_campaign_daily_performance', [
                    'campaign_id_list' => implode(',', $lote),
                    'start_date' => $desde->format('d-m-Y'),
                    'end_date' => Carbon::today()->format('d-m-Y'),
                ])['response']['campaign_list'] ?? [];
                foreach ($desempenho as $c) {
                    $item = $itemDaCampanha[(string) ($c['campaign_id'] ?? '')] ?? null;
                    if (! $item) {
                        continue;
                    }
                    foreach ($c['metrics_list'] ?? [] as $d) {
                        $porItem[$item]['gasto'] += (float) ($d['expense'] ?? 0);
                        $porItem[$item]['gmv'] += (float) ($d['broad_gmv'] ?? 0);
                        $porItem[$item]['pedidos'] += (int) ($d['broad_order'] ?? 0);
                        $porItem[$item]['cliques'] += (int) ($d['clicks'] ?? 0);
                        $porItem[$item]['impressoes'] += (int) ($d['impression'] ?? 0);
                    }
                }
            }

            foreach ($porItem as &$a) {
                $a['roas'] = $a['gasto'] > 0 ? round($a['gmv'] / $a['gasto'], 1) : 0.0;
                $a['ctr'] = $a['impressoes'] > 0 ? round($a['cliques'] / $a['impressoes'] * 100, 1) : 0.0;
            }

            return $porItem;
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    private function emLotes(Collection $ids, int $tamanho, callable $buscar): array
    {
        $tudo = [];
        foreach ($ids->chunk($tamanho) as $lote) {
            try {
                array_push($tudo, ...$buscar($lote->values()));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $tudo;
    }

    /** @param list<array{0: float, 1: float}> $pares */
    public static function mediaPonderada(array $pares): ?float
    {
        $peso = array_sum(array_column($pares, 1));

        return $peso > 0 ? round(array_sum(array_map(fn ($p) => $p[0] * $p[1], $pares)) / $peso, 1) : null;
    }
}
