<?php

namespace App\Modules\Financeiro\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Previsão de aquisição, nunca confirmação de repasse ou quitação. */
class PrevisaoFornecedores
{
    public const INICIO = '2026-11-23';

    public function util(string $data): bool
    {
        $d = CarbonImmutable::parse($data, 'America/Sao_Paulo');
        // Sede conferida em companies: São Paulo/SP. Não inclui pontos facultativos.
        $fixos = ['01-01', '01-25', '04-21', '05-01', '07-09', '09-07', '10-12', '11-02', '11-15', '11-20', '12-25'];
        $pascoa = CarbonImmutable::create($d->year, 3, 21, 0, 0, 0, 'America/Sao_Paulo')->addDays(easter_days($d->year));

        return ! $d->isWeekend() && ! in_array($d->format('m-d'), $fixos, true)
            && ! in_array($d->toDateString(), [$pascoa->subDays(2)->toDateString(), $pascoa->addDays(60)->toDateString()], true);
    }

    public function elegivel(string $data): bool
    {
        return $data >= self::INICIO && $this->util($data);
    }

    public function vencimento(string $base): string
    {
        $d = CarbonImmutable::parse($base, 'America/Sao_Paulo')->setTimezone('America/Sao_Paulo')->startOfDay()->addDays(10);
        while (! $this->util($d->toDateString())) {
            $d = $d->addDay();
        }

        return $d->toDateString();
    }

    public function montar(string $data, iterable $rows): array
    {
        $r = ['data' => $data, 'fornecedores' => [], 'pendencias' => [], 'total_centavos' => 0];
        foreach ($rows as $row) {
            $row = (array) $row;
            if (! in_array($row['status'], ['paid', 'shipped', 'completed'], true)
                || ! in_array($row['origin'], ['shopee', 'mercado_livre', 'tiktok_shop'], true)
                || in_array($row['fiscal_operation_type'] ?? null, ['purchase_return', 'sales_return'], true)
                || ! empty($row['estornado_em'])) {
                continue;
            }
            $base = $row['paid_at'] ?? $row['created_at'];
            if ($this->vencimento($base) !== $data) {
                continue; // Sem arrastar backlog anterior, inclusive na primeira execução.
            }
            $q = (int) $row['quantity'];
            $manual = $row['manual_cost_price'] ?? null;
            $cost = $row['cost_price'] ?? null;
            $total = $manual !== null ? (int) round((float) $manual * 100) : ($cost !== null ? (int) round((float) $cost * 100) * $q : null);
            $row['total_centavos'] = $total;
            $row['unitario_centavos'] = $total !== null && $q > 0 ? $total / $q : null;
            $row['base_data'] = $base;
            $row['base_fonte'] = isset($row['paid_at']) ? 'aprovação comprador registrada (Mercado Pago)' : 'created_at: referência do pedido/importação; pagamento não comprovado';
            $row['custo_fonte'] = $manual !== null ? 'manual: total da linha' : 'catálogo atual (sem snapshot)';
            $row['pendencia'] = implode('; ', array_filter([
                empty($row['supplier']) ? 'sem fornecedor vinculado à venda' : null,
                $total === null || $total <= 0 ? 'sem custo positivo comprovado' : null,
                $q <= 0 ? 'quantidade inválida' : null,
                ! empty($row['return_review']) ? 'devolução/disputa: conferir alvo financeiro; sem abatimento inventado' : null,
            ]));
            if ($row['pendencia'] !== '') {
                $r['pendencias'][] = $row;

                continue;
            }
            $key = implode('|', [$row['order_id'] ?? '', $row['origin'], $row['sku'] ?? $row['product_name'], $row['variation'] ?? '', $row['unitario_centavos'], $row['custo_fonte'], $row['base_fonte']]);
            $supplier = $row['supplier'];
            if (isset($r['fornecedores'][$supplier][$key])) {
                $r['fornecedores'][$supplier][$key]['quantity'] += $q;
                $r['fornecedores'][$supplier][$key]['total_centavos'] += $total;
            } else {
                $r['fornecedores'][$supplier][$key] = $row;
            }
            $r['total_centavos'] += $total;
        }
        foreach ($r['fornecedores'] as &$items) {
            $items = array_values($items);
        }

        return $r;
    }

    public function calcular(string $data): array
    {
        // Não há ligação produto→fornecedor nem ledger fornecedor por item de venda.
        // Compra de reposição não prova qual fornecedor originou esta venda.
        $paid = DB::table('mercado_pago_pagamentos')->selectRaw('order_id, MIN(data_aprovacao) as paid_at')
            ->where('status', 'approved')->whereNotNull('order_id')->groupBy('order_id');
        $rows = DB::table('orders as o')->join('order_items as i', 'i.order_id', '=', 'o.id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')->leftJoinSub($paid, 'mp', 'mp.order_id', '=', 'o.id')
            ->whereIn('o.origin', ['shopee', 'mercado_livre', 'tiktok_shop'])
            ->whereIn('o.status', ['paid', 'shipped', 'completed'])
            ->whereRaw('COALESCE(mp.paid_at, o.created_at) >= ?', [CarbonImmutable::parse($data)->subDays(45)->startOfDay()])
            ->whereRaw('COALESCE(mp.paid_at, o.created_at) < ?', [CarbonImmutable::parse($data)->subDays(9)->startOfDay()])
            ->select(['o.id as order_id', 'o.origin', 'o.status', 'o.fiscal_operation_type', 'o.estornado_em', 'o.created_at', 'mp.paid_at', 'i.product_name', 'i.quantity', 'i.manual_cost_price', 'p.sku', 'p.variation', 'p.cost_price'])
            ->selectRaw('NULL as supplier, EXISTS(SELECT 1 FROM marketplace_returns mr WHERE mr.order_id = o.id) as return_review')
            ->orderBy('o.id')->get();

        return $this->montar($data, $rows);
    }

    public function texto(array $r): string
    {
        $money = fn ($v) => $v === null ? 'não informado' : 'R$ '.number_format($v / 100, 2, ',', '.');
        $items = [...array_merge([], ...array_values($r['fornecedores'])), ...$r['pendencias']];
        $known = array_sum(array_column($items, 'total_centavos'));
        $unknown = count(array_filter($items, fn ($i) => $i['total_centavos'] === null));
        $withoutSupplier = count(array_filter($items, fn ($i) => empty($i['supplier'])));
        $out = ['KazaKora — previsão de fornecedores — '.$r['data'], '',
            'Custo de aquisição conhecido para conferência: '.$money($known).' (inclui pendências; não é dívida comprovada).',
            'Fornecedores identificados: '.count($r['fornecedores']).' grupos | Sem fornecedor: '.$withoutSupplier.' linhas | Sem custo informado: '.$unknown.' linhas (NULL; não somadas).',
            'Total agrupado por fornecedor sem pendências: '.$money($r['total_centavos']), '',
            'D+10 dias corridos desde pagamento comprador quando registrado; sem data autêntica, usa created_at apenas como referência de pedido/importação. Isto não é repasse real nem dívida comprovada.',
            'Pagamento ao fornecedor NÃO verificado. Confira sua planilha antes de pagar. E-mail enviado não significa quitado.',
            'Somente vencimentos rolados para esta data. Backlog histórico excluído. Sábados, domingos, nacionais e São Paulo/SP (25/01, 09/07, Sexta Santa, Corpus Christi) passam ao próximo útil.',
            'Sem snapshot de aquisição no item: catálogo atual rotulado; manual_cost_price é total da linha. Pendências NÃO integram o total por fornecedor.', '',
            'Total geral identificado por fornecedor: '.$money($r['total_centavos'])];
        foreach ($r['fornecedores'] as $supplier => $items) {
            $out[] = "\nFornecedor: {$supplier}";
            foreach ($items as $i) {
                $out[] = $this->linha($i, $money);
            }
            $out[] = 'Subtotal: '.$money(array_sum(array_column($items, 'total_centavos')));
        }
        $out[] = "\nPendências separadas: ".count($r['pendencias']);
        foreach ($r['pendencias'] as $i) {
            $out[] = $this->linha($i, $money).' | '.$i['pendencia'];
        }

        return implode("\n", $out);
    }

    private function linha(array $i, callable $money): string
    {
        return ($i['sku'] ?? '').' '.$i['product_name'].' / '.($i['variation'] ?? '')."\nQtd: ".$i['quantity'].' | Custo unitário: '.$money($i['unitario_centavos']).' | Total aquisição: '.$money($i['total_centavos'])."\nFonte custo: ".$i['custo_fonte'].' | Base: '.$i['base_data'].' — '.$i['base_fonte'];
    }

    public function csv(array $r): string
    {
        $f = fopen('php://temp', 'r+');
        fputcsv($f, ['Pedido', 'Canal', 'Fornecedor', 'Produto', 'Variante', 'Quantidade', 'Custo unitário BRL', 'Custo total BRL', 'Fonte custo', 'Base data', 'Fonte data', 'Pendência'], ';', '"', '');
        foreach ([...array_merge([], ...array_values($r['fornecedores'])), ...$r['pendencias']] as $i) {
            $safe = fn ($v) => preg_match('/^[=+@\-]/', (string) $v) ? "'".$v : $v;
            fputcsv($f, array_map($safe, [$i['order_id'] ?? '', $i['origin'] ?? '', $i['supplier'] ?? '', $i['product_name'], $i['variation'] ?? '', $i['quantity'], $i['unitario_centavos'] === null ? '' : number_format($i['unitario_centavos'] / 100, 2, ',', ''), $i['total_centavos'] === null ? '' : number_format($i['total_centavos'] / 100, 2, ',', ''), $i['custo_fonte'], $i['base_data'], $i['base_fonte'], $i['pendencia']]), ';', '"', '');
        }
        rewind($f);

        return stream_get_contents($f);
    }
}
