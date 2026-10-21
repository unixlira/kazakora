<?php

namespace App\Services\Meta;

use App\Modules\Marketplace\Models\MetaAdsCampanha;
use Illuminate\Support\Carbon;

/**
 * "O que fazer agora" do Meta ADS (pedido do Lira 2026-10-21; textos e
 * regras do Jonathan em Documents/2026-10-21-meta-ads-cta-e-diagnostico-
 * proposta.md). Classe pura: recebe o espelho já montado
 * (MetaAdsService::espelhoCompleto) e devolve os cartões. Não chama a Meta,
 * não grava nada e nenhuma sugestão é aplicada sozinha: a tela só leva ao
 * Gerenciador ou ao modal de editar que já existe.
 *
 * Limites em config/meta_ads_diagnostico.php. ROAS aqui é o da Meta (venda
 * atribuída ÷ gasto); o texto nunca chama isso de lucro.
 *
 * Só olha o que está ATIVO (effective_status ACTIVE): sugerir pausar o que
 * já está pausado não ajuda ninguém.
 */
class MetaAdsDiagnostico
{
    public const URGENTE = 'urgente';

    public const ATENCAO = 'atencao';

    public const OPORTUNIDADE = 'oportunidade';

    public const AVISO = 'aviso';

    private const ORDEM = [self::URGENTE => 0, self::ATENCAO => 1, self::OPORTUNIDADE => 2, self::AVISO => 3];

    private const VENDAS = 'OUTCOME_SALES';

    /** @var array<string, mixed> */
    private array $l;

    /** @param  array<string, mixed>  $limites  config('meta_ads_diagnostico') */
    public function __construct(array $limites = [])
    {
        $this->l = $limites + (require __DIR__.'/../../../config/meta_ads_diagnostico.php');
    }

    /**
     * @param  list<array<string, mixed>>  $campanhas  espelho (Campanha > conjuntos > anuncios)
     * @param  array{periodo?: string, gerado_em?: ?string, cpc_conta?: ?float, roas?: array<string, mixed>, agora?: Carbon|string|null}  $contexto
     * @return array<string, mixed>
     */
    public function analisar(array $campanhas, array $contexto = []): array
    {
        // Objetivo pelo código da Meta (OUTCOME_SALES); espelho antigo só tinha o rótulo.
        $campanhas = array_map(fn (array $c) => $c + [
            'objetivo_codigo' => array_search($c['objetivo'] ?? '', MetaAdsCampanha::OBJETIVOS, true) ?: '',
        ], $campanhas);
        $periodo = (string) ($contexto['periodo'] ?? 'maximum');
        $agora = Carbon::parse($contexto['agora'] ?? 'now', 'America/Sao_Paulo');
        $cpcConta = isset($contexto['cpc_conta']) && $contexto['cpc_conta'] > 0 ? (float) $contexto['cpc_conta'] : null;
        $roas = $contexto['roas'] ?? [];
        $padrao = $roas['padrao'] ?? ['valor' => (float) $this->l['roas_minimo_padrao'], 'origem' => MetaRoasMinimo::ORIGEM_CONFIG, 'margem' => null];
        $metaDe = fn (array $c) => $roas['campanhas'][$c['id']] ?? $padrao;

        $gastoEscopo = round(array_sum(array_map(fn ($c) => (float) ($c['metricas']['gasto'] ?? 0), $campanhas)), 2);
        $impressoesEscopo = (int) array_sum(array_map(fn ($c) => (int) ($c['metricas']['impressoes'] ?? 0), $campanhas));
        $cedo = $gastoEscopo < $this->l['minimo_gasto'] && $impressoesEscopo < $this->l['minimo_impressoes'];

        // Melhor anúncio ativo com venda (para onde mandar a verba na R7).
        $melhor = null;
        foreach ($this->anunciosAtivos($campanhas) as [$c, , $a]) {
            $m = $a['metricas'];
            if ($c['objetivo_codigo'] === self::VENDAS && $m['compras'] >= 1 && $m['roas'] !== null
                && ($melhor === null || $m['roas'] > $melhor['metricas']['roas'])) {
                $melhor = $a;
            }
        }

        $cartoes = [];
        $semDados = 0;

        foreach ($this->anunciosAtivos($campanhas) as [$c, $s, $a]) {
            $m = $a['metricas'];
            $meta = (float) $metaDe($c)['valor'];
            $vendas = $c['objetivo_codigo'] === self::VENDAS;
            $poucos = $m['gasto'] < $this->l['minimo_gasto'] && $m['impressoes'] < $this->l['minimo_impressoes'];
            $novo = fn (string $regra, string $prioridade, string $titulo, array $prova, string $acao) => $this->cartao($regra, $prioridade, $titulo, $prova, $acao, 'anuncio', $c, $s, $a);

            // R10: ativo há mais de 24 h e ninguém viu (no período "Hoje" o dia pode ter acabado de começar).
            $criado = isset($a['criado_em']) ? Carbon::parse($a['criado_em']) : null;
            if ($m['impressoes'] === 0 && $periodo !== 'today' && $criado && $criado->diffInHours($agora) >= $this->l['horas_sem_impressao']) {
                $cartoes[] = $novo('R10', self::URGENTE, 'Ativo, mas ninguém está vendo',
                    ['0 impressões', 'ativo desde '.$criado->setTimezone('America/Sao_Paulo')->format('d/m H:i')],
                    'Abra no Gerenciador e veja se o anúncio foi reprovado, se o público ficou pequeno demais ou se o orçamento acabou.');
            } elseif ($poucos) {
                $semDados++;
            }

            // R5: objetivo Vendas com botão de pouca intenção ou de conversa.
            $cta = $a['cta'] ?? null;
            if ($vendas && ($cta['desalinhado'] ?? false)) {
                $rotulo = $cta['sem_botao'] ? 'Sem botão' : ($cta['rotulos'][$cta['codigos'][0]] ?? $cta['codigos'][0]);
                $abaixo = ! $poucos && $m['roas'] !== null && $m['roas'] < $meta;
                $cartoes[] = $novo('R5', $abaixo ? self::ATENCAO : self::AVISO, "Campanha de vendas com botão \"{$rotulo}\"",
                    array_values(array_filter(['objetivo Vendas', 'botão '.$rotulo, $m['roas'] !== null ? 'ROAS '.$this->dec($m['roas']) : null])),
                    'Duplique o anúncio com o botão "Comprar agora" e deixe os dois rodando para comparar. Não troque no original: editar o criativo pode reiniciar o aprendizado.');
            }

            if ($poucos) {
                continue;
            }

            // R3: pouca gente clica.
            if ($m['impressoes'] >= $this->l['impressoes_para_ctr'] && $m['ctr_link'] !== null && $m['ctr_link'] < $this->l['ctr_link_min']) {
                $cartoes[] = $novo('R3', self::ATENCAO, 'Pouca gente clica: CTR de '.$this->dec($m['ctr_link']).'%',
                    [$this->numero($m['impressoes']).' impressões', $this->numero((int) $m['cliques_link']).' cliques no link', 'CTR '.$this->dec($m['ctr_link']).'%'],
                    'Troque a imagem ou os 3 primeiros segundos do vídeo, ou teste um carrossel com o produto em uso. Mostre o preço logo de cara.');
            }

            // R4: clique caro.
            if (($m['cliques_link'] ?? 0) >= $this->l['cliques_para_cpc'] && $m['cpc_link'] !== null) {
                $caro = $cpcConta !== null ? $m['cpc_link'] > $this->l['cpc_vezes_media'] * $cpcConta : $m['cpc_link'] > $this->l['cpc_teto_sem_historico'];
                if ($caro) {
                    $acao = 'Teste um público mais aberto ou um criativo novo.';
                    $prova = [$this->reais($m['cpc_link']).' por clique no link', $this->numero((int) $m['cliques_link']).' cliques'];
                    if ($cpcConta !== null) {
                        $acao .= ' O clique está saindo '.$this->dec($m['cpc_link'] / $cpcConta, 1).'× mais caro que a média da conta.';
                        $prova[] = 'média da conta '.$this->reais($cpcConta);
                    }
                    $cartoes[] = $novo('R4', self::ATENCAO, 'Clique caro: '.$this->reais($m['cpc_link']).' por clique', $prova, $acao);
                }
            }

            if (! $vendas) {
                continue;
            }

            // R1: gastou e não vendeu nada.
            if ($m['gasto'] >= $this->l['gasto_para_julgar'] && $m['compras'] === 0) {
                $cartoes[] = $novo('R1', self::URGENTE, $this->reais($m['gasto']).' gastos e nenhuma venda',
                    [$this->reais($m['gasto']).' gastos', $this->numero($m['impressoes']).' impressões', '0 compras'],
                    'Pause este anúncio ou troque o criativo. Antes, confira se o pixel está marcando compra no site.');
            }

            // R2: muita gente clicou e ninguém comprou; a ação depende de onde o funil para.
            if (($m['cliques_link'] ?? 0) >= $this->l['cliques_sem_compra'] && $m['compras'] === 0) {
                $cartoes[] = $novo('R2', self::URGENTE, $this->numero((int) $m['cliques_link']).' pessoas clicaram e ninguém comprou',
                    [$this->numero((int) $m['cliques_link']).' cliques no link', $this->numero($m['visualizacoes_pagina']).' abriram a página',
                        $this->numero($m['carrinho']).' no carrinho', $this->numero($m['checkout']).' no checkout', '0 compras'],
                    $this->acaoDoFunil($m));
            }

            // R7: vendeu menos do que gastou.
            if ($m['gasto'] >= $this->l['gasto_para_julgar'] && $m['compras'] >= 1 && $m['roas'] !== null && $m['roas'] < 1) {
                $acao = $melhor && $melhor['id'] !== $a['id'] && $melhor['metricas']['roas'] > $m['roas']
                    ? 'Pause ou corte o orçamento deste anúncio e passe a verba para o que tem melhor ROAS ('.$melhor['nome'].').'
                    : 'Pause ou corte o orçamento deste anúncio.';
                $cartoes[] = $novo('R7', self::URGENTE, 'Vendeu menos do que gastou: ROAS '.$this->dec($m['roas']),
                    [$this->reais($m['gasto']).' gastos', $this->reais($m['vendas']).' em vendas atribuídas', $this->numero($m['compras']).' '.($m['compras'] === 1 ? 'compra' : 'compras')],
                    $acao);
            }

            // R8: vende, mas abaixo do ROAS mínimo.
            if ($m['gasto'] >= $this->l['gasto_para_julgar'] && $m['roas'] !== null && $m['roas'] >= 1 && $m['roas'] < $meta) {
                $cartoes[] = $novo('R8', self::ATENCAO, 'ROAS '.$this->dec($m['roas']).', abaixo do mínimo de '.$this->dec($meta),
                    [$this->reais($m['gasto']).' gastos', $this->reais($m['vendas']).' em vendas atribuídas', 'mínimo '.$this->dec($meta)],
                    'Neste ROAS a venda não paga o produto, as taxas e o anúncio. Melhore o criativo ou a oferta antes de pôr mais verba.');
            }

            // R9: vendendo bem e fora do aprendizado.
            if ($m['gasto'] >= $this->l['gasto_para_julgar'] && $m['compras'] >= $this->l['compras_oportunidade']
                && $m['roas'] !== null && $m['roas'] >= $this->l['roas_oportunidade_vezes'] * $meta && ($s['aprendizado'] ?? null) !== 'LEARNING') {
                $cartoes[] = $novo('R9', self::OPORTUNIDADE, 'Este anúncio está vendendo bem: ROAS '.$this->dec($m['roas']),
                    [$this->numero($m['compras']).' compras', $this->reais($m['gasto']).' gastos', 'mínimo '.$this->dec($meta)],
                    'Aumente o orçamento do conjunto em até 20% e espere 3 dias antes de subir de novo. Aumento grande de uma vez costuma piorar o resultado.');
            }
        }

        foreach ($campanhas as $c) {
            foreach ($c['conjuntos'] ?? [] as $s) {
                if (($s['status'] ?? '') !== 'ACTIVE') {
                    continue;
                }
                $m = $s['metricas'];
                $novo = fn (string $regra, string $prioridade, string $titulo, array $prova, string $acao) => $this->cartao($regra, $prioridade, $titulo, $prova, $acao, 'conjunto', $c, $s, null);

                // R6: frequência alta. (A versão "> 3 com CTR em queda" precisa da série diária; ficou de fora.)
                if (($m['frequencia'] ?? null) !== null && $m['frequencia'] > $this->l['frequencia_max']) {
                    $cartoes[] = $novo('R6', self::ATENCAO, 'As mesmas pessoas já viram '.$this->dec($m['frequencia'], 1).' vezes',
                        ['frequência '.$this->dec($m['frequencia'], 1), $this->numero($m['alcance']).' pessoas alcançadas'],
                        'Coloque um criativo novo neste conjunto ou amplie o público. Quem já viu muitas vezes para de clicar.');
                }

                // R11 / R12: fase de aprendizado (learning_stage_info).
                $aprendizado = $s['aprendizado'] ?? null;
                if ($aprendizado === 'LEARNING') {
                    $cartoes[] = $novo('R11', self::AVISO, 'Conjunto em fase de aprendizado', ['aprendizado em andamento'],
                        'Não mexa em orçamento, público nem criativo por enquanto. Cada mudança faz a Meta começar a aprender de novo.');
                } elseif (in_array($aprendizado, ['FAIL', 'LIMITED'], true)) {
                    $cartoes[] = $novo('R12', self::ATENCAO, 'Aprendizado limitado: poucas vendas para a Meta aprender', ['aprendizado limitado'],
                        'Junte conjuntos parecidos num só ou aumente o orçamento para chegar a mais vendas por semana.');
                }

                // R13: a verba está indo para o anúncio mais fraco do conjunto.
                if ($c['objetivo_codigo'] === self::VENDAS) {
                    $cartoes = array_merge($cartoes, $this->verbaNoMaisFraco($c, $s));
                }
            }
        }

        $total = count($cartoes);

        return [
            'estado' => $total > 0 ? 'ok' : ($cedo ? 'cedo' : 'vazio'),
            'cedo' => $cedo,
            'periodo' => $periodo,
            'periodo_longo' => $periodo === 'maximum',
            'gerado_em' => $contexto['gerado_em'] ?? null,
            'escopo' => ['gasto' => $gastoEscopo, 'impressoes' => $impressoesEscopo],
            'sem_dados' => $semDados,
            'roas_minimo' => $padrao + ['origem_rotulo' => MetaRoasMinimo::ORIGENS[$padrao['origem']] ?? $padrao['origem']],
            'total' => $total,
            'cartoes' => $this->ordenar($cartoes),
        ];
    }

    /**
     * Prioridade, depois gasto do maior para o menor. O mesmo alvo aparece
     * no máximo N vezes na frente (os mais graves); o resto vai para o fim
     * da lista, no "Ver todas".
     *
     * @param  list<array<string, mixed>>  $cartoes
     * @return list<array<string, mixed>>
     */
    private function ordenar(array $cartoes): array
    {
        usort($cartoes, fn ($a, $b) => [self::ORDEM[$a['prioridade']], -$a['gasto'], $a['id']] <=> [self::ORDEM[$b['prioridade']], -$b['gasto'], $b['id']]);

        $frente = [];
        $resto = [];
        $vistos = [];
        foreach ($cartoes as $cartao) {
            $chave = $cartao['alvo']['tipo'].':'.$cartao['alvo']['id'];
            $vistos[$chave] = ($vistos[$chave] ?? 0) + 1;
            if ($vistos[$chave] <= $this->l['cartoes_por_alvo']) {
                $frente[] = $cartao;
            } else {
                $resto[] = $cartao + ['repetido' => true];
            }
        }

        return array_merge($frente, $resto);
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $s
     * @return list<array<string, mixed>>
     */
    private function verbaNoMaisFraco(array $c, array $s): array
    {
        $ativos = array_values(array_filter($s['anuncios'] ?? [], fn ($a) => ($a['status'] ?? '') === 'ACTIVE'));
        $total = array_sum(array_map(fn ($a) => (float) $a['metricas']['gasto'], $ativos));
        if (count($ativos) < 2 || $total <= 0) {
            return [];
        }

        foreach ($ativos as $a) {
            $m = $a['metricas'];
            if ($m['gasto'] < $this->l['minimo_gasto'] || $m['gasto'] / $total < $this->l['fatia_do_gasto']) {
                continue;
            }
            $roasDele = (float) ($m['roas'] ?? 0);
            $irmao = null;
            foreach ($ativos as $b) {
                $mb = $b['metricas'];
                if ($b['id'] !== $a['id'] && $mb['compras'] >= $this->l['compras_irmao'] && $mb['roas'] !== null && $mb['roas'] > $roasDele
                    && ($irmao === null || $mb['roas'] > $irmao['metricas']['roas'])) {
                    $irmao = $b;
                }
            }
            if ($irmao) {
                return [$this->cartao('R13', self::ATENCAO, 'A verba está indo para o anúncio mais fraco',
                    [$this->dec($m['gasto'] / $total * 100, 0).'% do gasto do conjunto', 'ROAS '.$this->dec($roasDele), '"'.$irmao['nome'].'" com ROAS '.$this->dec($irmao['metricas']['roas'])],
                    "Pause \"{$a['nome']}\" para a verba ir para \"{$irmao['nome']}\", que vende com ROAS ".$this->dec($irmao['metricas']['roas']).'.',
                    'anuncio', $c, $s, $a)];
            }
        }

        return [];
    }

    /** @param  array<string, mixed>  $m */
    private function acaoDoFunil(array $m): string
    {
        $cliques = (int) $m['cliques_link'];

        return match (true) {
            $m['visualizacoes_pagina'] === 0 && $m['carrinho'] === 0 && $m['checkout'] === 0 => 'Revise a página do produto: preço, frete e fotos.',
            $m['visualizacoes_pagina'] < $this->l['pagina_carregou_min'] * $cliques => 'A página demora para abrir. Confira a velocidade da página do produto no celular.',
            $m['carrinho'] === 0 && $m['checkout'] === 0 => 'Quem chega não coloca no carrinho. Revise o preço, as fotos e o frete grátis na página do produto.',
            default => 'Desistem no checkout. Confira o frete e as formas de pagamento.',
        };
    }

    /**
     * @param  list<string>  $prova
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $s
     * @param  array<string, mixed>|null  $a
     * @return array<string, mixed>
     */
    private function cartao(string $regra, string $prioridade, string $titulo, array $prova, string $acao, string $tipo, array $c, array $s, ?array $a): array
    {
        $alvo = $a ?? $s;

        return [
            'id' => $regra.'-'.$tipo.'-'.$alvo['id'],
            'regra' => $regra,
            'prioridade' => $prioridade,
            'titulo' => $titulo,
            'prova' => array_values($prova),
            'acao' => $acao,
            'gasto' => (float) ($alvo['metricas']['gasto'] ?? 0),
            'alvo' => [
                'tipo' => $tipo,
                'id' => (string) $alvo['id'],
                'nome' => (string) $alvo['nome'],
                'miniatura' => $a['miniatura'] ?? null,
                'campanha_id' => (string) $c['id'],
                'campanha' => (string) $c['nome'],
                'conjunto_id' => (string) $s['id'],
                'conjunto' => (string) $s['nome'],
                'gerenciador' => $a ? ($a['gerenciador'] ?? null) : ($s['gerenciador_conjunto'] ?? null),
                // Anúncio feito no KazaKora tem o modal completo; os outros, o de nome.
                'local' => $a !== null && ! empty($a['local']),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $campanhas
     * @return \Generator<int, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}>
     */
    private function anunciosAtivos(array $campanhas): \Generator
    {
        foreach ($campanhas as $c) {
            foreach ($c['conjuntos'] ?? [] as $s) {
                foreach ($s['anuncios'] ?? [] as $a) {
                    if (($a['status'] ?? '') === 'ACTIVE') {
                        yield [$c, $s, $a];
                    }
                }
            }
        }
    }

    private function reais(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }

    private function numero(int $valor): string
    {
        return number_format($valor, 0, ',', '.');
    }

    private function dec(float $valor, int $casas = 2): string
    {
        return number_format($valor, $casas, ',', '.');
    }
}
