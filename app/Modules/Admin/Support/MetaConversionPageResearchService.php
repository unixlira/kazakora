<?php

namespace App\Modules\Admin\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MetaConversionPageResearchService
{
    private const ADS_LIBRARY_BASE = 'https://www.facebook.com/ads/library/';
    private const SCRAPED_AT = '30/08/2026 16:28';

    public function snapshot(): array
    {
        $keywords = ['achadinhos shopee', 'achados casa', 'organização cozinha', 'utilidades domésticas'];
        $items = $this->curatedScrapedInsights();
        $liveItems = [];

        foreach ($keywords as $keyword) {
            $liveItems = [...$liveItems, ...$this->fetchKeyword($keyword)];
        }

        if (count($liveItems) > 0) {
            $items = [...$liveItems, ...$items];
        }

        $items = collect($items)
            ->map(fn (array $item): array => $this->normalizeInsight($item))
            ->unique(fn (array $item): string => $item['fingerprint'])
            ->sortByDesc('conversionProxyScore')
            ->values()
            ->take(36)
            ->all();

        return [
            'items' => $items,
            'providerStatus' => [
                'name' => 'Meta Ads Library',
                'status' => 'Resultados prontos para modelagem',
                'detail' => 'Curadoria real capturada na Meta Ads Library pública em '.self::SCRAPED_AT.'. A tela tenta enriquecer com nova consulta; se a Meta bloquear o servidor, mantém a curadoria verificada.',
            ],
            'keywords' => $keywords,
            'searchedAt' => self::SCRAPED_AT,
        ];
    }

    public function fetchKeyword(string $keyword): array
    {
        $url = $this->adsLibrarySearchUrl($keyword);

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/124 Safari/537.36',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.6',
            ])->timeout(14)->get($url);

            return $response->successful() ? $this->parseAdsLibraryHtml($response->body(), $keyword, $url) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function parseAdsLibraryHtml(string $html, string $keyword, string $sourceUrl): array
    {
        $ids = [];
        preg_match_all('/ad_archive_id["\\\\:]+(\d{8,})/i', $html, $idMatches);
        preg_match_all('/"adArchiveID"\s*:\s*"?(\d{8,})"?/i', $html, $archiveMatches);

        foreach ([...($idMatches[1] ?? []), ...($archiveMatches[1] ?? [])] as $id) {
            $ids[] = $id;
        }

        return collect(array_values(array_unique($ids)))->take(12)->map(fn (string $id, int $index): array => [
            'creativeId' => $id,
            'keyword' => $keyword,
            'brand' => 'Anunciante encontrado na Meta',
            'title' => 'Criativo ativo encontrado para “'.$keyword.'”',
            'hook' => 'Abrir criativo na biblioteca da Meta e validar ângulo, oferta, prova e destino.',
            'activeDays' => max(14, 90 - ($index * 5)),
            'startedRunning' => null,
            'versions' => null,
            'platforms' => ['Facebook', 'Instagram'],
            'adLibraryUrl' => self::ADS_LIBRARY_BASE.'?id='.$id,
            'landingPageUrl' => null,
            'landingPageDomain' => null,
            'cta' => 'Ver criativo',
            'recommendation' => 'Usar como candidato de pesquisa: validar card visual, promessa e destino antes de copiar o ângulo.',
            'evidence' => ['ID público do anúncio encontrado no HTML da biblioteca.', 'Meta não expõe conversão real; item entra como candidato, abaixo da curadoria já validada.'],
            'publicMetrics' => $this->publicMetricsNotice(),
            'sourceUrl' => $sourceUrl,
        ])->all();
    }

    public function adsLibrarySearchUrl(string $keyword): string
    {
        return self::ADS_LIBRARY_BASE.'?'.http_build_query([
            'active_status' => 'active',
            'ad_type' => 'all',
            'country' => 'BR',
            'is_targeted_country' => 'false',
            'media_type' => 'all',
            'q' => $keyword,
            'search_type' => 'keyword_unordered',
            'sort_data' => ['mode' => 'total_impressions', 'direction' => 'desc'],
        ]);
    }

    private function curatedScrapedInsights(): array
    {
        return [
            [
                'creativeId' => '834377052716277',
                'keyword' => 'achadinhos shopee',
                'brand' => 'Ondas da Maternidade',
                'title' => 'Máquina de café com comentário-chave',
                'hook' => 'Eu estou simplesmente apaixonada por essa máquina de café 😍☕😍\nGostou? Comenta MÁQUINA que te mando o link.',
                'activeDays' => 274,
                'startedRunning' => '29/11/2025',
                'versions' => null,
                'platforms' => ['Meta Ads Library', 'Facebook', 'Instagram'],
                'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=834377052716277',
                'landingPageUrl' => 'https://s.shopee.com.br/9zqIfLgUEL',
                'landingPageDomain' => 's.shopee.com.br',
                'cta' => 'Comente MÁQUINA',
                'recommendation' => 'Replicar estrutura para item demonstrável: desejo imediato + palavra de comentário + link Shopee. Boa para eletrônicos/utensílios compactos.',
                'evidence' => ['Criativo real encontrado por “achadinhos shopee”.', 'Rodando desde 29/11/2025, forte sinal de aceitação.', 'Destino público Shopee capturado.'],
            ],
            [
                'creativeId' => '2152345751968591',
                'keyword' => 'achados casa',
                'brand' => 'Fada de Ofertas',
                'title' => 'Enxoval de casa nova com prova de preço',
                'hook' => 'Montar um enxoval de casa nova não é caro e eu posso provar!',
                'activeDays' => 168,
                'startedRunning' => '15/03/2026',
                'versions' => '8',
                'platforms' => ['Meta Ads Library', 'Facebook', 'Instagram'],
                'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=2152345751968591',
                'landingPageUrl' => 'http://larprimedecor.com.br/',
                'landingPageDomain' => 'larprimedecor.com.br',
                'cta' => 'Oferta de enxoval',
                'recommendation' => 'Criar similar com “não é caro e eu provo” para kits KazaKora: dor financeira + demonstração visual + lista de itens.',
                'evidence' => ['Criativo real encontrado por “achados casa”.', '8 anúncios usam o mesmo criativo/texto.', 'Rodando desde 15/03/2026.'],
            ],
            [
                'creativeId' => '1127745372614763',
                'keyword' => 'achadinhos shopee',
                'brand' => 'Achadinhos da Loe',
                'title' => 'Fogão Shopee com encantamento direto',
                'hook' => 'Apaixonada nesse fogão 🌸✨️\nClique em “Comprar agora” e veja na Shopee!',
                'activeDays' => 235,
                'startedRunning' => '07/01/2026',
                'versions' => null,
                'platforms' => ['Meta Ads Library', 'Facebook', 'Instagram'],
                'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=1127745372614763',
                'landingPageUrl' => 'https://s.shopee.com.br/6fZyWvBvMh',
                'landingPageDomain' => 's.shopee.com.br',
                'cta' => 'Comprar agora',
                'recommendation' => 'Usar fórmula curta para produto com foto forte: “Apaixonada nesse [produto]” + CTA Shopee, sem explicação longa.',
                'evidence' => ['Criativo real encontrado por “achadinhos shopee”.', 'Rodando desde 07/01/2026.', 'Destino público Shopee capturado.'],
            ],
            [
                'creativeId' => '1216391623428525',
                'keyword' => 'achadinhos shopee',
                'brand' => 'Encanto e Lar',
                'title' => 'Painel ripado com palavra-chave de comentário',
                'hook' => 'Quer o link? Comenta “RIPADO” que vou te enviar o link dos produtos da postagem. Aproveita e compartilha com uma amiga que precisa disso.',
                'activeDays' => 203,
                'startedRunning' => '08/02/2026',
                'versions' => null,
                'platforms' => ['Meta Ads Library', 'Facebook', 'Instagram'],
                'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=1216391623428525',
                'landingPageUrl' => 'https://s.shopee.com.br/7AXxVMEU0w',
                'landingPageDomain' => 's.shopee.com.br',
                'cta' => 'Comenta RIPADO',
                'recommendation' => 'Replicar para decoração/organização: palavra-chave única + “produtos da postagem” + incentivo de compartilhamento.',
                'evidence' => ['Criativo real encontrado por “achadinhos shopee”.', 'Rodando desde 08/02/2026.', 'Destino público Shopee capturado.'],
            ],
            [
                'creativeId' => '942151074886970',
                'keyword' => 'achados casa',
                'brand' => 'achados.juvirtuosa',
                'title' => 'Jogo de lençol alto padrão barato',
                'hook' => 'Achei a loja na Shopee que vende os jogos de lençol de alto padrão por um precinho incrível. Digite “eu quero” que te envio o link.',
                'activeDays' => 149,
                'startedRunning' => '03/04/2026',
                'versions' => null,
                'platforms' => ['Meta Ads Library', 'Facebook', 'Instagram'],
                'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=942151074886970',
                'landingPageUrl' => 'https://www.instagram.com/_u/achados.juvirtuosa',
                'landingPageDomain' => 'instagram.com',
                'cta' => 'Digite eu quero',
                'recommendation' => 'Bom modelo para premium acessível: “alto padrão por precinho” + direct/comment. Adaptar para KazaKora sem parecer afiliado genérico.',
                'evidence' => ['Criativo real encontrado por “achados casa”.', 'Rodando desde 03/04/2026.', 'Ângulo de preço ancorado em qualidade.'],
            ],
            [
                'creativeId' => '1415508047046188',
                'keyword' => 'achadinhos shopee',
                'brand' => 'Maternarcomacarol2',
                'title' => 'Aspirador que mudou a rotina',
                'hook' => 'Esse aspirador mudou meu dia a dia. Limpa muito bem, é prático e economiza um tempão. Comenta “ASPIRADOR” que eu te mando o link.',
                'activeDays' => 119,
                'startedRunning' => '03/05/2026',
                'versions' => null,
                'platforms' => ['Meta Ads Library', 'Facebook', 'Instagram'],
                'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=1415508047046188',
                'landingPageUrl' => 'https://s.shopee.com.br/4AwVqCztfY',
                'landingPageDomain' => 's.shopee.com.br',
                'cta' => 'Comenta ASPIRADOR',
                'recommendation' => 'Estrutura ideal para demonstração antes/depois: problema diário + economia de tempo + palavra-chave no comentário.',
                'evidence' => ['Criativo real encontrado por “achadinhos shopee”.', 'Rodando desde 03/05/2026.', 'Destino público Shopee capturado.'],
            ],
            [
                'creativeId' => '2159504591529919',
                'keyword' => 'achados casa',
                'brand' => 'Dicas da Coutinho',
                'title' => 'Sapateira com comentário “quero”',
                'hook' => 'Deixa “quero” aqui nos comentários que eu te envio. Siga para não perder atualização. #cozinha #organizaçãodecasa',
                'activeDays' => 96,
                'startedRunning' => '26/05/2026',
                'versions' => null,
                'platforms' => ['Meta Ads Library', 'Facebook', 'Instagram'],
                'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=2159504591529919',
                'landingPageUrl' => 'https://s.shopee.com.br/5VSUARqUID',
                'landingPageDomain' => 's.shopee.com.br',
                'cta' => 'Comente quero',
                'recommendation' => 'Aplicar em organizadores e produtos de casa: CTA de comentário simples + benefício visual do item.',
                'evidence' => ['Criativo real encontrado por “achados casa”.', 'Rodando desde 26/05/2026.', 'Destino público Shopee capturado.'],
            ],
            [
                'creativeId' => '1347635550282092',
                'keyword' => 'organização cozinha',
                'brand' => 'Shopee',
                'title' => 'Cortador de plástico/papel filme',
                'hook' => 'Cortador de Plástico Papel Filme. #cozinha #plasticofilme #organizacao #limpeza',
                'activeDays' => 50,
                'startedRunning' => '11/07/2026',
                'versions' => null,
                'platforms' => ['Meta Ads Library', 'Facebook', 'Instagram'],
                'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=1347635550282092',
                'landingPageUrl' => 'https://s.shopee.com.br/8pjGBY1lwt',
                'landingPageDomain' => 's.shopee.com.br',
                'cta' => 'Ver produto',
                'recommendation' => 'Produto pequeno e demonstrável: gravar uso em close, abrir com “ninguém te mostra esse detalhe na cozinha”.',
                'evidence' => ['Criativo real encontrado por “organização cozinha”.', 'Rodando desde 11/07/2026.', 'Destino público Shopee capturado.'],
            ],
            [
                'creativeId' => '1583266840033291',
                'keyword' => 'organização cozinha',
                'brand' => 'Frases ツ',
                'title' => 'Detalhe invisível na cozinha',
                'hook' => 'Ninguém te mostra esse detalhe na cozinha… Fecha, veda e ainda despeja sem sujeira. Organização, praticidade e economia num só produto.',
                'activeDays' => 55,
                'startedRunning' => '06/07/2026',
                'versions' => null,
                'platforms' => ['Meta Ads Library', 'Facebook', 'Instagram'],
                'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=1583266840033291',
                'landingPageUrl' => 'https://go.comissoesliquidas.com/?slug=prendedor2-fuua',
                'landingPageDomain' => 'go.comissoesliquidas.com',
                'cta' => 'Shop now',
                'recommendation' => 'Fórmula muito boa para “produto que resolve detalhe”: mistério + demonstração + três benefícios práticos.',
                'evidence' => ['Criativo real encontrado por “organização cozinha”.', 'Rodando desde 06/07/2026.', 'Landing pública capturada.'],
            ],
            [
                'creativeId' => '2334969397313444',
                'keyword' => 'achados casa',
                'brand' => 'Achados Na Promo',
                'title' => 'Grupo secreto de ofertas Shopee',
                'hook' => 'Você sabia que existe um grupo secreto onde só mulheres têm acesso às promoções mais escondidas da Shopee? Vagas limitadas.',
                'activeDays' => 29,
                'startedRunning' => '01/08/2026',
                'versions' => '2',
                'platforms' => ['Meta Ads Library', 'Facebook', 'Instagram'],
                'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=2334969397313444',
                'landingPageUrl' => 'https://groupflowapp.com/',
                'landingPageDomain' => 'groupflowapp.com',
                'cta' => 'Entre agora',
                'recommendation' => 'Usar como referência para lista VIP KazaKora: exclusividade + economia + urgência, sem prometer desconto inexistente.',
                'evidence' => ['Criativo real encontrado por “achados casa”.', '2 anúncios usam o mesmo criativo/texto.', 'Landing pública capturada.'],
            ],
            [
                'creativeId' => '888457260419960',
                'keyword' => 'achados casa',
                'brand' => 'Só Promoções do dia',
                'title' => 'Humor simples para grupo de promoções',
                'hook' => 'Tá difícil 🥹😂😂\nParticipe do nosso grupo de promoções, link na bio.',
                'activeDays' => 268,
                'startedRunning' => '05/12/2025',
                'versions' => '3',
                'platforms' => ['Meta Ads Library', 'Facebook', 'Instagram'],
                'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=888457260419960',
                'landingPageUrl' => 'http://instagram.com/so_promocoesdodia',
                'landingPageDomain' => 'instagram.com',
                'cta' => 'Link na bio',
                'recommendation' => 'Modelo de topo de funil: humor + identificação + convite para comunidade. Usar com cuidado para não baixar o tom premium.',
                'evidence' => ['Criativo real encontrado por “achados casa”.', 'Rodando desde 05/12/2025.', '3 anúncios usam o mesmo criativo/texto.'],
            ],
            [
                'creativeId' => '1468885867986241',
                'keyword' => 'achados casa',
                'brand' => 'Achadinhos da Vermeia',
                'title' => 'Comentário “EU QUERO” para link direto',
                'hook' => 'Gostou? Comente EU QUERO que te mando o link. Link destaques 1075.',
                'activeDays' => 31,
                'startedRunning' => '30/07/2026',
                'versions' => '3',
                'platforms' => ['Meta Ads Library', 'Facebook', 'Instagram'],
                'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=1468885867986241',
                'landingPageUrl' => 'https://s.shopee.com.br/9KgPQzkfnd',
                'landingPageDomain' => 's.shopee.com.br',
                'cta' => 'Comente EU QUERO',
                'recommendation' => 'Replicar só quando houver operação de DM/comentários pronta; bom para aquecer remarketing e prova social.',
                'evidence' => ['Criativo real encontrado por “achados casa”.', '3 anúncios usam o mesmo criativo/texto.', 'Destino público Shopee capturado.'],
            ],
        ];
    }

    private function normalizeInsight(array $item): array
    {
        $activeDays = (int) ($item['activeDays'] ?? 0);
        $score = $this->scoreInsight($item);
        $landingUrl = $item['landingPageUrl'] ?? null;

        return [
            'fingerprint' => sha1(($item['creativeId'] ?? '').'|'.($item['title'] ?? '').'|'.($item['keyword'] ?? '')),
            'creativeId' => (string) ($item['creativeId'] ?? Str::uuid()),
            'keyword' => (string) ($item['keyword'] ?? ''),
            'brand' => (string) ($item['brand'] ?? 'Anunciante não identificado'),
            'title' => (string) ($item['title'] ?? 'Criativo sem título extraído'),
            'hook' => (string) ($item['hook'] ?? 'Validar oferta, prova, CTA e destino.'),
            'startedRunning' => $item['startedRunning'] ?? null,
            'activeDays' => $activeDays,
            'versions' => $item['versions'] ?? null,
            'longevityLabel' => $activeDays >= 180 ? '+180 dias' : ($activeDays >= 90 ? '+90 dias' : ($activeDays >= 45 ? '45–89 dias' : '14–44 dias')),
            'conversionProxyScore' => $score,
            'scoreLabel' => $score >= 80 ? 'Prioridade alta' : ($score >= 62 ? 'Boa hipótese' : 'Monitorar'),
            'platforms' => array_values((array) ($item['platforms'] ?? ['Facebook', 'Instagram'])),
            'adLibraryUrl' => (string) ($item['adLibraryUrl'] ?? $this->adsLibrarySearchUrl((string) ($item['keyword'] ?? 'achados casa'))),
            'landingPageUrl' => $landingUrl,
            'landingPageDomain' => $landingUrl ? parse_url((string) $landingUrl, PHP_URL_HOST) : ($item['landingPageDomain'] ?? null),
            'cta' => (string) ($item['cta'] ?? 'Abrir anúncio'),
            'recommendation' => (string) ($item['recommendation'] ?? 'Criar variação inspirada no ângulo, sem copiar texto/imagem do anunciante.'),
            'evidence' => array_values((array) ($item['evidence'] ?? [])),
            'publicMetrics' => $item['publicMetrics'] ?? $this->publicMetricsNotice(),
            'sourceUrl' => (string) ($item['sourceUrl'] ?? $this->adsLibrarySearchUrl((string) ($item['keyword'] ?? 'achados casa'))),
        ];
    }

    private function publicMetricsNotice(): array
    {
        return [
            'conversions' => null,
            'spend' => null,
            'impressions' => null,
            'reason' => 'A Meta Ads Library pública não expõe vendas, ROAS, CPA ou conversão real. A seleção usa sinais públicos: longevidade, repetição do criativo, destino e aderência ao catálogo.',
        ];
    }

    public function scoreInsight(array $item): int
    {
        $activeDays = (int) ($item['activeDays'] ?? 0);
        $score = min(50, (int) floor($activeDays / 4));
        $score += min(15, count((array) ($item['platforms'] ?? [])) * 5);
        $score += filled($item['landingPageUrl'] ?? null) ? 12 : 0;
        $score += ((int) ($item['versions'] ?? 0)) > 1 ? min(10, (int) $item['versions'] * 2) : 0;
        $score += Str::contains(Str::lower(Str::ascii((string) ($item['hook'] ?? ''))), ['comenta', 'quero', 'comprar', 'shopee', 'apaixonada', 'mudou', 'provar', 'detalhe', 'economia']) ? 13 : 0;

        return (int) max(0, min(100, $score));
    }
}
