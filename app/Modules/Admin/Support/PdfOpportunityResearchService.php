<?php

namespace App\Modules\Admin\Support;

use Illuminate\Support\Str;

class PdfOpportunityResearchService
{
    private const SCRAPED_AT = '30/08/2026 16:44';
    private const ADS_LIBRARY_BASE = 'https://www.facebook.com/ads/library/';

    public function snapshot(): array
    {
        $opportunities = collect($this->opportunities())
            ->map(fn (array $item): array => $this->normalizeOpportunity($item))
            ->sortByDesc('score')
            ->values()
            ->all();

        return [
            'opportunities' => $opportunities,
            'benchmarks' => collect($opportunities)->flatMap(fn (array $item): array => $item['adBenchmarks'])->values()->all(),
            'summary' => [
                'totalOpportunities' => count($opportunities),
                'priorityOpportunities' => collect($opportunities)->where('score', '>=', 82)->count(),
                'totalAdBenchmarks' => collect($opportunities)->sum(fn (array $item): int => count($item['adBenchmarks'])),
                'averageScore' => (int) round(collect($opportunities)->avg('score') ?? 0),
                'scrapedAt' => self::SCRAPED_AT,
            ],
            'providerStatus' => [
                'name' => 'Meta Ads Library + busca pública de artigos',
                'status' => 'Pesquisa pronta para modelagem de PDFs',
                'detail' => 'Benchmarks reais capturados na Meta Ads Library pública e organizados por nicho de PDF. Conversão real, ROAS e vendas não são públicos; o score é proxy comercial.',
            ],
            'searchedAt' => self::SCRAPED_AT,
            'sourceNotice' => 'Esta tela é só para oportunidades de PDF: e-books, planners, apostilas, moldes, guias e checklists. Ela não lista criativos de produtos físicos; traz referências externas para criar PDFs, apps simples ou sites sem copiar texto, arte ou material protegido.',
            'scoreFormula' => 'Score proxy = longevidade dos anúncios + repetição do criativo + dor urgente + facilidade de produzir PDF + clareza de oferta + risco regulatório baixo.',
            'developerNote' => 'Antes de mexer neste fluxo: git pull --ff-only. Se precisar medir conversão real, integrar UTMs, checkout próprio e Pixel/CAPI; fontes públicas só permitem proxy.',
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
        $score = $this->scoreOpportunity($item);

        return [
            ...$item,
            'score' => $score,
            'scoreLabel' => match (true) {
                $score >= 86 => 'Prioridade alta',
                $score >= 74 => 'Boa aposta',
                default => 'Monitorar',
            },
            'difficultyLabel' => match ($item['difficulty'] ?? 'medium') {
                'low' => 'produção rápida',
                'high' => 'exige validação forte',
                default => 'produção média',
            },
            'fingerprint' => Str::slug(($item['title'] ?? '').'-'.($item['niche'] ?? '')),
            'sourceSearchUrl' => $this->adsLibrarySearchUrl($item['metaKeyword'] ?? $item['niche'] ?? 'pdf'),
        ];
    }

    public function scoreOpportunity(array $item): int
    {
        $benchmarks = collect($item['adBenchmarks'] ?? []);
        $maxDays = (int) $benchmarks->max('activeDays');
        $totalVersions = (int) $benchmarks->sum(fn (array $ad): int => (int) ($ad['versions'] ?? 1));
        $hasLanding = $benchmarks->contains(fn (array $ad): bool => filled($ad['landingPageUrl'] ?? null));

        $score = 30;
        $score += min(24, (int) floor($maxDays / 14));
        $score += min(14, $totalVersions * 2);
        $score += $hasLanding ? 10 : 0;
        $score += (int) ($item['painScore'] ?? 10);
        $score += (int) ($item['productionEaseScore'] ?? 10);
        $score += (int) ($item['catalogFitScore'] ?? 8);
        $score -= (int) ($item['riskPenalty'] ?? 0);

        return max(1, min(100, $score));
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
                'catalogFitScore' => 10,
                'riskPenalty' => 3,
                'difficulty' => 'medium',
                'conversionMechanism' => 'Preço baixo + PDF pronto para imprimir + promessa de economizar tempo da professora/mãe.',
                'adAngle' => '“50 atividades prontas para imprimir” com lista de conteúdos e demonstração folheando o material.',
                'creativeSuggestion' => 'Reels vertical mostrando páginas passando rápido, antes/depois da criança completando sílabas e CTA “comente EU QUERO”.',
                'funnelSuggestion' => 'Anúncio → WhatsApp/landing curta → checkout Pix/cartão → entrega automática do PDF + bônus.',
                'risks' => ['Não prometer cura, diagnóstico ou resultado educacional garantido.', 'Usar atividades originais, sem copiar apostilas de professoras/portais.'],
                'articleSources' => [
                    ['label' => 'Espaço Professor: apostilas de atividades em PDF', 'url' => 'https://www.espacoprofessor.com/16-apostilas-de-atividades-para-baixar-gratuitamente-em-pdf/'],
                    ['label' => 'Busca pública: atividades alfabetização PDF', 'url' => 'https://www.bing.com/search?q=atividades+alfabetiza%C3%A7%C3%A3o+pdf+professora'],
                ],
                'adBenchmarks' => [
                    ['creativeId' => '1982129989214771', 'advertiser' => 'Atividades Com Amor', 'activeDays' => 348, 'startedRunning' => '16/09/2025', 'versions' => null, 'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=1982129989214771', 'landingPageUrl' => 'https://atividadescomamor.com.br', 'landingPageDomain' => 'atividadescomamor.com.br', 'hook' => 'Caderno das Sílabas de A a Z por R$ 12,00; PDF pronto para impressão.'],
                    ['creativeId' => '2008992333136396', 'advertiser' => 'Leitura Fácil', 'activeDays' => 39, 'startedRunning' => '23/07/2026', 'versions' => '6', 'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=2008992333136396', 'landingPageUrl' => null, 'landingPageDomain' => null, 'hook' => 'Kit Grafismo Fonético em PDF pelo WhatsApp, 10 minutos por dia.'],
                    ['creativeId' => '2341721086366886', 'advertiser' => 'Expert Kids', 'activeDays' => 34, 'startedRunning' => '28/07/2026', 'versions' => null, 'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=2341721086366886', 'landingPageUrl' => 'https://www.kumon.com.br', 'landingPageDomain' => 'kumon.com.br', 'hook' => '50 atividades de sílabas simples prontas para imprimir.'],
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
                'catalogFitScore' => 8,
                'riskPenalty' => 2,
                'difficulty' => 'medium',
                'conversionMechanism' => 'Renda extra + produto fofo/demonstrável + pacote com muitas fotos reduz medo de execução.',
                'adAngle' => '“Transforme linhas em lucro” ou “moldes prontos para copiar” com peças finais bonitas no primeiro segundo.',
                'creativeSuggestion' => 'Vídeo curto com gato/personagem pronto, cortes do PDF na tela e prova “70 fotos de apoio”.',
                'funnelSuggestion' => 'Anúncio → landing com galeria de peças → pacote PDF + bônus de precificação → upsell de coleção.',
                'risks' => ['Evitar personagens protegidos por marca.', 'Usar moldes originais e fotos próprias.'],
                'articleSources' => [
                    ['label' => 'Busca pública: amigurumi receita PDF vender', 'url' => 'https://www.bing.com/search?q=amigurumi+receita+pdf+vender'],
                    ['label' => 'Busca pública: crochê renda extra amigurumi', 'url' => 'https://www.bing.com/search?q=croch%C3%AA+renda+extra+amigurumi'],
                ],
                'adBenchmarks' => [
                    ['creativeId' => '1653711459115473', 'advertiser' => 'Amigujumi - Ateliê de Crochê 3D', 'activeDays' => 145, 'startedRunning' => '08/04/2026', 'versions' => null, 'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=1653711459115473', 'landingPageUrl' => 'https://amigujumi.com.br/felinos/', 'landingPageDomain' => 'amigujumi.com.br', 'hook' => 'Coleção Felinos Amigujumi: receita detalhada em PDF, 70 fotos e acessórios exclusivos.'],
                    ['creativeId' => '3418282375017581', 'advertiser' => 'Coisa de Mãe', 'activeDays' => 31, 'startedRunning' => '31/07/2026', 'versions' => null, 'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=3418282375017581', 'landingPageUrl' => 'https://api.whatsapp.com', 'landingPageDomain' => 'whatsapp.com', 'hook' => 'Mais de 100 moldes prontos para copiar; renda extra nas horas vagas.'],
                ],
            ],
            [
                'niche' => 'Culinária para vender',
                'title' => 'Finger foods, brigadeiros e receitas vendáveis em PDF',
                'pdfFormat' => 'e-book de receitas + montagem + lista de fornecedores + precificação',
                'audience' => 'pessoas buscando renda extra com comida caseira',
                'promise' => 'começar a vender com receitas simples e apresentação mais bonita',
                'priceBand' => 'R$ 19 a R$ 67',
                'metaKeyword' => 'ebook receitas pdf',
                'painScore' => 17,
                'productionEaseScore' => 13,
                'catalogFitScore' => 8,
                'riskPenalty' => 5,
                'difficulty' => 'medium',
                'conversionMechanism' => 'Primeira venda + passo a passo visual + promessa de começar com utensílios comuns.',
                'adAngle' => '“Sua primeira venda pode começar com uma bandeja bem montada” e demonstração estética do produto pronto.',
                'creativeSuggestion' => 'Vídeo de montagem da bandeja, close de resultado final e tela com módulos do PDF.',
                'funnelSuggestion' => 'Anúncio → landing de oferta única → bônus de precificação e fornecedores → remarketing para coleção avançada.',
                'risks' => ['Não prometer renda garantida.', 'Evitar promessa de saúde/zero açúcar sem base técnica.'],
                'articleSources' => [
                    ['label' => 'Busca pública: finger foods para vender e-book', 'url' => 'https://www.bing.com/search?q=finger+foods+para+vender+ebook'],
                    ['label' => 'Busca pública: receitas para vender em casa PDF', 'url' => 'https://www.bing.com/search?q=receitas+para+vender+em+casa+pdf'],
                ],
                'adBenchmarks' => [
                    ['creativeId' => '1509418351226258', 'advertiser' => 'Finger Foods', 'activeDays' => 15, 'startedRunning' => '16/08/2026', 'versions' => null, 'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=1509418351226258', 'landingPageUrl' => 'https://oferta.cursarnet.com', 'landingPageDomain' => 'oferta.cursarnet.com', 'hook' => 'Sua primeira venda pode começar com uma bandeja bem montada; receitas passo a passo.'],
                    ['creativeId' => '2091667484782389', 'advertiser' => 'Lucas Lacerda Nutricionista', 'activeDays' => 35, 'startedRunning' => '27/07/2026', 'versions' => '5', 'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=2091667484782389', 'landingPageUrl' => 'https://lucaslacerdanutri.com/ebook-dieta-sem-drama/', 'landingPageDomain' => 'lucaslacerdanutri.com', 'hook' => 'Receitas organizadas em um único lugar, por categoria, sem complicação.'],
                    ['creativeId' => '1077556021394941', 'advertiser' => 'Renata Bacha', 'activeDays' => 9, 'startedRunning' => '22/08/2026', 'versions' => 'múltiplas', 'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=1077556021394941', 'landingPageUrl' => null, 'landingPageDomain' => null, 'hook' => 'Curso com PDF complementar, fornecedores e e-book bônus de receitas.'],
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
                'catalogFitScore' => 9,
                'riskPenalty' => 1,
                'difficulty' => 'low',
                'conversionMechanism' => 'Demonstração de preenchimento no celular + bônus financeiro + promessa de rotina sob controle.',
                'adAngle' => '“Chega de esquecer compromisso” mostrando toque no campo e preenchimento direto no celular.',
                'creativeSuggestion' => 'Tela gravada preenchendo planner, zoom em 446 páginas/bônus e CTA para organizar hoje.',
                'funnelSuggestion' => 'Anúncio → checkout direto de baixo ticket → order bump de pacote de capas/templates.',
                'risks' => ['Diferenciar layout e identidade para não copiar planners existentes.', 'Promessa de organização, não transformação financeira garantida.'],
                'articleSources' => [
                    ['label' => 'Busca pública: planner financeiro PDF preenchível', 'url' => 'https://www.bing.com/search?q=planner+financeiro+pdf+preench%C3%ADvel'],
                    ['label' => 'Busca pública: planner digital PDF celular', 'url' => 'https://www.bing.com/search?q=planner+digital+pdf+celular'],
                ],
                'adBenchmarks' => [
                    ['creativeId' => '1331473312405163', 'advertiser' => 'PlannerFin', 'activeDays' => 53, 'startedRunning' => '09/07/2026', 'versions' => '2', 'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=1331473312405163', 'landingPageUrl' => null, 'landingPageDomain' => null, 'hook' => 'O melhor controle financeiro do Brasil.'],
                    ['creativeId' => '2863961817297404', 'advertiser' => 'PlannerFin', 'activeDays' => 53, 'startedRunning' => '09/07/2026', 'versions' => null, 'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=2863961817297404', 'landingPageUrl' => 'https://plannerfin.com', 'landingPageDomain' => 'plannerfin.com', 'hook' => 'Controle suas finanças; jeito mais fácil de cuidar do dinheiro.'],
                    ['creativeId' => '1698549608270875', 'advertiser' => 'Central de Arquivos PDF', 'activeDays' => 7, 'startedRunning' => '24/08/2026', 'versions' => null, 'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=1698549608270875', 'landingPageUrl' => 'https://centralarquivospdf.com.br', 'landingPageDomain' => 'centralarquivospdf.com.br', 'hook' => 'Planner Inteligente em PDF preenchível no celular, tablet ou computador.'],
                ],
            ],
            [
                'niche' => 'Casa e organização',
                'title' => 'Checklist de organização da casa em PDF',
                'pdfFormat' => 'checklists imprimíveis + rotina por cômodo + lista de produtos úteis',
                'audience' => 'mulheres que querem organizar casa, rotina e compras',
                'promise' => 'transformar bagunça em rotina simples por cômodos, com listas prontas',
                'priceBand' => 'R$ 9,90 a R$ 27',
                'metaKeyword' => 'organização casa pdf',
                'painScore' => 14,
                'productionEaseScore' => 18,
                'catalogFitScore' => 18,
                'riskPenalty' => 0,
                'difficulty' => 'low',
                'conversionMechanism' => 'Dor visual de bagunça + checklist simples + promessa de rotina prática entregue em PDF.',
                'adAngle' => '“Pare de tentar organizar tudo de cabeça” com checklist por ambiente e links dos itens usados.',
                'creativeSuggestion' => 'Antes/depois de gaveta/cozinha + PDF checklist na tela + demonstração das páginas preenchíveis/imprimíveis.',
                'funnelSuggestion' => 'PDF barato/lead magnet → sequência WhatsApp/e-mail → upsell de planner completo, app simples ou área/site de acesso.',
                'risks' => ['Evitar copiar método de organizadoras profissionais.', 'Manter a entrega digital; não transformar este radar em funil de produto físico.'],
                'articleSources' => [
                    ['label' => 'Busca pública: checklist organização casa PDF', 'url' => 'https://www.bing.com/search?q=checklist+organiza%C3%A7%C3%A3o+casa+pdf'],
                    ['label' => 'Busca pública: planner limpeza doméstica PDF', 'url' => 'https://www.bing.com/search?q=planner+limpeza+dom%C3%A9stica+pdf'],
                ],
                'adBenchmarks' => [
                    ['creativeId' => 'search-organizacao-casa-pdf', 'advertiser' => 'Busca Meta por organização casa PDF', 'activeDays' => 0, 'startedRunning' => null, 'versions' => null, 'adLibraryUrl' => $this->adsLibrarySearchUrl('organização casa pdf'), 'landingPageUrl' => null, 'landingPageDomain' => null, 'hook' => 'Usar busca viva da Meta para encontrar checklists/rotinas por cômodo.'],
                ],
            ],
            [
                'niche' => 'Neurodesenvolvimento / família',
                'title' => 'Guia de marcos do desenvolvimento em PDF',
                'pdfFormat' => 'guia educativo + checklist de observação + orientação para consulta profissional',
                'audience' => 'pais atentos ao desenvolvimento infantil',
                'promise' => 'organizar sinais observáveis e próximos passos sem substituir pediatra ou especialista',
                'priceBand' => 'lead magnet grátis ou R$ 9,90 a R$ 29,90 se bem robusto',
                'metaKeyword' => 'guia autismo pdf',
                'painScore' => 18,
                'productionEaseScore' => 8,
                'catalogFitScore' => 4,
                'riskPenalty' => 12,
                'difficulty' => 'high',
                'conversionMechanism' => 'Ansiedade parental + guia simples + comentário no post para receber PDF no direct.',
                'adAngle' => '“Comente MARCOS para receber o guia em PDF” com linguagem calma e educativa.',
                'creativeSuggestion' => 'Criativo sóbrio, sem promessa de diagnóstico. Usar roteiro de orientação e convite para buscar avaliação profissional.',
                'funnelSuggestion' => 'Lead magnet gratuito → nutrição ética → produto pago mais completo apenas com fontes oficiais e revisado.',
                'risks' => ['Tema sensível: nunca diagnosticar, prometer tratamento ou explorar medo dos pais.', 'Exigir fonte oficial/profissional antes de vender.'],
                'articleSources' => [
                    ['label' => 'CDC: marcos do desenvolvimento infantil', 'url' => 'https://www.cdc.gov/ncbddd/actearly/milestones/index.html'],
                    ['label' => 'Busca pública: marcos desenvolvimento infantil PDF', 'url' => 'https://www.bing.com/search?q=marcos+desenvolvimento+infantil+pdf'],
                ],
                'adBenchmarks' => [
                    ['creativeId' => '28292926933664802', 'advertiser' => 'draluanamaluf', 'activeDays' => 4, 'startedRunning' => '27/08/2026', 'versions' => null, 'adLibraryUrl' => 'https://www.facebook.com/ads/library/?id=28292926933664802', 'landingPageUrl' => 'https://www.instagram.com', 'landingPageDomain' => 'instagram.com', 'hook' => 'Comente MARCOS para receber guia completo em PDF sobre o que observar em cada fase.'],
                ],
            ],
        ];
    }
}
