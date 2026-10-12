<?php

namespace App\Modules\Marketplace\Support\Diagnostico;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\MercadoLivre\MercadoLivreAdsService;
use App\Services\MercadoLivre\MercadoLivreAuthService;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Diego (Mercado Livre) olhando anúncio por anúncio (pedido 2026-10-11).
 * Fontes: venda/lucro real do KazaKora, visitas de 30 dias, ROAS real por
 * anúncio no Mercado Ads e a "qualidade do anúncio" que o próprio ML calcula
 * (/item/{id}/performance), cujas pendências já vêm escritas como ação
 * ("Baixe o preço para recuperar a exposição", "Ofereça parcelamento"...).
 */
class DiagnosticoMercadoLivre
{
    private const DIAS = 30;

    public function __construct(
        private readonly MercadoLivreClient $ml,
        private readonly MercadoLivreAdsService $ads,
        private readonly MercadoLivreAuthService $auth,
        private readonly EconomiaDosAnuncios $economia,
    ) {}

    public function gerar(): array
    {
        $desde = Carbon::today()->subDays(self::DIAS - 1);
        $vendas = $this->economia->porAnuncio('mercado_livre', $desde);
        $taxaMedia = $this->economia->taxaMedia('mercado_livre', $desde);

        $anuncios = ProductChannelListing::query()
            ->where('channel', 'mercado_livre')
            ->whereNotNull('external_id')
            ->whereIn('status', ['published', 'active'])
            ->get(['product_id', 'external_id']);
        $ids = $anuncios->pluck('external_id')->map(fn ($id) => (string) $id)->merge(array_keys($vendas))->unique()->values();
        $custos = Product::query()->whereIn('id', $anuncios->pluck('product_id'))->pluck('cost_price', 'id');
        $custoPorItem = $anuncios->groupBy('external_id')->map(fn ($g) => $g->map(fn ($l) => $custos[$l->product_id] ?? null)->filter()->avg());

        $itens = [];
        foreach ($ids->chunk(20) as $lote) {
            try {
                foreach ($this->ml->get('/items', ['ids' => $lote->implode(','), 'attributes' => 'id,title,price,status,secure_thumbnail,pictures,video_id,permalink,available_quantity,catalog_listing']) as $r) {
                    if (($r['code'] ?? 0) === 200) {
                        $itens[(string) $r['body']['id']] = $r['body'];
                    }
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
        $ads = $this->adsPorItem($desde);

        $alertas = [];
        $metas = [];
        $notas = [];
        foreach ($itens as $id => $item) {
            if (in_array($item['status'] ?? '', ['closed', 'inactive'], true) && ! isset($vendas[$id])) {
                continue;
            }
            $eco = $vendas[$id] ?? $this->economia->estimar((float) $item['price'], $custoPorItem[$id] ?? null, $taxaMedia);
            $visitas = $this->visitas($id);
            $qualidade = $this->qualidade($id);
            if ($qualidade['nota'] !== null) {
                $notas[] = $qualidade['nota'];
            }
            if (($eco['receita'] ?? 0) > 0 && $eco['roas_meta'] && $eco['roas_meta'] <= 25) {
                $metas[] = [$eco['roas_meta'], $eco['receita']];
            }
            $produto = [
                'item_id' => $id,
                'nome' => (string) ($item['title'] ?? ''),
                'imagem' => $item['pictures'][0]['secure_url'] ?? $item['secure_thumbnail'] ?? $item['thumbnail'] ?? null,
                'link' => $item['permalink'] ?? "https://produto.mercadolivre.com.br/{$id}",
                'preco' => (float) $item['price'],
                'vendas_30d' => $eco['unidades'] ?? 0,
                'receita_30d' => $eco['receita'] ?? 0,
                'roas_empate' => $eco['roas_empate'],
                'roas_meta' => $eco['roas_meta'],
                'margem' => $eco['margem'],
                'qualidade' => $qualidade['rotulo'],
            ];
            foreach ($this->regras($item, $eco, $ads[$id] ?? null, $visitas, $qualidade) as $alerta) {
                $alertas[] = $alerta + ['produto' => $produto];
            }
        }

        $gasto = array_sum(array_column($ads, 'gasto'));
        $gmvAds = array_sum(array_column($ads, 'gmv'));

        return [
            'canal' => 'mercado_livre',
            'especialista' => 'Diego',
            'gerado_em' => now()->toIso8601String(),
            'dias' => self::DIAS,
            'kpis' => [
                'pedidos' => array_sum(array_column($vendas, 'pedidos')),
                'faturamento' => round(array_sum(array_column($vendas, 'receita')), 2),
                'ads_gasto' => round($gasto, 2),
                'ads_roas' => $gasto > 0 ? round($gmvAds / $gasto, 1) : null,
                'roas_meta_loja' => DiagnosticoShopee::mediaPonderada($metas),
                'anuncios' => count($itens),
                'em_ads' => count(array_filter($ads, fn ($a) => $a['ativo'])),
                'qualidade_media' => $notas ? (int) round(array_sum($notas) / count($notas)) : null,
            ],
            'alertas' => Prioridade::ordenar($alertas),
        ];
    }

    private function regras(array $item, array $eco, ?array $ad, ?int $visitas, array $qualidade): array
    {
        $r = [];
        $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
        $um = fn ($v) => number_format((float) $v, 1, ',', '.');
        // Meta acima de 25 não é praticável: aí a margem é que está errada.
        $metaPraticavel = $eco['roas_meta'] && $eco['roas_meta'] <= 25 ? $eco['roas_meta'] : null;
        $status = $item['status'] ?? '';
        $vendidos = (int) ($eco['unidades'] ?? 0);

        if ($status === 'paused' && (int) ($item['available_quantity'] ?? 0) > 0) {
            $r[] = Prioridade::alerta('P1', 'status', 'Anúncio pausado com '.(int) $item['available_quantity'].' em estoque no ML. Se não for um duplicado, reative para voltar a vender.');
        } elseif ($status === 'under_review') {
            $r[] = Prioridade::alerta('P0', 'status', 'Anúncio em revisão pelo ML: veja o motivo na central e corrija.');
        }

        if ($eco['sem_custo'] ?? false) {
            $r[] = Prioridade::alerta('P1', 'custo', 'Produto sem custo cadastrado: não dá para saber o lucro nem a meta de ROAS. Cadastre o custo.');
        } elseif ($eco['lucro_unidade'] <= 0) {
            $minimo = $eco['preco_minimo'] ? " Para sobrar 15%, o preço precisa ser pelo menos R$ {$fmt($eco['preco_minimo'])}." : '';
            $quem = ($eco['estimado'] ?? false) ? 'No preço atual, cada venda daria prejuízo antes do ADS (estimado com a taxa média do canal):' : 'Vende com prejuízo antes do ADS:';
            $r[] = Prioridade::alerta('P0', 'preco', "{$quem} R$ {$fmt($eco['lucro_unidade'])} por unidade, já com a taxa e o frete que o ML cobra.{$minimo}");
        } elseif (! $eco['roas_meta'] && $eco['preco_minimo']) {
            $r[] = Prioridade::alerta('P1', 'preco', "Margem de {$um($eco['margem'])}% não aguenta ADS com 15% de lucro. Preço mínimo para isso: R$ {$fmt($eco['preco_minimo'])}.");
        }

        if ($ad) {
            if ($ad['gasto'] >= 20 && $ad['vendas'] === 0) {
                $r[] = Prioridade::alerta('P0', 'ads', "Mercado Ads gastou R$ {$fmt($ad['gasto'])} em 30 dias sem nenhuma venda. Pause ou melhore o anúncio antes de continuar.");
            } elseif ($ad['gasto'] >= 10 && $eco['roas_empate'] && $ad['roas'] < $eco['roas_empate'] && ! $metaPraticavel) {
                $minimo = $eco['preco_minimo'] ? " Preço mínimo para aguentar ADS com lucro: R$ {$fmt($eco['preco_minimo'])}." : '';
                $r[] = Prioridade::alerta('P0', 'ads', "ADS dando prejuízo (ROAS {$um($ad['roas'])}, empate {$um($eco['roas_empate'])}) e a margem é fina demais para ADS. Pause o ADS deste produto.{$minimo}");
            } elseif ($ad['gasto'] >= 10 && $eco['roas_empate'] && $ad['roas'] < $eco['roas_empate']) {
                $meta = $um($metaPraticavel);
                $r[] = Prioridade::alerta('P0', 'ads', "Mercado Ads dando prejuízo: ROAS real {$um($ad['roas'])} e o empate é {$um($eco['roas_empate'])}. Suba o ROAS objetivo para {$meta} ou pause.");
            }
            if ($ad['impressoes'] >= 1000 && $ad['ctr'] < 1) {
                $r[] = Prioridade::alerta('P1', 'capa', "Pouca gente clica no anúncio patrocinado ({$um($ad['ctr'])}%). Troque a foto principal e revise título e preço.");
            }
        }

        if ($visitas !== null && $visitas >= 100) {
            $conversao = $vendidos / $visitas * 100;
            if ($conversao < 1) {
                $r[] = Prioridade::alerta('P1', 'conversao', "{$visitas} visitas e só {$vendidos} vendas em 30 dias (".number_format($conversao, 1, ',', '.').'%). Revise preço, fotos, frete e descrição.');
            }
        } elseif ($visitas !== null && $visitas < 30 && $status === 'active') {
            $r[] = Prioridade::alerta('P1', 'trafego', "Só {$visitas} visitas em 30 dias: o anúncio quase não aparece. Melhore título e fotos, entre em promoção ou no Mercado Ads.");
        }

        // O que o próprio ML diz que falta (qualidade do anúncio).
        foreach ($qualidade['pendencias'] as $pendencia) {
            $preco = str_contains(mb_strtolower($pendencia), 'preço');
            $r[] = Prioridade::alerta($preco ? 'P1' : 'P2', 'qualidade', "O ML pede: {$pendencia}.");
        }
        if (count($item['pictures'] ?? []) < 6) {
            $r[] = Prioridade::alerta('P2', 'fotos', 'Só '.count($item['pictures'] ?? []).' fotos. Use 6 ou mais, com fundo branco na principal.');
        }

        if ((! $ad || ! $ad['ativo']) && $vendidos >= 5 && $metaPraticavel && $visitas && $vendidos / $visitas * 100 >= 2) {
            $r[] = Prioridade::alerta('OK', 'oportunidade', 'Oportunidade: converte bem ('.number_format($vendidos / $visitas * 100, 1, ',', '.')."%) e a margem aguenta ADS. Coloque no Mercado Ads com ROAS objetivo {$um($metaPraticavel)}.");
        }

        // "Margem não aguenta ADS" repete o alerta de ADS que já manda pausar.
        if (collect($r)->contains(fn ($a) => $a['tipo'] === 'ads' && str_contains($a['texto'], 'fina demais'))) {
            $r = array_values(array_filter($r, fn ($a) => ! ($a['tipo'] === 'preco' && $a['prioridade'] === 'P1')));
        }

        return $r;
    }

    private function visitas(string $id): ?int
    {
        try {
            return (int) ($this->ml->get("/items/{$id}/visits/time_window", ['last' => self::DIAS, 'unit' => 'day'])['total_visits'] ?? 0);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{nota: ?int, rotulo: ?string, pendencias: list<string>} */
    private function qualidade(string $id): array
    {
        try {
            $p = $this->ml->get("/item/{$id}/performance");
        } catch (Throwable) {
            return ['nota' => null, 'rotulo' => null, 'pendencias' => []];
        }
        $pendencias = [];
        foreach ($p['buckets'] ?? [] as $grupo) {
            foreach ($grupo['variables'] ?? [] as $v) {
                if (($v['status'] ?? '') === 'PENDING' && ! empty($v['title'])) {
                    $pendencias[] = rtrim((string) $v['title'], '.');
                }
            }
        }

        return [
            'nota' => isset($p['score']) ? (int) $p['score'] : null,
            'rotulo' => $p['level_wording'] ?? null,
            'pendencias' => array_slice($pendencias, 0, 3),
        ];
    }

    /** Gasto, receita e ROAS de cada anúncio no Mercado Ads nos últimos 30 dias. */
    private function adsPorItem(Carbon $desde): array
    {
        try {
            $anunciante = $this->ads->resolveAdvertiserId();
            if (! $anunciante) {
                return [];
            }
            $token = $this->auth->ensureValidToken($this->auth->currentToken())->access_token;
            $porItem = [];
            for ($offset = 0; $offset < 2000; $offset += 50) {
                $res = Http::withToken($token)->withHeaders(['Api-Version' => '2'])->timeout(60)
                    ->get("https://api.mercadolibre.com/marketplace/advertising/MLB/advertisers/{$anunciante}/product_ads/ads/search", [
                        'date_from' => $desde->toDateString(),
                        'date_to' => Carbon::today()->toDateString(),
                        'metrics' => 'clicks,prints,cost,total_amount,units_quantity',
                        'limit' => 50,
                        'offset' => $offset,
                    ]);
                if ($res->failed()) {
                    break;
                }
                foreach ($res->json('results') ?? [] as $a) {
                    $m = $a['metrics'] ?? [];
                    $gasto = (float) ($m['cost'] ?? 0);
                    $gmv = (float) ($m['total_amount'] ?? 0);
                    $impressoes = (int) ($m['prints'] ?? 0);
                    $porItem[(string) $a['item_id']] = [
                        'ativo' => ($a['status'] ?? '') === 'active',
                        'gasto' => $gasto,
                        'gmv' => $gmv,
                        'vendas' => (int) ($m['units_quantity'] ?? 0),
                        'impressoes' => $impressoes,
                        'roas' => $gasto > 0 ? round($gmv / $gasto, 1) : 0.0,
                        'ctr' => $impressoes > 0 ? round((int) ($m['clicks'] ?? 0) / $impressoes * 100, 1) : 0.0,
                    ];
                }
                if ($offset + 50 >= (int) $res->json('paging.total', 0)) {
                    break;
                }
            }

            return $porItem;
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }
}
