<?php

namespace App\Modules\Admin\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DigitalMarketingResearchService
{
    private const CACHE_KEY = 'admin.digital-marketing.snapshot.v1';
    private const ADS_LIBRARY_BASE = 'https://www.facebook.com/ads/library/';

    public function __construct(
        private readonly PdfOpportunityResearchService $pdfResearch,
        private readonly MetaConversionPageResearchService $metaResearch,
    ) {
    }

    public function dashboardSnapshot(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(12), fn (): array => $this->buildSnapshot());
    }

    public function refreshSnapshot(): array
    {
        Cache::forget(self::CACHE_KEY);

        return $this->scanAndCache();
    }

    public function scanAndCache(bool $dryRun = false): array
    {
        $snapshot = $this->buildSnapshot();

        if (! $dryRun) {
            Cache::put(self::CACHE_KEY, $snapshot, now()->addHours(12));
            Storage::disk('local')->put('digital-marketing/last-scan.json', json_encode([
                'generated_at' => now()->toIso8601String(),
                'summary' => $snapshot['summary'],
                'regions' => collect($snapshot['regionPlaybooks'])->pluck('region')->all(),
                'top_creatives' => collect($snapshot['mappedCreatives'])->take(12)->values()->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $snapshot;
    }

    public function paymentGatewayMatrix(): array
    {
        return [
            [
                'region' => 'América',
                'countries' => 'EUA, Canadá e audiência global em inglês',
                'gateway' => 'Stripe + PayPal + Paddle/Gumroad como fallback',
                'receivesInBrl' => 'Indireto: converter via banco/conta internacional; validar tarifas e câmbio antes de escala.',
                'bestFor' => 'Ebooks low-ticket em inglês, bundles, templates, planners e funis com cartão internacional.',
                'conversionNotes' => 'Cartão internacional converte bem; PayPal reduz fricção para público mais velho; preço psicológico em US$ 7, US$ 9 ou US$ 17.',
                'risk' => 'Pode exigir entidade/conta compatível. Não assumir saque direto em reais sem contrato validado.',
                'priority' => 2,
            ],
            [
                'region' => 'América Latina',
                'countries' => 'Brasil, México, Colômbia, Chile, Peru e Argentina',
                'gateway' => 'Hotmart para operação internacional + Kiwify/Eduzz/Monetizze no Brasil quando o foco for nacional',
                'receivesInBrl' => 'Mais favorável para criador brasileiro: vender fora e liquidar/receber em reais depende da plataforma e cadastro.',
                'bestFor' => 'PDFs em português/espanhol, religião, renda extra, educação infantil, organização e receitas.',
                'conversionNotes' => 'Pix no Brasil aumenta conversão; parcelamento ajuda ticket de R$ 47+; em espanhol testar checkout local e cartão.',
                'risk' => 'Compliance de promessas sensíveis: renda, saúde, espiritualidade e educação precisam de linguagem cuidadosa.',
                'priority' => 1,
            ],
            [
                'region' => 'Europa',
                'countries' => 'Portugal, Espanha, França, Alemanha, Itália e Reino Unido',
                'gateway' => 'Hotmart internacional, Stripe/PayPal, Paddle, Gumroad ou Payhip',
                'receivesInBrl' => 'Possível por plataforma intermediária ou conversão bancária; confirmar impostos, VAT/IVA e payout.',
                'bestFor' => 'Guias práticos, estudo bíblico/histórico, organização, imigração, idioma e templates profissionais.',
                'conversionNotes' => 'Checkout com VAT/IVA claro, prova editorial forte e idioma local. Portugal é ponte natural para começar.',
                'risk' => 'LGPD/GDPR, VAT/IVA e regras de consumidor digital; evitar promessas absolutas.',
                'priority' => 3,
            ],
            [
                'region' => 'Ásia',
                'countries' => 'Índia, Filipinas, Singapura, Japão e audiência em inglês',
                'gateway' => 'PayPal, Stripe onde disponível, Paddle/Gumroad/Payhip como merchant of record ou marketplace digital',
                'receivesInBrl' => 'Normalmente indireto, por payout da plataforma e conversão posterior.',
                'bestFor' => 'Templates, estudo, produtividade, planners, inglês e guias religiosos/históricos em inglês.',
                'conversionNotes' => 'Preço baixo e prova visual são críticos; começar por mercados com inglês forte antes de traduzir.',
                'risk' => 'Muitos países têm métodos locais fortes; sem gateway local a conversão pode cair.',
                'priority' => 4,
            ],
        ];
    }

    private function buildSnapshot(): array
    {
        $pdfSnapshot = $this->pdfResearch->snapshot();
        $metaSnapshot = $this->metaResearch->snapshot();
        $mappedCreatives = $this->mappedCreatives($pdfSnapshot, $metaSnapshot);
        $gateways = $this->paymentGatewayMatrix();

        return [
            'summary' => [
                'mappedCreatives' => count($mappedCreatives),
                'pdfOpportunities' => $pdfSnapshot['summary']['totalOpportunities'] ?? 0,
                'paymentRegions' => count($gateways),
                'priorityRegions' => collect($gateways)->where('priority', '<=', 2)->count(),
                'lastScanAt' => now()->format('d/m/Y H:i'),
            ],
            'mappedCreatives' => $mappedCreatives,
            'regionPlaybooks' => $this->regionPlaybooks($gateways),
            'paymentGateways' => $gateways,
            'sourceSearches' => $this->sourceSearches(),
            'operatingRules' => [
                'Modelar estrutura, promessa e funil; nunca copiar texto, arte, PDF, páginas internas ou identidade visual de terceiros.',
                'Conversão real e ROAS não são públicos: longevidade de anúncio, repetição de criativo e destino ativo são proxies, não prova absoluta.',
                'Antes de escalar tráfego fora do Brasil, validar checkout, impostos, reembolso, idioma e payout em reais.',
            ],
            'providerStatus' => [
                'name' => 'Meta Ads Library + curadoria PDF + matriz regional de checkout',
                'status' => 'Radar inicial pronto para operação',
                'detail' => 'A cron noturna atualiza cache e snapshot local. Fontes públicas podem bloquear scraping; nesses casos a curadoria verificada permanece como fallback.',
            ],
        ];
    }

    private function mappedCreatives(array $pdfSnapshot, array $metaSnapshot): array
    {
        $pdfBenchmarks = collect($pdfSnapshot['benchmarks'] ?? [])->map(function (array $ad): array {
            return [
                'id' => 'pdf-'.$ad['creativeId'],
                'type' => 'PDF / produto digital',
                'region' => 'América Latina',
                'market' => 'Brasil',
                'source' => 'Meta Ads Library',
                'creativeId' => $ad['creativeId'],
                'brand' => $ad['advertiser'] ?? 'Benchmark externo',
                'title' => Str::limit($ad['hook'] ?? 'Criativo PDF mapeado', 86),
                'hook' => $ad['hook'] ?? null,
                'activeDays' => (int) ($ad['activeDays'] ?? 0),
                'versions' => $ad['versions'] ?? null,
                'landingPageDomain' => $ad['landingPageDomain'] ?? null,
                'adLibraryUrl' => $ad['adLibraryUrl'] ?? null,
                'landingPageUrl' => $ad['landingPageUrl'] ?? null,
                'action' => 'Modelar promessa, oferta, bônus e mecanismo de entrega em PDF autoral.',
            ];
        });

        $metaItems = collect($metaSnapshot['items'] ?? [])->map(function (array $item): array {
            return [
                'id' => 'meta-'.$item['creativeId'],
                'type' => 'Criativo / página de conversão',
                'region' => 'América Latina',
                'market' => 'Brasil',
                'source' => 'Meta Ads Library',
                'creativeId' => $item['creativeId'],
                'brand' => $item['brand'] ?? 'Benchmark externo',
                'title' => $item['title'] ?? 'Criativo mapeado',
                'hook' => $item['hook'] ?? null,
                'activeDays' => (int) ($item['activeDays'] ?? 0),
                'versions' => $item['versions'] ?? null,
                'landingPageDomain' => $item['landingPageDomain'] ?? null,
                'adLibraryUrl' => $item['adLibraryUrl'] ?? null,
                'landingPageUrl' => $item['landingPageUrl'] ?? null,
                'action' => $item['recommendation'] ?? 'Usar como referência de ângulo, não como cópia.',
            ];
        });

        return $pdfBenchmarks
            ->merge($metaItems)
            ->unique('id')
            ->sortByDesc('activeDays')
            ->values()
            ->take(80)
            ->all();
    }

    private function regionPlaybooks(array $gateways): array
    {
        return collect($gateways)->map(fn (array $gateway): array => [
            'region' => $gateway['region'],
            'countries' => $gateway['countries'],
            'firstTest' => match ($gateway['region']) {
                'América Latina' => 'Português Brasil primeiro; depois espanhol para México/Colômbia/Chile com checkout Hotmart.',
                'América' => 'Inglês simples, preço US$ 9-17 e página curta com prova visual do PDF.',
                'Europa' => 'Portugal como teste de menor fricção; depois Espanha com versão traduzida e checkout com VAT claro.',
                default => 'Inglês para Índia/Filipinas/Singapura antes de localização pesada.',
            },
            'checkout' => $gateway['gateway'],
            'moneyBackToBrazil' => $gateway['receivesInBrl'],
            'adAngles' => match ($gateway['region']) {
                'América Latina' => ['renda extra', 'organização da casa', 'religião/mistérios bíblicos', 'educação infantil'],
                'América' => ['templates prontos', 'Bible mysteries', 'printable planners', 'side hustle guides'],
                'Europa' => ['guias práticos', 'Portugal/idioma', 'história bíblica', 'produtividade'],
                default => ['study templates', 'productivity', 'English learning', 'faith/history explainer'],
            },
        ])->sortBy('region')->values()->all();
    }

    private function sourceSearches(): array
    {
        $keywords = ['pdf ebook', 'printable planner', 'bible mystery ebook', 'renda extra pdf', 'atividades alfabetização pdf'];
        $countries = [
            'BR' => 'Brasil',
            'US' => 'Estados Unidos',
            'MX' => 'México',
            'PT' => 'Portugal',
            'ES' => 'Espanha',
            'GB' => 'Reino Unido',
            'IN' => 'Índia',
            'PH' => 'Filipinas',
        ];

        return collect($countries)->flatMap(function (string $countryName, string $countryCode) use ($keywords): array {
            return collect($keywords)->map(fn (string $keyword): array => [
                'country' => $countryName,
                'countryCode' => $countryCode,
                'keyword' => $keyword,
                'url' => $this->adsLibrarySearchUrl($keyword, $countryCode),
            ])->all();
        })->values()->all();
    }

    private function adsLibrarySearchUrl(string $keyword, string $country): string
    {
        return self::ADS_LIBRARY_BASE.'?'.http_build_query([
            'active_status' => 'active',
            'ad_type' => 'all',
            'country' => $country,
            'is_targeted_country' => 'false',
            'media_type' => 'all',
            'q' => $keyword,
            'search_type' => 'keyword_unordered',
        ]);
    }
}
