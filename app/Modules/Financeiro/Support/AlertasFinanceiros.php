<?php

namespace App\Modules\Financeiro\Support;

use Illuminate\Support\Carbon;

/**
 * Alertas do Resumo do Fluxo de Caixa (bloco 1 da proposta de 2026-10-19).
 * Recebe os números já calculados pela conta oficial (ContributionMargin)
 * e devolve os alertas em ordem de prioridade, com texto e link de ação.
 * Não calcula nada de lucro por conta própria.
 */
class AlertasFinanceiros
{
    /** Nome do canal com o artigo ("ADS da Shopee", "do Mercado Livre"). */
    public const CANAIS = [
        'shopee' => ['Shopee', 'da'],
        'mercado_livre' => ['Mercado Livre', 'do'],
        'tiktok_shop' => ['TikTok Shop', 'do'],
        'amazon' => ['Amazon', 'da'],
        'loja' => ['site', 'do'],
        'ferrakora' => ['FerraKora', 'da'],
        'beautykora' => ['Beauty Kora', 'da'],
    ];

    /**
     * @param array{
     *   hoje: Carbon,
     *   rotuloPeriodo: string,
     *   das: list<array{competencia: string, vence: string, valor: float, base: float, parcial: bool}>,
     *   canais: array<string, array<string, float|int>>,
     *   receita: float,
     *   receitaComparacao: ?float,
     *   rotuloComparacao: ?string,
     *   qualidade: array<string, array{real: float, estimada: float, pedidos_real: int, pedidos_estimada: int}>,
     *   semCusto: int,
     *   linkSemCusto: string,
     *   notasCanceladas: array{quantidade: int, valor: float},
     * } $c
     * @return list<array{chave: string, nivel: string, rotulo: string, texto: string, links: list<array{href: string, rotulo: string}>}>
     */
    public function gerar(array $c): array
    {
        $alertas = [];
        $hoje = $c['hoje']->copy()->startOfDay();

        // 1. DAS vencendo (do dia 13 ao vencimento).
        foreach ($c['das'] as $das) {
            $vence = Carbon::parse($das['vence']);
            if ($das['parcial'] || $hoje->day < 13 || $vence->lt($hoje) || ! $vence->isSameMonth($hoje)) {
                continue;
            }
            $dias = (int) $hoje->diffInDays($vence);
            $mes = $this->mes($das['competencia']);
            $alertas[] = [
                'chave' => 'das',
                'nivel' => $dias <= 1 ? 'urgente' : 'aviso',
                'rotulo' => match ($dias) { 0 => 'VENCE HOJE', 1 => 'VENCE AMANHÃ', default => "VENCE EM {$dias} DIAS" },
                'texto' => "DAS de {$mes} vence {$vence->format('d/m')}. Estimativa do sistema: {$this->moeda($das['valor'])} ("
                    .$this->pct((float) config('loja.simples.aliquota', 4.5))." das notas de {$mes}). O valor oficial é a guia do contador.",
                'links' => [['href' => '/admin/notas-fiscais/fechamento', 'rotulo' => 'Ver fechamento']],
            ];
        }

        // 2. ADS alto, 3. canal no prejuízo.
        $limiteAds = (float) config('loja.ads_alerta_percentual', 6);
        $prejuizos = [];
        foreach ($c['canais'] as $canal => $conta) {
            [$nome, $de] = self::CANAIS[$canal] ?? [$canal, 'de'];
            $receita = (float) ($conta['receita'] ?? 0);
            $ads = (float) ($conta['ads'] ?? 0);
            if ($receita > 0 && $ads / $receita * 100 > $limiteAds) {
                $antesDoAds = (float) $conta['margem'] + $ads;
                $levou = $antesDoAds > 0 ? ' e levou '.$this->pct(min(999, $ads / $antesDoAds * 100)).' do que sobrava antes do anúncio' : ', mais do que sobrava antes do anúncio';
                $alertas[] = [
                    'chave' => "ads:{$canal}",
                    'nivel' => 'aviso',
                    'rotulo' => 'ADS ALTO',
                    'texto' => "ADS {$de} {$nome} comeu ".$this->pct($ads / $receita * 100)." do faturamento {$de} {$nome} em {$c['rotuloPeriodo']} (meta 3 a 4%){$levou}. Lucro {$de} {$nome}: {$this->moeda((float) $conta['lucro'])}.",
                    'links' => $this->linksAds($canal),
                ];
            }
            if ((float) ($conta['lucro'] ?? 0) < 0) {
                $prejuizos[] = [
                    'chave' => "prejuizo:{$canal}",
                    'nivel' => 'urgente',
                    'rotulo' => 'PREJUÍZO',
                    'texto' => ucfirst($nome)." fechou o período com lucro de {$this->moeda((float) $conta['lucro'])}.",
                    'links' => $this->linksPrejuizo($canal),
                ];
            }
        }
        array_push($alertas, ...$prejuizos);

        // 4. Taxa estimada acima de 10% das taxas.
        $real = array_sum(array_column($c['qualidade'], 'real'));
        $estimada = array_sum(array_column($c['qualidade'], 'estimada'));
        if ($real + $estimada > 0 && $estimada / ($real + $estimada) > 0.10) {
            $partes = collect($c['qualidade'])
                ->filter(fn ($q) => $q['pedidos_estimada'] > 0)
                ->sortByDesc('estimada')
                ->map(fn ($q, $canal) => $q['pedidos_estimada'].' '.($q['pedidos_estimada'] === 1 ? 'pedido' : 'pedidos')." {$this->artigo($canal)}, ".$this->moeda($q['estimada']))
                ->implode('; ');
            $alertas[] = [
                'chave' => 'taxa-estimada',
                'nivel' => 'info',
                'rotulo' => 'NÚMERO PROVISÓRIO',
                'texto' => $this->pct($estimada / ($real + $estimada) * 100)." das taxas do período ainda são estimadas ({$partes}). O lucro muda quando o demonstrativo chegar.",
                'links' => isset($c['qualidade']['tiktok_shop']) && $c['qualidade']['tiktok_shop']['pedidos_estimada'] > 0
                    ? [['href' => '/admin/arquivos-tiktok', 'rotulo' => 'Arquivos do TikTok']]
                    : [],
            ];
        }

        // 5. Queda de vendas contra a comparação.
        $queda = (float) config('loja.queda_vendas_percentual', 30);
        if ($c['receitaComparacao'] !== null && $c['receitaComparacao'] > 0 && $c['receita'] < $c['receitaComparacao'] * (1 - $queda / 100)) {
            $alertas[] = [
                'chave' => 'queda',
                'nivel' => 'aviso',
                'rotulo' => 'VENDAS CAINDO',
                'texto' => 'Receita '.$this->pct((1 - $c['receita'] / $c['receitaComparacao']) * 100)." abaixo de {$c['rotuloComparacao']}.",
                'links' => [],
            ];
        }

        // 6. Itens vendidos sem custo.
        if ($c['semCusto'] > 0) {
            $alertas[] = [
                'chave' => 'sem-custo',
                'nivel' => 'aviso',
                'rotulo' => 'SEM CUSTO',
                'texto' => "{$c['semCusto']} ".($c['semCusto'] === 1 ? 'item vendido' : 'itens vendidos').' sem custo cadastrado: o lucro está acima do real.',
                'links' => [['href' => $c['linkSemCusto'], 'rotulo' => 'Ver itens']],
            ];
        }

        // 7. Nota autorizada de pedido cancelado.
        if ($c['notasCanceladas']['quantidade'] > 0) {
            $n = $c['notasCanceladas']['quantidade'];
            $alertas[] = [
                'chave' => 'nota-pendente',
                'nivel' => 'aviso',
                'rotulo' => 'NOTA PENDENTE',
                'texto' => "{$n} ".($n === 1 ? 'nota de pedido cancelado ainda autorizada' : 'notas de pedidos cancelados ainda autorizadas')
                    ." ({$this->moeda($c['notasCanceladas']['valor'])}).",
                'links' => [['href' => '/admin/notas-fiscais', 'rotulo' => 'Notas fiscais']],
            ];
        }

        return $alertas;
    }

    /** @return list<array{href: string, rotulo: string}> */
    private function linksAds(string $canal): array
    {
        return match ($canal) {
            'shopee' => [['href' => '/admin/publicidade/shopee', 'rotulo' => 'Abrir Shopee Ads'], ['href' => '/admin/indicadores?aba=caue-diego', 'rotulo' => 'Ver o Cauê']],
            'mercado_livre' => [['href' => '/admin/oportunidades/mercado-livre', 'rotulo' => 'Abrir Mercado Livre'], ['href' => '/admin/indicadores?aba=caue-diego', 'rotulo' => 'Ver o Diego']],
            default => [['href' => '/admin/publicidade', 'rotulo' => 'Visão geral de ADS']],
        };
    }

    /** @return list<array{href: string, rotulo: string}> */
    private function linksPrejuizo(string $canal): array
    {
        return match ($canal) {
            'shopee' => [['href' => '/admin/oportunidades/shopee', 'rotulo' => 'Oportunidades Shopee'], ['href' => '/admin/indicadores?aba=caue-diego', 'rotulo' => 'Ver o Cauê']],
            'mercado_livre' => [['href' => '/admin/oportunidades/mercado-livre', 'rotulo' => 'Oportunidades Mercado Livre'], ['href' => '/admin/indicadores?aba=caue-diego', 'rotulo' => 'Ver o Diego']],
            default => [],
        };
    }

    private function artigo(string $canal): string
    {
        [$nome, $de] = self::CANAIS[$canal] ?? [$canal, 'de'];

        return "{$de} {$nome}";
    }

    private function mes(string $anoMes): string
    {
        return Carbon::createFromFormat('!Y-m', $anoMes)->locale('pt_BR')->translatedFormat('F');
    }

    private function moeda(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }

    private function pct(float $valor): string
    {
        return number_format($valor, 1, ',', '.').'%';
    }
}
