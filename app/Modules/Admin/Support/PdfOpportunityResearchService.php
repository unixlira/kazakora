<?php

namespace App\Modules\Admin\Support;

use Illuminate\Support\Str;

class PdfOpportunityResearchService
{
    private const SCRAPED_AT = '03/10/2026 23:55';
    private const ADS_LIBRARY_BASE = 'https://www.facebook.com/ads/library/';
    private const MINIMUM_SCORE = 82;

    public function snapshot(): array
    {
        $opportunities = collect($this->opportunities())
            ->map(fn (array $item): array => $this->normalizeOpportunity($item))
            ->filter(fn (array $item): bool => $item['score'] >= self::MINIMUM_SCORE && count($item['adBenchmarks']) > 0)
            ->sortByDesc('score')
            ->values()
            ->map(fn (array $item, int $index): array => [...$item, 'rank' => $index + 1])
            ->all();

        $benchmarks = collect($opportunities)->flatMap(function (array $item): array {
            return collect($item['adBenchmarks'])->map(fn (array $ad, int $index): array => [
                ...$ad,
                'rank' => ($item['rank'] * 100) + $index + 1,
                'opportunityRank' => $item['rank'],
                'opportunityTitle' => $item['title'],
                'opportunityScore' => $item['score'],
                'opportunityScoreLabel' => $item['scoreLabel'],
                'deliverable' => $item['pdfFormat'],
                'niche' => $item['niche'],
                'priceBand' => $item['priceBand'],
            ])->all();
        })->values()->all();

        return [
            'opportunities' => $opportunities,
            'benchmarks' => $benchmarks,
            'summary' => [
                'totalOpportunities' => count($opportunities),
                'priorityOpportunities' => collect($opportunities)->where('score', '>=', 88)->count(),
                'totalAdBenchmarks' => count($benchmarks),
                'averageScore' => (int) round(collect($opportunities)->avg('score') ?? 0),
                'minimumScore' => self::MINIMUM_SCORE,
                'verifiedEvidenceLinks' => collect($benchmarks)->sum(fn (array $ad): int => count($ad['accessLinks'] ?? [])),
                'scrapedAt' => self::SCRAPED_AT,
            ],
            'providerStatus' => [
                'name' => 'Curadoria criteriosa de PDFs + páginas/conteúdos acessáveis',
                'status' => 'Somente oportunidades com score alto e links de evidência',
                'detail' => 'Meta Ads Library não é mais a evidência principal. Anúncio da Meta vira link secundário; o radar exige página de conversão e conteúdo/amostra/fonte aberta acessável para moldar no formato próprio.',
            ],
            'searchedAt' => self::SCRAPED_AT,
            'sourceNotice' => 'Esta tela é só para oportunidades de PDF/produto digital. Não entram produtos físicos, afiliados Shopee ou e-commerce. Cada card precisa ter links úteis para ver página, conteúdo, PDF/amostra, checkout ou fonte aberta.',
            'scoreFormula' => 'Score = força comercial + evidência acessável + clareza de entrega + facilidade de produzir + risco. Meta indisponível não derruba a pesquisa se a página/conteúdo principal abrir.',
            'qualityGate' => [
                'Score mínimo '.self::MINIMUM_SCORE,
                'Obrigatório ter página de conversão ou página do produto digital.',
                'Obrigatório ter conteúdo, amostra, fonte aberta, preview ou página que mostre o que será entregue.',
                'Links da Meta são auditoria secundária, nunca a única fonte.',
                'Sem produto físico, sem Shopee, sem afiliado e sem funil de loja física.',
            ],
            'developerNote' => 'Antes de mexer neste fluxo: git pull --ff-only. Se precisar provar conversão real, integrar UTMs, checkout próprio e Pixel/CAPI; fontes públicas só permitem proxy.',
        ];
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

    public function normalizeOpportunity(array $item): array
    {
        $benchmarks = collect($item['adBenchmarks'] ?? [])
            ->map(fn (array $ad): array => $this->normalizeBenchmark($ad))
            ->filter(fn (array $ad): bool => $this->passesBenchmarkGate($ad))
            ->values()
            ->all();

        $item['adBenchmarks'] = $benchmarks;
        $scoreBreakdown = $this->scoreBreakdown($item);
        $score = array_sum($scoreBreakdown);
        $score = max(1, min(100, $score));

        return [
            ...$item,
            'score' => $score,
            'scoreBreakdown' => $scoreBreakdown,
            'scoreLabel' => match (true) {
                $score >= 92 => 'Score altíssimo',
                $score >= 88 => 'Prioridade alta',
                $score >= self::MINIMUM_SCORE => 'Boa aposta com prova',
                default => 'Fora do corte',
            },
            'difficultyLabel' => match ($item['difficulty'] ?? 'medium') {
                'low' => 'produção rápida',
                'high' => 'exige validação forte',
                default => 'produção média',
            },
            'fingerprint' => Str::slug(($item['title'] ?? '').'-'.($item['niche'] ?? '')),
            'sourceSearchUrl' => $this->adsLibrarySearchUrl($item['metaKeyword'] ?? $item['niche'] ?? 'pdf'),
            'evidenceSummary' => $this->evidenceSummary($benchmarks),
        ];
    }

    public function scoreOpportunity(array $item): int
    {
        return max(1, min(100, array_sum($this->scoreBreakdown($item))));
    }

    private function normalizeBenchmark(array $ad): array
    {
        $links = collect($ad['accessLinks'] ?? [])->map(function (array $link): array {
            return [
                'label' => $link['label'] ?? 'Abrir referência',
                'url' => $link['url'] ?? '#',
                'kind' => $link['kind'] ?? 'conteúdo',
                'priority' => (int) ($link['priority'] ?? 5),
                'access' => $link['access'] ?? 'abrir',
            ];
        })->sortBy('priority')->values()->all();

        if (($ad['landingPageUrl'] ?? null) && collect($links)->doesntContain(fn (array $link): bool => $link['url'] === $ad['landingPageUrl'])) {
            array_unshift($links, [
                'label' => 'Página de conversão',
                'url' => $ad['landingPageUrl'],
                'kind' => 'conversion_page',
                'priority' => 1,
                'access' => 'abrir',
            ]);
        }

        return [
            ...$ad,
            'accessLinks' => $links,
            'evidenceCount' => count($links),
            'hasConversionPage' => collect($links)->contains(fn (array $link): bool => in_array($link['kind'], ['conversion_page', 'product_page', 'checkout'], true)),
            'hasModelAsset' => collect($links)->contains(fn (array $link): bool => in_array($link['kind'], ['sample_pdf', 'content_source', 'product_preview', 'official_source', 'checkout'], true)),
            'primaryAccessUrl' => $links[0]['url'] ?? ($ad['landingPageUrl'] ?? null),
            'auditNote' => 'Abrir primeiro os links de página/conteúdo. Meta fica apenas como auditoria secundária, porque pode ficar indisponível.',
        ];
    }

    private function passesBenchmarkGate(array $ad): bool
    {
        return ($ad['hasConversionPage'] ?? false) && ($ad['hasModelAsset'] ?? false);
    }

    private function scoreBreakdown(array $item): array
    {
        $benchmarks = collect($item['adBenchmarks'] ?? []);
        $maxDays = (int) $benchmarks->max('activeDays');
        $totalVersions = (int) $benchmarks->sum(fn (array $ad): int => (int) ($ad['versions'] ?? 1));
        $evidenceLinks = (int) $benchmarks->sum(fn (array $ad): int => count($ad['accessLinks'] ?? []));
        $hasConversion = $benchmarks->contains(fn (array $ad): bool => (bool) ($ad['hasConversionPage'] ?? false));
        $hasModelAsset = $benchmarks->contains(fn (array $ad): bool => (bool) ($ad['hasModelAsset'] ?? false));

        return [
            'base' => 20,
            'evidencia_acessavel' => min(26, ($evidenceLinks * 5) + ($hasConversion ? 8 : 0) + ($hasModelAsset ? 8 : 0)),
            'longevidade_anuncio' => min(12, (int) floor($maxDays / 18)),
            'repeticao_criativo' => min(6, $totalVersions * 2),
            'dor_urgente' => min(18, (int) ($item['painScore'] ?? 10)),
            'facilidade_producao' => min(16, (int) ($item['productionEaseScore'] ?? 10)),
            'aderencia_digital' => min(12, (int) ($item['catalogFitScore'] ?? 8)),
            'risco' => -1 * min(18, (int) ($item['riskPenalty'] ?? 0)),
        ];
    }

    private function evidenceSummary(array $benchmarks): array
    {
        return [
            'benchmarks' => count($benchmarks),
            'accessLinks' => collect($benchmarks)->sum(fn (array $ad): int => count($ad['accessLinks'] ?? [])),
            'hasConversionPage' => collect($benchmarks)->contains(fn (array $ad): bool => (bool) ($ad['hasConversionPage'] ?? false)),
            'hasModelAsset' => collect($benchmarks)->contains(fn (array $ad): bool => (bool) ($ad['hasModelAsset'] ?? false)),
        ];
    }

    private function opportunities(): array
    {
        return [
            [
                'niche' => 'Educação infantil',
                'title' => 'Caderno de sílabas e alfabetização para imprimir',
                'pdfFormat' => 'apostila PDF imprimível + bônus de fichas rápidas',
                'audience' => 'professoras, mães e reforço escolar',
                'promise' => 'economizar horas de preparação e entregar atividades prontas de alfabetização inicial',
                'priceBand' => 'R$ 9,90 a R$ 29,90',
                'metaKeyword' => 'atividades alfabetização pdf',
                'painScore' => 18,
                'productionEaseScore' => 16,
                'catalogFitScore' => 12,
                'riskPenalty' => 3,
                'difficulty' => 'medium',
                'conversionMechanism' => 'Preço baixo + PDF pronto para imprimir + promessa de economizar tempo da professora/mãe.',
                'adAngle' => '“50 atividades prontas para imprimir” com lista de conteúdos e demonstração folheando o material.',
                'creativeSuggestion' => 'Reels vertical folheando páginas reais, antes/depois da atividade e CTA direto para baixar o PDF.',
                'funnelSuggestion' => 'Anúncio → página curta com preview de páginas → checkout Pix/cartão → entrega automática do PDF + bônus.',
                'risks' => ['Não prometer resultado educacional garantido.', 'Usar atividades originais, sem copiar apostilas de professoras/portais.'],
                'articleSources' => [
                    ['label' => 'Espaço Professor: apostilas de atividades em PDF', 'url' => 'https://www.espacoprofessor.com/16-apostilas-de-atividades-para-baixar-gratuitamente-em-pdf/'],
                ],
                'adBenchmarks' => [
                    [
                        'creativeId' => '1982129989214771',
                        'advertiser' => 'Atividades Com Amor',
                        'activeDays' => 348,
                        'startedRunning' => '16/09/2025',
                        'versions' => 1,
                        'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=1982129989214771',
                        'landingPageUrl' => 'https://atividadescomamor.com.br',
                        'landingPageDomain' => 'atividadescomamor.com.br',
                        'hook' => 'Caderno das Sílabas de A a Z por R$ 12,00; PDF pronto para impressão.',
                        'accessLinks' => [
                            ['label' => 'Página de conversão', 'url' => 'https://atividadescomamor.com.br', 'kind' => 'conversion_page', 'priority' => 1],
                            ['label' => 'Conteúdo/amostra de referência', 'url' => 'https://www.espacoprofessor.com/16-apostilas-de-atividades-para-baixar-gratuitamente-em-pdf/', 'kind' => 'content_source', 'priority' => 2],
                            ['label' => 'Anúncio Meta secundário', 'url' => 'https://www.facebook.com/ads/library/?id=1982129989214771', 'kind' => 'ad_archive', 'priority' => 9],
                        ],
                    ],
                ],
            ],
            [
                'niche' => 'Organização pessoal',
                'title' => 'Planner digital preenchível em PDF',
                'pdfFormat' => 'planner diário/semanal/anual preenchível + planner financeiro bônus',
                'audience' => 'mulheres que querem rotina, metas, hábitos e finanças no celular',
                'promise' => 'organizar a vida em um PDF preenchível, sem imprimir e sem depender de aplicativo complexo',
                'priceBand' => 'R$ 17 a R$ 49',
                'metaKeyword' => 'planner financeiro pdf',
                'painScore' => 15,
                'productionEaseScore' => 15,
                'catalogFitScore' => 12,
                'riskPenalty' => 1,
                'difficulty' => 'low',
                'conversionMechanism' => 'Demonstração de preenchimento no celular + bônus financeiro + promessa de rotina sob controle.',
                'adAngle' => '“Chega de esquecer compromisso” mostrando toque no campo e preenchimento direto no celular.',
                'creativeSuggestion' => 'Tela gravada preenchendo planner, zoom nas páginas e CTA para organizar hoje.',
                'funnelSuggestion' => 'Anúncio → página de produto com preview → checkout direto → order bump de capas/templates.',
                'risks' => ['Diferenciar layout e identidade para não copiar planners existentes.', 'Promessa de organização, não transformação financeira garantida.'],
                'articleSources' => [
                    ['label' => 'Busca pública: planner digital PDF celular', 'url' => 'https://www.bing.com/search?q=planner+digital+pdf+celular'],
                ],
                'adBenchmarks' => [
                    [
                        'creativeId' => '2863961817297404',
                        'advertiser' => 'PlannerFin',
                        'activeDays' => 53,
                        'startedRunning' => '09/07/2026',
                        'versions' => 2,
                        'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=2863961817297404',
                        'landingPageUrl' => 'https://plannerfin.com',
                        'landingPageDomain' => 'plannerfin.com',
                        'hook' => 'Controle suas finanças; jeito mais fácil de cuidar do dinheiro.',
                        'accessLinks' => [
                            ['label' => 'Página de conversão', 'url' => 'https://plannerfin.com', 'kind' => 'conversion_page', 'priority' => 1],
                            ['label' => 'Preview/modelo do produto', 'url' => 'https://plannerfin.com', 'kind' => 'product_preview', 'priority' => 2],
                            ['label' => 'Anúncio Meta secundário', 'url' => 'https://www.facebook.com/ads/library/?id=2863961817297404', 'kind' => 'ad_archive', 'priority' => 9],
                        ],
                    ],
                    [
                        'creativeId' => '1698549608270875',
                        'advertiser' => 'Central de Arquivos PDF',
                        'activeDays' => 7,
                        'startedRunning' => '24/08/2026',
                        'versions' => 1,
                        'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=1698549608270875',
                        'landingPageUrl' => 'https://centralarquivospdf.com.br',
                        'landingPageDomain' => 'centralarquivospdf.com.br',
                        'hook' => 'Planner Inteligente em PDF preenchível no celular, tablet ou computador.',
                        'accessLinks' => [
                            ['label' => 'Página/preview do PDF', 'url' => 'https://centralarquivospdf.com.br', 'kind' => 'product_preview', 'priority' => 1],
                            ['label' => 'Página de conversão', 'url' => 'https://centralarquivospdf.com.br', 'kind' => 'conversion_page', 'priority' => 2],
                        ],
                    ],
                ],
            ],
            [
                'niche' => 'Artesanato e renda extra',
                'title' => 'Moldes/receitas de amigurumi em PDF',
                'pdfFormat' => 'receitas passo a passo em PDF + fotos de apoio + checklist de materiais',
                'audience' => 'mulheres que já fazem crochê ou querem renda extra manual',
                'promise' => 'transformar habilidade manual em peças vendáveis com instrução visual simples',
                'priceBand' => 'R$ 17 a R$ 47',
                'metaKeyword' => 'amigurumi receita pdf',
                'painScore' => 17,
                'productionEaseScore' => 12,
                'catalogFitScore' => 10,
                'riskPenalty' => 2,
                'difficulty' => 'medium',
                'conversionMechanism' => 'Renda extra + peça final bonita + pacote com fotos reduz medo de execução.',
                'adAngle' => '“Transforme linhas em lucro” com peça final bonita no primeiro segundo e preview do PDF.',
                'creativeSuggestion' => 'Vídeo curto com personagem pronto, cortes do PDF na tela e prova “70 fotos de apoio”.',
                'funnelSuggestion' => 'Anúncio → landing com galeria de peças → pacote PDF + bônus de precificação → upsell de coleção.',
                'risks' => ['Evitar personagens protegidos por marca.', 'Usar moldes originais e fotos próprias.'],
                'articleSources' => [
                    ['label' => 'Busca pública: amigurumi receita PDF vender', 'url' => 'https://www.bing.com/search?q=amigurumi+receita+pdf+vender'],
                ],
                'adBenchmarks' => [
                    [
                        'creativeId' => '1653711459115473',
                        'advertiser' => 'Amigujumi - Ateliê de Crochê 3D',
                        'activeDays' => 145,
                        'startedRunning' => '08/04/2026',
                        'versions' => 1,
                        'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=1653711459115473',
                        'landingPageUrl' => 'https://amigujumi.com.br/felinos/',
                        'landingPageDomain' => 'amigujumi.com.br',
                        'hook' => 'Coleção Felinos Amigujumi: receita detalhada em PDF, 70 fotos e acessórios exclusivos.',
                        'accessLinks' => [
                            ['label' => 'Página de conversão', 'url' => 'https://amigujumi.com.br/felinos/', 'kind' => 'conversion_page', 'priority' => 1],
                            ['label' => 'Preview/modelo do produto', 'url' => 'https://amigujumi.com.br/felinos/', 'kind' => 'product_preview', 'priority' => 2],
                            ['label' => 'Anúncio Meta secundário', 'url' => 'https://www.facebook.com/ads/library/?id=1653711459115473', 'kind' => 'ad_archive', 'priority' => 9],
                        ],
                    ],
                ],
            ],
            [
                'niche' => 'Arqueologia e manuscritos preservados',
                'title' => 'Guia documental sobre manuscritos bíblicos em museus',
                'pdfFormat' => 'PDF documental + linha do tempo + mapa de museus/fontes oficiais',
                'audience' => 'curiosos de história, arqueologia, museus e preservação de textos antigos',
                'promise' => 'entender onde estão manuscritos preservados, o que eles provam e o que não dá para afirmar',
                'priceBand' => 'R$ 17 a R$ 47',
                'metaKeyword' => 'biblical archaeology ebook manuscripts museum',
                'painScore' => 16,
                'productionEaseScore' => 11,
                'catalogFitScore' => 11,
                'riskPenalty' => 4,
                'difficulty' => 'medium',
                'conversionMechanism' => 'Curiosidade documental + fontes oficiais + visual de museu/linha do tempo, sem vender devoção.',
                'adAngle' => '“Os manuscritos preservados que contam a história do texto antigo” com fontes oficiais e fotos/museus.',
                'creativeSuggestion' => 'Carrossel/Reels com mapa, manuscrito, museu e CTA para baixar o guia documental.',
                'funnelSuggestion' => 'Anúncio → página editorial com fontes → checkout → PDF com links/QR para museus e acervos oficiais.',
                'risks' => ['Não vender devocional ou “palavra de Deus”.', 'Separar evidência documental de crença e evitar afirmações absolutas.'],
                'articleSources' => [
                    ['label' => 'Codex Sinaiticus oficial', 'url' => 'https://codexsinaiticus.org/'],
                ],
                'adBenchmarks' => [
                    [
                        'creativeId' => 'source-codex-sinaiticus',
                        'advertiser' => 'Codex Sinaiticus / acervo oficial',
                        'activeDays' => 0,
                        'startedRunning' => null,
                        'versions' => 1,
                        'adLibraryUrl' => null,
                        'landingPageUrl' => 'https://codexsinaiticus.org/',
                        'landingPageDomain' => 'codexsinaiticus.org',
                        'hook' => 'Acervo oficial com acesso ao manuscrito e contexto histórico; usar como base documental, não como cópia.',
                        'accessLinks' => [
                            ['label' => 'Fonte oficial / conteúdo', 'url' => 'https://codexsinaiticus.org/', 'kind' => 'official_source', 'priority' => 1],
                            ['label' => 'Página base para modelo editorial', 'url' => 'https://codexsinaiticus.org/', 'kind' => 'conversion_page', 'priority' => 2],
                        ],
                    ],
                ],
            ],
        ];
    }
}
