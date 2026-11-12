<?php

namespace App\Modules\Admin\Support\Publicidade;

use App\Services\Meta\MetaAdsException;
use App\Services\Meta\MetaAdsService;
use Illuminate\Support\Facades\Cache;

/**
 * Campanhas da Meta por loja (pedido do Lira em 2026-11-11): a KazaKora e a
 * FerraKora têm contas de anúncios separadas, e o mesmo token de usuário do
 * sistema lê as duas. A tela da Meta ADS mostra as campanhas de uma, da
 * outra ou das duas, com as métricas do período.
 *
 * De onde vem cada número (nada é recalculado aqui além das divisões):
 *  - Cartões da loja: insights da CONTA inteira (MetaAdsService::resumoDaConta),
 *    que inclui campanha excluída e conta o alcance sem repetir pessoa.
 *  - Linhas: o espelho (MetaAdsService::espelhoCompleto), que já escolhe um
 *    tipo de ação só por evento (omni_* > padrão > offsite_conversion.fb_pixel_*),
 *    sem somar o mesmo carrinho/checkout/compra duas vezes.
 *
 * Limite de chamadas da Graph API (código 17): o resultado de cada conta e
 * período fica guardado 5 minutos; se a Meta recusar por limite, a conta
 * fica 3 minutos sem consulta e a tela mostra o aviso em vez de quebrar.
 *
 * Só leitura. ROAS é o da Meta (venda atribuída / gasto); lucro é sempre
 * ContributionMargin, e nada daqui vai para channel_ad_spends.
 */
class CampanhasMetaPorLoja
{
    public const KAZAKORA = 'kazakora';

    public const FERRAKORA = 'ferrakora';

    public const AMBAS = 'ambas';

    /** Loja => [nome, chave de config da conta de anúncios]. */
    private const LOJAS = [
        self::KAZAKORA => ['KazaKora', 'services.meta_ads.ad_account_id'],
        self::FERRAKORA => ['FerraKora', 'services.meta_ads.ferrakora_ad_account_id'],
    ];

    /** Períodos do painel (date_preset da Meta => rótulo). */
    public const PERIODOS = [
        'today' => 'Hoje',
        'yesterday' => 'Ontem',
        'last_7d' => 'Últimos 7 dias',
        'last_30d' => 'Últimos 30 dias',
        'this_month' => 'Mês atual',
    ];

    public const PERIODO_PADRAO = 'last_7d';

    private const CACHE = 'meta-ads:painel-lojas:v2:';

    private const PAUSA = 'meta-ads:painel-lojas:pausa:';

    public function __construct(private readonly MetaAdsService $meta) {}

    /**
     * Lojas com conta de anúncios configurada (a KazaKora só entra com a
     * Meta configurada; a FerraKora, com a conta dela preenchida).
     *
     * @return array<string, string> chave => nome
     */
    public function lojas(): array
    {
        if (! $this->meta->configurado()) {
            return [];
        }

        $lojas = [];
        foreach (self::LOJAS as $chave => [$nome, $config]) {
            if (filled(config($config))) {
                $lojas[$chave] = $nome;
            }
        }

        return $lojas;
    }

    /** Escolha da tela, caindo no que existe (sem FerraKora, só KazaKora). */
    public function loja(?string $pedida): string
    {
        $lojas = $this->lojas();

        if ($pedida === self::AMBAS && count($lojas) > 1) {
            return self::AMBAS;
        }

        return isset($lojas[$pedida]) ? (string) $pedida : (array_key_first($lojas) ?? self::KAZAKORA);
    }

    /**
     * Uma loja só (sem "ambas"), para a tela de campanhas (Lira 2026-11-11:
     * campanha, conjuntos e anúncios da FerraKora no menu Meta).
     */
    public function umaLoja(?string $pedida): string
    {
        return isset($this->lojas()[$pedida]) ? (string) $pedida : self::KAZAKORA;
    }

    /** O serviço da Meta lendo a conta da loja (KazaKora = o padrão). */
    public function servicoDa(string $loja): MetaAdsService
    {
        return $loja === self::KAZAKORA || ! isset(self::LOJAS[$loja]) || blank(config(self::LOJAS[$loja][1]))
            ? $this->meta
            : $this->meta->naConta((string) config(self::LOJAS[$loja][1]));
    }

    public static function periodo(?string $pedido): string
    {
        return array_key_exists((string) $pedido, self::PERIODOS) ? (string) $pedido : self::PERIODO_PADRAO;
    }

    /**
     * Uma entrada por loja escolhida. Erro de uma loja não derruba a outra.
     *
     * @return list<array{loja: string, nome: string, conta: string, erro: ?string, totais: ?array<string, mixed>, campanhas: list<array<string, mixed>>, ocultas: int}>
     */
    public function montar(string $loja, string $periodo): array
    {
        $lojas = $this->lojas();
        $escolhidas = $loja === self::AMBAS ? array_keys($lojas) : array_intersect([$loja], array_keys($lojas));

        return array_values(array_map(fn (string $chave) => $this->daLoja($chave, $lojas[$chave], $periodo), $escolhidas));
    }

    /** @return array{loja: string, nome: string, conta: string, erro: ?string, totais: ?array<string, mixed>, campanhas: list<array<string, mixed>>, ocultas: int} */
    private function daLoja(string $chave, string $nome, string $periodo): array
    {
        $conta = (string) config(self::LOJAS[$chave][1]);
        $base = ['loja' => $chave, 'nome' => $nome, 'conta' => $conta];
        $vazio = ['totais' => null, 'campanhas' => [], 'ocultas' => 0];

        if ($pausa = Cache::get(self::PAUSA.md5($conta))) {
            return $base + ['erro' => (string) $pausa] + $vazio;
        }

        try {
            return $base + ['erro' => null] + Cache::remember(
                self::CACHE.md5($conta.'|'.$periodo),
                now()->addMinutes(5),
                fn () => $this->lerDaMeta($this->meta->naConta($conta), $periodo),
            );
        } catch (MetaAdsException $erro) {
            if (in_array($erro->getCode(), MetaAdsService::ERROS_DE_LIMITE, true)) {
                Cache::put(self::PAUSA.md5($conta), $erro->getMessage(), now()->addMinutes(3));
            }

            return $base + ['erro' => $erro->getMessage()] + $vazio;
        }
    }

    /**
     * @return array{totais: array<string, mixed>, campanhas: list<array<string, mixed>>, ocultas: int}
     *
     * @throws MetaAdsException
     */
    private function lerDaMeta(MetaAdsService $meta, string $periodo): array
    {
        $totais = self::numeros($meta->resumoDaConta($periodo));
        $campanhas = $meta->espelhoCompleto($periodo)['campanhas'];

        // Campanha parada e sem gasto no período sai da lista padrão (é contada);
        // "Mostrar todas" (Lira 2026-11-12) mostra as marcadas como "oculta".
        $oculta = fn (array $c) => ($c['status'] ?? '') !== 'ACTIVE' && (float) ($c['metricas']['gasto'] ?? 0) <= 0;
        // Visíveis primeiro, na ordem da Meta; as paradas no fim.
        usort($campanhas, fn (array $a, array $b) => $oculta($a) <=> $oculta($b));

        return [
            'totais' => $totais,
            'ocultas' => count(array_filter($campanhas, $oculta)),
            'campanhas' => array_map(fn (array $c) => [
                'id' => (string) $c['id'],
                'oculta' => $oculta($c),
                'nome' => (string) $c['nome'],
                'status' => (string) $c['status'],
                'objetivo' => $c['objetivo'] ?? null,
                'orcamento_nivel' => $c['orcamento_nivel'] ?? 'campanha',
                'orcamento_diario' => $c['orcamento_diario'] ?? null,
                'gerenciador' => $c['gerenciador'] ?? null,
                'anuncios' => self::anuncios($c),
            ] + self::numeros($c['metricas'] ?? []), $campanhas),
        ];
    }

    /**
     * Anúncios da campanha (de todos os conjuntos), mais gasto primeiro.
     * Na FerraKora são os 10 vídeos UGC (utm_content = nome do anúncio).
     *
     * @param  array<string, mixed>  $campanha
     * @return list<array<string, mixed>>
     */
    private static function anuncios(array $campanha): array
    {
        $lista = [];
        foreach ($campanha['conjuntos'] ?? [] as $conjunto) {
            foreach ($conjunto['anuncios'] ?? [] as $a) {
                $lista[] = [
                    'id' => (string) $a['id'],
                    'nome' => (string) $a['nome'],
                    'status' => (string) $a['status'],
                    'conjunto' => (string) $conjunto['nome'],
                    'miniatura' => $a['miniatura'] ?? null,
                    'gerenciador' => $a['gerenciador'] ?? null,
                ] + self::numeros($a['metricas'] ?? []);
            }
        }

        usort($lista, fn ($x, $y) => [$y['gasto'], $x['nome']] <=> [$x['gasto'], $y['nome']]);

        return $lista;
    }

    /**
     * As colunas do painel a partir das métricas do MetaAdsService. Cliques,
     * CTR e CPC são do clique NO LINK (o que leva para a loja).
     *
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    public static function numeros(array $m): array
    {
        $gasto = round((float) ($m['gasto'] ?? 0), 2);
        $impressoes = (int) ($m['impressoes'] ?? 0);
        $compras = (int) ($m['compras'] ?? 0);

        return [
            'gasto' => $gasto,
            'impressoes' => $impressoes,
            'alcance' => (int) ($m['alcance'] ?? 0),
            'cliques_link' => $m['cliques_link'] ?? null,
            'ctr_link' => $m['ctr_link'] ?? null,
            'cpc_link' => $m['cpc_link'] ?? null,
            'cpm' => $m['cpm'] ?? ($impressoes > 0 ? round($gasto / $impressoes * 1000, 2) : null),
            'carrinho' => (int) ($m['carrinho'] ?? 0),
            'checkout' => (int) ($m['checkout'] ?? 0),
            'compras' => $compras,
            'vendas' => round((float) ($m['vendas'] ?? 0), 2),
            'roas' => $m['roas'] ?? null,
            'custo_compra' => $compras > 0 ? round($gasto / $compras, 2) : null,
        ];
    }
}
