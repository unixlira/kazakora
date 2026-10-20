<?php

namespace App\Modules\Marketplace\Support\Sugestoes;

use App\Modules\Marketplace\Models\Sugestao;

/**
 * Leitura do funil do Meta Ads (Sugestões do dia, 2026-10-20; proposta em
 * Documents/meta-ads-metricas-diagnostico-2026-10-20.md do Homer). Cada elo
 * aponta um lugar diferente para agir:
 *
 * - Criativo (por anúncio): vídeo que perde gente nos 3 primeiros segundos,
 *   CTR do link baixo (= foto ou vídeo não chamam), frequência alta (fadiga)
 *   e impressão cara.
 * - Página e checkout (por produto, somando os anúncios dele): clica e não
 *   chega, chega e não compra, põe no carrinho e desiste.
 * - Resultado (por produto): ROAS abaixo do empate (prejuízo) ou bem acima
 *   (dá para escalar).
 *
 * Cuidado com o Pixel (achado 2026-10-20): ele só carrega com o OK dos
 * cookies e só ~14% dos visitantes aceitam (Parafusadeira: 77 visitantes
 * vindos do Meta no site, 8 chegadas no Pixel). Por isso visita é contada
 * pelo próprio site (site_visits, origem meta_ads), carrinho e compra do
 * Pixel são comparados só com quem aceitou, e o prejuízo usa as vendas do
 * produto no site (o ROAS atribuído pela Meta sai subestimado).
 *
 * Regra de amostra: sem volume mínimo não dá veredito (não julgar cedo). As
 * frases são fixas, não são texto de IA.
 */
class DiagnosticoMetaAds
{
    public const IMPRESSOES_MIN = 1000;

    public const CTR_LINK_MIN = 1.0;

    public const GANCHO_MIN = 25.0;

    public const FREQUENCIA_MAX = 3.0;

    public const CPM_MAX = 45.0;

    public const CLIQUES_MIN_CHEGADA = 30;

    public const CHEGADA_MIN = 50.0;

    /** Visitantes do anúncio sem nenhuma venda do produto no site. */
    public const VISITANTES_SEM_VENDA = 60;

    /** Quem aceitou cookies: base do carrinho do Pixel. */
    public const CONSENTIDOS_MIN = 20;

    public const CARRINHO_MIN = 5.0;

    public const CARRINHOS_MIN_CHECKOUT = 8;

    public const COMPRA_CARRINHO_MIN = 25.0;

    /** Prejuízo só depois de gastar o lucro de 2 vendas. */
    public const VENDAS_DE_GASTO_PARA_JULGAR = 2;

    public const ESCALAR_FATOR = 1.5;

    public const ESCALAR_COMPRAS_MIN = 3;

    /**
     * Elos do criativo, para um anúncio (linha de MetaAdsService::funilPorAnuncio).
     * Só o primeiro problema, na ordem do funil: consertar o primeiro vazamento primeiro.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>|null
     */
    public function doAnuncio(array $a, ?int $productId = null): ?array
    {
        $impressoes = (int) ($a['impressoes'] ?? 0);
        if ($impressoes < self::IMPRESSOES_MIN) {
            return null;
        }

        $formato = (string) ($a['formato'] ?? 'imagem');
        $gancho = $formato === 'video' ? round((int) ($a['video_3s'] ?? 0) / $impressoes * 100, 1) : null;
        $ctr = $a['ctr_link'] ?? null;
        $freq = $a['frequencia'] ?? null;
        $cpm = $a['cpm'] ?? null;

        $base = [
            'area' => Sugestao::AREA_CRIATIVO,
            'titulo' => (string) ($a['nome'] ?? 'Anúncio'),
            'product_id' => $productId,
            'meta_ad_id' => (string) $a['ad_id'],
            'dados' => [
                'metricas' => array_filter([
                    'Gasto' => Texto::reais((float) ($a['gasto'] ?? 0)),
                    'Impressões' => Texto::inteiro($impressoes),
                    'CTR do link' => $ctr !== null ? Texto::pct((float) $ctr) : null,
                    'Gancho 3 s' => $gancho !== null ? Texto::pct($gancho) : null,
                    'Frequência' => $freq !== null ? Texto::num((float) $freq) : null,
                    'CPM' => $cpm !== null ? Texto::reais((float) $cpm) : null,
                ], fn ($v) => $v !== null),
                'miniatura' => $a['miniatura'] ?? null,
                'formato' => $formato,
            ],
        ];

        return match (true) {
            $gancho !== null && $gancho < self::GANCHO_MIN => $base + [
                'chave' => 'criativo:gancho:'.$a['ad_id'],
                'tipo' => 'gancho_fraco',
                'prioridade' => 2,
                'acao' => 'O vídeo perde as pessoas no começo. Mostre o produto aberto e em uso já no 1º segundo.',
                'motivo' => 'Só '.Texto::pct($gancho).' de quem viu assistiu 3 segundos (bom é acima de '.Texto::pct(self::GANCHO_MIN).').',
            ],
            $ctr !== null && $ctr < self::CTR_LINK_MIN => $base + [
                'chave' => 'criativo:ctr:'.$a['ad_id'],
                'tipo' => 'ctr_baixo',
                'prioridade' => 1,
                'acao' => match ($formato) {
                    'video' => 'Pouca gente clica. Troque a capa e os 3 primeiros segundos do vídeo.',
                    'carrossel' => 'Pouca gente clica. Troque o carrossel: abra com o produto em uso e o benefício principal.',
                    default => 'Pouca gente clica. Troque a imagem: produto em uso, fundo limpo e o benefício principal.',
                },
                'motivo' => 'CTR do link de '.Texto::pct((float) $ctr).' em '.Texto::inteiro($impressoes).' impressões (bom é acima de '.Texto::pct(self::CTR_LINK_MIN).'). CTR baixo é problema de imagem ou vídeo, não de página.',
            ],
            $freq !== null && $freq > self::FREQUENCIA_MAX => $base + [
                'chave' => 'criativo:fadiga:'.$a['ad_id'],
                'tipo' => 'fadiga',
                'prioridade' => 2,
                'acao' => 'O público já cansou deste anúncio. Suba um criativo novo no mesmo conjunto.',
                'motivo' => 'Cada pessoa viu o anúncio '.Texto::num((float) $freq).' vezes em 7 dias (limite '.Texto::num(self::FREQUENCIA_MAX).').',
            ],
            $cpm !== null && $cpm > self::CPM_MAX => $base + [
                'chave' => 'criativo:cpm:'.$a['ad_id'],
                'tipo' => 'cpm_alto',
                'prioridade' => 3,
                'acao' => 'Impressão cara. Abra o público (idade, região) ou teste outro criativo.',
                'motivo' => 'CPM de '.Texto::reais((float) $cpm).' (faixa saudável até '.Texto::reais(self::CPM_MAX).').',
            ],
            default => null,
        };
    }

    /**
     * Elos da página, do checkout e do resultado, para um produto (soma dos
     * anúncios que levam a ele).
     *
     * @param  array{product_id: int, nome: string, preco: float, gasto: float, cliques_link: int, carrinhos: int,
     *   compras: int, vendas_meta: float, visitantes: int, consentidos: int, vendas_site: int, receita_site: float,
     *   roas_empate: ?float, margem_estimada: bool, checagens: list<string>, mais_barato: ?array}  $p
     * @return list<array<string, mixed>>
     */
    public function doProduto(array $p): array
    {
        $sugestoes = [];
        $id = (int) $p['product_id'];
        $metricas = array_filter([
            'Gasto 7 dias' => Texto::reais($p['gasto']),
            'Cliques no link' => Texto::inteiro($p['cliques_link']),
            'Visitantes do anúncio (site)' => Texto::inteiro($p['visitantes']),
            'Carrinhos (Pixel)' => $p['carrinhos'] > 0 ? Texto::inteiro($p['carrinhos']) : null,
            'Compras (Pixel)' => Texto::inteiro($p['compras']),
            'Vendas do produto no site' => Texto::inteiro($p['vendas_site']),
            'ROAS para empatar' => $p['roas_empate'] !== null ? Texto::num($p['roas_empate']).($p['margem_estimada'] ? ' (estimado)' : '') : null,
        ], fn ($v) => $v !== null);
        $base = fn (array $s) => $s + ['titulo' => $p['nome'], 'product_id' => $id, 'meta_ad_id' => null, 'dados' => ['metricas' => $metricas]];

        // 1. Clica e não chega.
        if ($p['cliques_link'] >= self::CLIQUES_MIN_CHEGADA && $p['visitantes'] / $p['cliques_link'] * 100 < self::CHEGADA_MIN) {
            $sugestoes[] = $base([
                'chave' => 'pagina:chegada:'.$id,
                'area' => Sugestao::AREA_PAGINA,
                'tipo' => 'chegada_baixa',
                'prioridade' => 2,
                'acao' => 'Muita gente clica e não chega na página. Confira se o link do anúncio abre o produto certo e se a página carrega rápido no celular.',
                'motivo' => Texto::inteiro($p['visitantes']).' visitantes no site para '.Texto::inteiro($p['cliques_link']).' cliques no link.',
            ]);
        }

        // 2. Chega e não compra: pelo site (todos os visitantes) ou pelo carrinho de quem aceitou cookies.
        $semVenda = $p['visitantes'] >= self::VISITANTES_SEM_VENDA && $p['vendas_site'] === 0 && $p['compras'] === 0;
        $carrinhoBaixo = $p['consentidos'] >= self::CONSENTIDOS_MIN && $p['carrinhos'] / $p['consentidos'] * 100 < self::CARRINHO_MIN;
        if ($semVenda || $carrinhoBaixo) {
            $motivo = $semVenda
                ? Texto::inteiro($p['visitantes']).' visitantes vindos do anúncio e nenhuma venda do produto no site em 7 dias.'
                : 'Só '.Texto::inteiro($p['carrinhos']).' carrinhos em '.Texto::inteiro($p['consentidos']).' visitantes que aceitaram cookies (bom é '.Texto::pct(self::CARRINHO_MIN).' ou mais).';
            $barato = $p['mais_barato'];

            if ($barato !== null) {
                // Mesma chave da regra de preço: fica a versão mais urgente, com o motivo do anúncio.
                $sugestoes[] = $base([
                    'chave' => 'preco:baixar:'.$id,
                    'area' => Sugestao::AREA_PRECO,
                    'tipo' => 'nao_compram_preco',
                    'prioridade' => 1,
                    'acao' => 'Chegam na página e não compram: '.$barato['loja'].' vende por '.Texto::reais($barato['preco']).' e você por '.Texto::reais($p['preco']).'. Baixe o preço ou mostre por que o seu vale mais.',
                    'motivo' => $motivo.' Muito clique e pouca venda com concorrente mais barato é preço.',
                ]);
            } else {
                $sugestoes[] = $base([
                    'chave' => 'pagina:nao_compram:'.$id,
                    'area' => Sugestao::AREA_PAGINA,
                    'tipo' => 'nao_compram',
                    'prioridade' => 1,
                    'acao' => $p['checagens'] !== []
                        ? 'Chegam na página e não compram. Corrija: '.implode('; ', $p['checagens']).'.'
                        : 'Chegam na página e não compram. Revise fotos, descrição, frete e avaliações do produto.',
                    'motivo' => $motivo.' O anúncio cumpre a parte dele (traz gente); o problema é a página.',
                ]);
            }
        }

        // 3. Põe no carrinho e desiste.
        if ($p['carrinhos'] >= self::CARRINHOS_MIN_CHECKOUT && $p['compras'] / $p['carrinhos'] * 100 < self::COMPRA_CARRINHO_MIN) {
            $sugestoes[] = $base([
                'chave' => 'pagina:checkout:'.$id,
                'area' => Sugestao::AREA_PAGINA,
                'tipo' => 'abandono_checkout',
                'prioridade' => 2,
                'acao' => 'Põem no carrinho e desistem. Confira o frete para as regiões do anúncio e as formas de pagamento.',
                'motivo' => Texto::inteiro($p['compras']).' compras para '.Texto::inteiro($p['carrinhos']).' carrinhos (bom é '.Texto::pct(self::COMPRA_CARRINHO_MIN).' ou mais).',
            ]);
        }

        // 4. Resultado.
        $empate = $p['roas_empate'];
        if ($empate === null) {
            return $sugestoes;
        }
        if ($empate <= 0) {
            // Pela estimativa não manda pausar: sem venda no site, a margem não é conhecida.
            if ($p['margem_estimada']) {
                return $sugestoes;
            }
            $sugestoes[] = $base([
                'chave' => 'orcamento:sem_margem:'.$id,
                'area' => Sugestao::AREA_ORCAMENTO,
                'tipo' => 'sem_margem',
                'prioridade' => 1,
                'acao' => 'Este produto não dá lucro nem antes do ADS. Pause os anúncios até rever o preço ou o custo.',
                'motivo' => 'Nas vendas do site dos últimos 60 dias, o preço de '.Texto::reais($p['preco']).' não cobriu custo, taxas e frete.',
            ]);

            return $sugestoes;
        }

        $lucroPorVenda = $p['preco'] / $empate;
        $roasSite = $p['gasto'] > 0 ? $p['receita_site'] / $p['gasto'] : null;
        $roasMeta = $p['gasto'] > 0 ? $p['vendas_meta'] / $p['gasto'] : null;

        if ($roasSite !== null && $roasSite < $empate && $p['gasto'] >= self::VENDAS_DE_GASTO_PARA_JULGAR * $lucroPorVenda) {
            $sugestoes[] = $base([
                'chave' => 'orcamento:prejuizo:'.$id,
                'area' => Sugestao::AREA_ORCAMENTO,
                'tipo' => 'prejuizo',
                'prioridade' => 1,
                'acao' => 'O ADS deste produto está custando mais do que o lucro que ele traz. Pause os anúncios ou suba o preço.',
                'motivo' => Texto::reais($p['gasto']).' gastos em 7 dias e '.Texto::reais($p['receita_site']).' vendidos no site (ROAS '.Texto::num($roasSite).'; para empatar precisa de '.Texto::num($empate).'). Conta com TODAS as vendas do produto no site, então o prejuízo real pode ser maior.',
            ]);
        } elseif ($roasMeta !== null && $roasMeta >= self::ESCALAR_FATOR * $empate && $p['compras'] >= self::ESCALAR_COMPRAS_MIN) {
            $sugestoes[] = $base([
                'chave' => 'orcamento:escalar:'.$id,
                'area' => Sugestao::AREA_ORCAMENTO,
                'tipo' => 'escalar',
                'prioridade' => 2,
                'acao' => 'Está dando lucro. Dá para subir o orçamento em 20% e acompanhar por 3 dias.',
                'motivo' => 'ROAS atribuído pela Meta de '.Texto::num($roasMeta).' com '.Texto::inteiro($p['compras']).' compras; o empate é '.Texto::num($empate).'.',
            ]);
        }

        return $sugestoes;
    }
}
