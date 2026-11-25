<?php

namespace App\Modules\Admin\Support\Publicidade;

use App\Services\Meta\MetaAdsException;
use App\Services\Meta\MetaAdsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Leitura das campanhas (pedido do Lira em 25/11/2026): uma linha por
 * CONJUNTO (a análise é no conjunto, regra do professor), com semáforo em
 * cada número e uma frase "O que fazer" em português simples.
 *
 * Ordem de leitura (a mesma das colunas e do "O que fazer"):
 *   Atenção (hook, frequência) → Entrega (gasto, CPM) → Clique (CTR, CPC)
 *   → Funil (página, carrinho, checkout) → Venda (compras, CPA, ROAS) → Lucro.
 *
 * De onde vem cada número:
 *  - Graph API, só leitura (MetaAdsService::conjuntosParaLeitura e
 *    insightsPorConjunto), com o mesmo token e a mesma escolha de conta do
 *    CampanhasMetaPorLoja. Guardado 5 min (config leitura_meta.cache_minutos);
 *    o botão Atualizar apaga e lê de novo.
 *  - Hook = visualizações de 3 s (action video_view) ÷ impressões; sem vídeo, null.
 *  - CPA alvo/máximo e custos do produto: CpaDoProduto (produto do link do anúncio).
 *  - Lucro real (estimado) = valor de conversão − gasto − compras × (custo +
 *    frete médio + insumos) − valor de conversão × (imposto + taxa).
 *
 * Ciclo: início do teste por campanha (id), por produto ou por pedaço do
 * nome (tabela leitura_meta_ciclos); sem nada gravado, vale o início da
 * campanha na Meta. A Meta só separa os números por DIA, então o ciclo conta
 * o dia inteiro do início (as horas antes da hora gravada entram).
 *
 * As regras de corte e escala ("gastou X sem vender", "pode subir 20%")
 * usam os números DIÁRIOS do ciclo, qualquer que seja o período escolhido;
 * a leitura do funil usa o período da tela.
 */
class LeituraCampanhasMeta
{
    public const PERIODOS = [
        'today' => 'Hoje',
        'yesterday' => 'Ontem',
        'last_7d' => '7 dias',
        'last_14d' => '14 dias',
        'ciclo' => 'Desde o início do ciclo',
    ];

    public const PERIODO_PADRAO = 'ciclo';

    public const STATUS = ['ativos' => 'Ativos', 'todos' => 'Todos'];

    private const FUSO = 'America/Sao_Paulo';

    private const CACHE = 'meta-ads:leitura:v1:';

    private const PAUSA = 'meta-ads:leitura:pausa:';

    public function __construct(
        private readonly CampanhasMetaPorLoja $lojas,
        private readonly CpaDoProduto $cpa,
    ) {}

    public static function periodo(?string $pedido): string
    {
        return array_key_exists((string) $pedido, self::PERIODOS) ? (string) $pedido : self::PERIODO_PADRAO;
    }

    public static function status(?string $pedido): string
    {
        return array_key_exists((string) $pedido, self::STATUS) ? (string) $pedido : 'ativos';
    }

    /**
     * @return array{erro: ?string, linhas: list<array<string, mixed>>, gerado_em: ?string}
     */
    public function montar(string $loja, string $periodo, string $status, bool $atualizar = false): array
    {
        $conta = (string) config($loja === CampanhasMetaPorLoja::FERRAKORA ? 'services.meta_ads.ferrakora_ad_account_id' : 'services.meta_ads.ad_account_id');
        $chave = self::CACHE.md5($conta.'|'.$periodo.'|'.$status.'|'.$this->assinaturaDosCiclos($loja));

        if ($atualizar) {
            Cache::forget($chave);
            Cache::forget(self::PAUSA.md5($conta));
        }
        if ($pausa = Cache::get(self::PAUSA.md5($conta))) {
            return ['erro' => (string) $pausa, 'linhas' => [], 'gerado_em' => null];
        }

        try {
            $lido = Cache::remember($chave, now()->addMinutes((int) config('leitura_meta.cache_minutos', 5)),
                fn () => $this->lerDaMeta($this->lojas->servicoDa($loja), $loja, $periodo, $status));
        } catch (MetaAdsException $erro) {
            if (in_array($erro->getCode(), MetaAdsService::ERROS_DE_LIMITE, true)) {
                Cache::put(self::PAUSA.md5($conta), $erro->getMessage(), now()->addMinutes(3));
            }

            return ['erro' => $erro->getMessage(), 'linhas' => [], 'gerado_em' => null];
        }

        $linhas = array_map(fn (array $c) => $this->linha($c, $loja), $lido['conjuntos']);
        // Primeiro o que pede ação (vermelho), depois o resto por gasto.
        $peso = ['vermelho' => 0, 'amarelo' => 1, 'verde' => 2, 'espera' => 3, 'ok' => 4, 'info' => 5];
        usort($linhas, fn ($a, $b) => [$peso[$a['acao']['nivel']] ?? 9, -$a['m']['gasto']] <=> [$peso[$b['acao']['nivel']] ?? 9, -$b['m']['gasto']]);

        return ['erro' => null, 'linhas' => $linhas, 'gerado_em' => $lido['gerado_em']];
    }

    /**
     * Tudo o que vem da Meta (é isto que fica guardado alguns minutos).
     *
     * @return array{conjuntos: list<array<string, mixed>>, gerado_em: string}
     *
     * @throws MetaAdsException
     */
    private function lerDaMeta(MetaAdsService $meta, string $loja, string $periodo, string $status): array
    {
        $conjuntos = $meta->conjuntosParaLeitura($status === 'ativos');
        $hoje = now(self::FUSO)->startOfDay();
        $ids = array_map(fn ($c) => $c['id'], $conjuntos);

        // Ciclo de cada conjunto (produto do link entra na escolha).
        foreach ($conjuntos as &$c) {
            $c['product_id'] = null;
            foreach ($c['links'] as $link) {
                if ($c['product_id'] = CpaDoProduto::produtoDoLink($link)) {
                    break;
                }
            }
            $c['ciclo'] = $this->cicloDe($loja, $c);
        }
        unset($c);

        // Diário: do ciclo mais antigo (no máximo N dias) até hoje.
        $limite = $hoje->copy()->subDays(max(1, (int) config('leitura_meta.dias_diarios_max', 90)) - 1);
        $desde = collect($conjuntos)->map(fn ($c) => $c['ciclo']['inicio'] ? Carbon::parse($c['ciclo']['inicio'], self::FUSO)->startOfDay() : null)
            ->filter()->push($hoje->copy()->subDays(13))->min();
        $desde = $desde->lt($limite) ? $limite : $desde;
        $diario = collect($ids ? $meta->insightsPorConjunto(['since' => $desde->toDateString(), 'until' => $hoje->toDateString()], $ids, true) : [])
            ->groupBy('conjunto_id');

        // Números do período da tela.
        $doPeriodo = [];
        if ($periodo === 'ciclo') {
            $porInicio = collect($conjuntos)->filter(fn ($c) => $c['ciclo']['inicio'])
                ->groupBy(fn ($c) => Carbon::parse($c['ciclo']['inicio'], self::FUSO)->toDateString());
            foreach ($porInicio as $dia => $lista) {
                $inicio = max($dia, $limite->toDateString());
                foreach ($meta->insightsPorConjunto(['since' => $inicio, 'until' => $hoje->toDateString()], $lista->pluck('id')->all()) as $l) {
                    $doPeriodo[$l['conjunto_id']] = $l;
                }
            }
        } elseif ($ids) {
            foreach ($meta->insightsPorConjunto($periodo, $ids) as $l) {
                $doPeriodo[$l['conjunto_id']] = $l;
            }
        }

        return [
            'conjuntos' => array_map(fn ($c) => $c + [
                'periodo' => $doPeriodo[$c['id']] ?? null,
                'dias' => $diario->get($c['id'], collect())->map(fn ($d) => [
                    'data' => $d['data'], 'gasto' => $d['gasto'], 'compras' => $d['compras'], 'carrinho' => $d['carrinho'],
                ])->sortBy('data')->values()->all(),
            ], $conjuntos),
            'gerado_em' => now(self::FUSO)->toIso8601String(),
        ];
    }

    /**
     * Início do ciclo: campanha (id) > produto > pedaço do nome > início da
     * campanha na Meta.
     *
     * @param  array<string, mixed>  $c
     * @return array{inicio: ?string, origem: ?string}
     */
    private function cicloDe(string $loja, array $c): array
    {
        $ciclos = $this->ciclos($loja);
        $achado = $ciclos->first(fn ($x) => $x->campanha_id !== null && (string) $x->campanha_id === $c['campanha_id']);
        $origem = 'campanha';
        if (! $achado && $c['product_id']) {
            $achado = $ciclos->first(fn ($x) => $x->product_id !== null && (int) $x->product_id === (int) $c['product_id']);
            $origem = 'produto';
        }
        if (! $achado) {
            $achado = $ciclos->first(fn ($x) => filled($x->nome_contem) && mb_stripos($c['campanha'], (string) $x->nome_contem) !== false);
            $origem = 'nome';
        }

        if ($achado) {
            return ['inicio' => Carbon::parse($achado->inicio, self::FUSO)->format('Y-m-d H:i'), 'origem' => $origem];
        }

        return ['inicio' => $c['campanha_inicio'] ? Carbon::parse($c['campanha_inicio'], self::FUSO)->format('Y-m-d H:i') : null, 'origem' => $c['campanha_inicio'] ? 'meta' : null];
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function ciclos(string $loja)
    {
        return DB::table('leitura_meta_ciclos')->where('loja', $loja)->orderByDesc('id')->get();
    }

    /** Muda quando um ciclo é salvo: o guardado da Meta fica velho e é lido de novo. */
    private function assinaturaDosCiclos(string $loja): string
    {
        return md5((string) json_encode($this->ciclos($loja)->map(fn ($x) => [$x->id, $x->inicio, $x->campanha_id, $x->product_id, $x->nome_contem])));
    }

    /** Grava (ou apaga, com $inicio vazio) o início do ciclo da campanha. */
    public static function salvarCiclo(string $loja, string $campanhaId, ?string $inicio): void
    {
        if ($inicio === null || $inicio === '') {
            DB::table('leitura_meta_ciclos')->where('loja', $loja)->where('campanha_id', $campanhaId)->delete();

            return;
        }

        DB::table('leitura_meta_ciclos')->updateOrInsert(['loja' => $loja, 'campanha_id' => $campanhaId], [
            'inicio' => Carbon::parse($inicio, self::FUSO)->format('Y-m-d H:i:s'),
            'updated_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * A linha da tela: números do período, semáforos, lucro e "O que fazer".
     *
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function linha(array $c, string $loja): array
    {
        $p = $c['periodo'] ?? [];
        $produto = $c['product_id'] ? $this->cpa->doProduto((int) $c['product_id'], $loja) : null;
        $impressoes = (int) ($p['impressoes'] ?? 0);
        $gasto = (float) ($p['gasto'] ?? 0);
        $compras = (int) ($p['compras'] ?? 0);
        $vendas = (float) ($p['vendas'] ?? 0);

        $m = [
            'gasto' => round($gasto, 2),
            'impressoes' => $impressoes,
            'hook' => isset($p['video_3s']) && $impressoes > 0 ? round($p['video_3s'] / $impressoes * 100, 1) : null,
            'frequencia' => $p['frequencia'] ?? null,
            'cpm' => $p['cpm'] ?? ($impressoes > 0 ? round($gasto / $impressoes * 1000, 2) : null),
            'ctr_link' => $p['ctr_link'] ?? null,
            'cpc_link' => $p['cpc_link'] ?? null,
            'cliques_link' => $p['cliques_link'] ?? null,
            'visualizacoes_pagina' => (int) ($p['visualizacoes_pagina'] ?? 0),
            'custo_pagina' => $p['custo_pagina'] ?? null,
            'carrinho' => (int) ($p['carrinho'] ?? 0),
            'custo_carrinho' => $p['custo_carrinho'] ?? null,
            'checkout' => (int) ($p['checkout'] ?? 0),
            'custo_checkout' => $p['custo_checkout'] ?? null,
            'compras' => $compras,
            'cpa' => $compras > 0 ? round($gasto / $compras, 2) : null,
            'roas' => $p['roas'] ?? null,
            'vendas' => round($vendas, 2),
            'lucro' => $produto ? self::lucro($vendas, $gasto, $compras, $produto) : null,
            // TODO(professor): "Métrica do professor", fórmula a definir com o Lira.
            // Ponto de extensão: metricaDoProfessor(); a coluna fica escondida
            // na tela enquanto config('leitura_meta.metrica_professor') for falsa.
            'professor' => self::metricaDoProfessor($p, $produto),
        ];

        $ciclo = self::numerosDoCiclo($c['dias'] ?? [], $c['ciclo']['inicio'] ?? null);
        $orcamento = $c['orcamento_diario'] !== null
            ? ['valor' => $c['orcamento_diario'], 'nivel' => 'conjunto']
            : ($c['campanha_orcamento_diario'] !== null ? ['valor' => $c['campanha_orcamento_diario'], 'nivel' => 'campanha'] : null);

        return [
            'id' => $c['id'],
            'nome' => $c['nome'],
            'status' => $c['status'],
            'campanha_id' => $c['campanha_id'],
            'campanha' => $c['campanha'],
            'gerenciador' => $c['gerenciador'],
            'orcamento' => $orcamento,
            'produto' => $produto,
            'ciclo' => $c['ciclo'] + $ciclo,
            'm' => $m,
            'sem' => [
                'hook' => self::semaforo('hook', $m['hook']),
                'frequencia' => self::semaforo('frequencia', $m['frequencia']),
                'cpm' => self::semaforo('cpm', $m['cpm']),
                'ctr' => self::semaforo('ctr', $m['ctr_link']),
                'cpc' => self::semaforo('cpc', $m['cpc_link']),
                'cpa' => self::semaforoCpa($m['cpa'], $produto['cpa_alvo'] ?? null, $produto['cpa_maximo'] ?? null),
            ],
            'acao' => self::oQueFazer($m, $ciclo, $produto ? ['alvo' => $produto['cpa_alvo'], 'maximo' => $produto['cpa_maximo']] : null, $orcamento),
        ];
    }

    /**
     * Lucro real (estimado) do conjunto.
     *
     * @param  array<string, mixed>  $produto  saída de CpaDoProduto::doProduto
     */
    public static function lucro(float $valor, float $gasto, int $compras, array $produto): float
    {
        $porVenda = (float) $produto['custo'] + (float) $produto['frete'] + (float) $produto['insumos'];
        $percentual = ((float) $produto['imposto_pct'] + (float) $produto['taxa_pct']) / 100;

        return round($valor - $gasto - $compras * $porVenda - $valor * $percentual, 2);
    }

    /**
     * TODO(professor): "Métrica do professor" — fórmula ainda não definida.
     * Quando o Lira passar a conta, implemente aqui (recebe os números do
     * período e o produto) e ligue LEITURA_META_METRICA_PROFESSOR.
     *
     * @param  array<string, mixed>  $periodo
     * @param  array<string, mixed>|null  $produto
     */
    public static function metricaDoProfessor(array $periodo, ?array $produto): ?float
    {
        return null;
    }

    /**
     * Gasto e vendas do ciclo pelos números diários.
     *
     * @param  list<array{data: string, gasto: float, compras: int, carrinho: int}>  $dias
     * @return array{gasto: float, compras: int, carrinho: int, gasto_sem_venda: float, ultimos2_gasto: float, ultimos2_compras: int}
     */
    public static function numerosDoCiclo(array $dias, ?string $inicio, ?Carbon $hoje = null): array
    {
        $hoje ??= now(self::FUSO)->startOfDay();
        $desde = $inicio ? Carbon::parse($inicio, self::FUSO)->toDateString() : '0000-00-00';
        $doCiclo = collect($dias)->filter(fn ($d) => $d['data'] >= $desde)->sortBy('data')->values();

        // Gasto depois do último dia com venda (o próprio dia da venda não conta: a Meta não diz a hora).
        $ultimaVenda = $doCiclo->filter(fn ($d) => $d['compras'] > 0)->last();
        $semVenda = $ultimaVenda ? $doCiclo->filter(fn ($d) => $d['data'] > $ultimaVenda['data']) : $doCiclo;

        // Os 2 últimos dias fechados = ontem e anteontem.
        $fechados = [$hoje->copy()->subDays(2)->toDateString(), $hoje->copy()->subDay()->toDateString()];
        $ultimos2 = $doCiclo->filter(fn ($d) => in_array($d['data'], $fechados, true));

        return [
            'gasto' => round((float) $doCiclo->sum('gasto'), 2),
            'compras' => (int) $doCiclo->sum('compras'),
            'carrinho' => (int) $doCiclo->sum('carrinho'),
            'gasto_sem_venda' => round((float) $semVenda->sum('gasto'), 2),
            'ultimos2_gasto' => round((float) $ultimos2->sum('gasto'), 2),
            'ultimos2_compras' => (int) $ultimos2->sum('compras'),
            'ultimos2_completos' => $ultimos2->count() === 2,
        ];
    }

    /**
     * Cor do número pelas faixas de config/leitura_meta.php. null = sem dado.
     *
     * @return array{cor: string, rotulo: string}|null
     */
    public static function semaforo(string $metrica, int|float|null $valor): ?array
    {
        $f = config("leitura_meta.faixas.{$metrica}");
        if ($valor === null || ! is_array($f)) {
            return null;
        }

        $cor = isset($f['verde_min'])
            ? ($valor >= $f['verde_min'] ? 'verde' : ($valor >= $f['amarelo_min'] ? 'amarelo' : 'vermelho'))
            : ($valor <= $f['verde_max'] ? 'verde' : ($valor <= $f['amarelo_max'] ? 'amarelo' : 'vermelho'));

        return ['cor' => $cor, 'rotulo' => self::ROTULOS[$cor]];
    }

    /** @return array{cor: string, rotulo: string}|null */
    public static function semaforoCpa(?float $cpa, ?float $alvo, ?float $maximo): ?array
    {
        if ($cpa === null || $alvo === null || $maximo === null) {
            return null;
        }
        $cor = $cpa <= $alvo ? 'verde' : ($cpa <= $maximo ? 'amarelo' : 'vermelho');

        return ['cor' => $cor, 'rotulo' => self::ROTULOS[$cor]];
    }

    private const ROTULOS = ['verde' => 'Bom', 'amarelo' => 'Atenção', 'vermelho' => 'Ruim'];

    /**
     * "O que fazer": UMA frase, a do primeiro problema.
     *
     * Ordem: 1) corte (gasto desde a última venda ≥ CPA máximo) › 2) escala
     * (CPA ≤ alvo nos 2 últimos dias fechados) › 3) cedo (gastou menos que o
     * alvo e não vendeu) › 4) fraco (gastou o alvo sem carrinho) › 5) funil
     * na ordem hook → frequência → CPM → CTR → CPC → página → carrinho →
     * checkout → compra › 6) tudo certo. Nos casos 3 e 4 o primeiro problema
     * do funil vai junto como "motivo".
     *
     * @param  array<string, mixed>  $m  números do período
     * @param  array<string, mixed>  $ciclo  numerosDoCiclo()
     * @param  array{alvo: float, maximo: float}|null  $cpa
     * @param  array{valor: float, nivel: string}|null  $orcamento
     * @return array{nivel: string, frase: string, motivo: ?string}
     */
    public static function oQueFazer(array $m, array $ciclo, ?array $cpa, ?array $orcamento): array
    {
        $funil = self::problemaDoFunil($m);
        $r = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');
        $passo = (float) config('leitura_meta.ajuste_orcamento', 0.2);
        $de = fn (int $sinal) => $orcamento
            ? ' (de '.$r($orcamento['valor']).' para '.$r(round($orcamento['valor'] * (1 + $sinal * $passo), 2)).($orcamento['nivel'] === 'campanha' ? ', orçamento da campanha' : '').')'
            : ' o orçamento';
        $pct = (int) round($passo * 100);

        if ($cpa !== null && $cpa['maximo'] > 0) {
            if ($ciclo['gasto_sem_venda'] >= $cpa['maximo']) {
                return ['nivel' => 'vermelho', 'frase' => '🔴 Gastou '.$r($ciclo['gasto_sem_venda']).' sem vender (o seu 0 a 0 é '.$r($cpa['maximo']).'). Desligue o conjunto.', 'motivo' => $funil];
            }
            if ($ciclo['ultimos2_compras'] > 0 && $ciclo['ultimos2_gasto'] / $ciclo['ultimos2_compras'] <= $cpa['alvo']) {
                return ['nivel' => 'verde', 'frase' => "🟢 Pode subir {$pct}%".$de(1).', entre 0 h e 10 h, e espere 2 dias.', 'motivo' => null];
            }
            if ($ciclo['compras'] === 0 && $ciclo['gasto'] < $cpa['alvo']) {
                return ['nivel' => 'espera', 'frase' => '⏳ Ainda cedo: espere gastar '.$r($cpa['alvo']).' antes de julgar.', 'motivo' => $funil];
            }
            if ($ciclo['gasto'] >= $cpa['alvo'] && $ciclo['carrinho'] === 0) {
                return ['nivel' => 'amarelo', 'frase' => "🟡 Fraco: baixe {$pct}%".$de(-1).', não desligue.', 'motivo' => $funil];
            }
        }

        if ($funil !== null) {
            return ['nivel' => 'amarelo', 'frase' => '🟡 '.$funil, 'motivo' => null];
        }

        if ($cpa === null) {
            return ['nivel' => 'info', 'frase' => 'ℹ️ Não achei o produto no link do anúncio: sem CPA alvo, só dá para ler o funil.', 'motivo' => null];
        }

        return $m['compras'] > 0
            ? ['nivel' => 'ok', 'frase' => '✅ Vendendo dentro do esperado: mantenha e não mexa por 2 dias.', 'motivo' => null]
            : ['nivel' => 'ok', 'frase' => '✅ Números bons até aqui: deixe rodar até vender ou bater o CPA máximo.', 'motivo' => null];
    }

    /** Primeiro número ruim, na ordem de leitura do professor. */
    public static function problemaDoFunil(array $m): ?string
    {
        $ruim = fn (string $metrica, $valor) => (self::semaforo($metrica, $valor)['cor'] ?? null) === 'vermelho';
        $f = config('leitura_meta.funil');
        $cliques = (int) ($m['cliques_link'] ?? 0);

        return match (true) {
            $ruim('hook', $m['hook'] ?? null) => 'Hook baixo (quase ninguém para no vídeo): troque o começo do vídeo.',
            $ruim('frequencia', $m['frequencia'] ?? null) => 'Frequência alta: as mesmas pessoas já viram demais. Troque o criativo.',
            $ruim('cpm', $m['cpm'] ?? null) => 'CPM alto: a Meta está cobrando caro para mostrar. Teste público mais aberto ou outro criativo.',
            $ruim('ctr', $m['ctr_link'] ?? null) => 'CTR baixo, o anúncio não convence a clicar: teste outro gancho/texto.',
            $ruim('cpc', $m['cpc_link'] ?? null) => 'CPC alto, o clique está caro: teste outro gancho/texto.',
            $cliques >= 20 && $m['visualizacoes_pagina'] / $cliques < $f['pagina_por_clique_min'] => 'Clicam e não esperam a página abrir: deixe a página mais rápida.',
            $m['visualizacoes_pagina'] >= $f['paginas_sem_carrinho'] && $m['carrinho'] === 0 => 'Visitam e não põem no carrinho: olhe a página, o preço e o frete.',
            $m['carrinho'] > 0 && $m['checkout'] === 0 && $m['compras'] === 0 => 'Carrinho sem compra: olhe a página, o preço e o frete.',
            $m['checkout'] > 0 && $m['compras'] === 0 => 'Checkout sem compra: olhe o frete e as formas de pagamento.',
            default => null,
        };
    }
}
