<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\OrderChannelFee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Lê os relatórios de pagamento da Seller Central da Amazon e grava em
 * marketplace_settlement_details (channel amazon) o valor REAL de cada
 * pedido: venda, frete, promoção, comissão, tarifas e o líquido (2026-10-12).
 * O Bling não traz a taxa da Amazon (taxaComissao sempre 0), por isso o
 * relatório.
 *
 * Dois formatos:
 *  - "Flat File V2" da liquidação (Pagamentos › Todos os extratos): TSV com
 *    cabeçalho fixo em inglês (settlement-id, order-id, amount-type,
 *    amount-description, amount...), uma linha por componente. A primeira
 *    linha traz o total depositado, usado na conferência.
 *  - "Relatório por intervalo de datas" de transações (Repositório de
 *    relatórios): CSV com algumas linhas de aviso antes do cabeçalho, em
 *    inglês ou português, uma linha por transação.
 *
 * Cada pedido vira uma linha por (liquidação, pedido, SKU, tipo); reimportar
 * atualiza, não duplica.
 */
class ImportadorRelatorioAmazon
{
    /** Cabeçalho do relatório por datas (normalizado: minúsculo, sem acento) => coluna. */
    private const COLUNAS_DATAS = [
        'date/time' => 'settlement_date', 'data/hora' => 'settlement_date', 'data e hora' => 'settlement_date',
        'settlement id' => 'statement_id', 'id de liquidacao' => 'statement_id', 'id da liquidacao' => 'statement_id', 'id do acerto' => 'statement_id',
        'type' => 'transaction_type', 'tipo' => 'transaction_type',
        'order id' => 'external_order_id', 'id do pedido' => 'external_order_id', 'numero do pedido' => 'external_order_id',
        'sku' => 'external_sku_id',
        'description' => 'product_name', 'descricao' => 'product_name',
        'quantity' => 'quantity', 'quantidade' => 'quantity',
        'product sales' => 'product_net_sales', 'vendas de produtos' => 'product_net_sales', 'vendas do produto' => 'product_net_sales', 'vendas de produto' => 'product_net_sales',
        'shipping credits' => 'customer_shipping_fee', 'creditos de envio' => 'customer_shipping_fee', 'creditos de frete' => 'customer_shipping_fee', 'creditos de remessa' => 'customer_shipping_fee',
        'promotional rebates' => 'seller_discounts', 'descontos promocionais' => 'seller_discounts', 'abatimentos promocionais' => 'seller_discounts', 'reembolsos promocionais' => 'seller_discounts',
        'selling fees' => 'platform_commission_fee', 'tarifas de venda' => 'platform_commission_fee', 'taxas de venda' => 'platform_commission_fee',
        'fba fees' => 'service_fees', 'tarifas do fba' => 'service_fees', 'taxas do fba' => 'service_fees', 'tarifas de logistica da amazon' => 'service_fees',
        'other transaction fees' => 'service_fees', 'outras tarifas de transacao' => 'service_fees', 'outras taxas de transacao' => 'service_fees',
        'other' => 'adjustment_amount', 'outro' => 'adjustment_amount', 'outros' => 'adjustment_amount',
        'total' => 'payout_amount',
    ];

    /** Colunas de dinheiro que esta importação preenche (o resto fica 0). */
    private const DINHEIRO = ['payout_amount', 'product_net_sales', 'item_subtotal_before_discounts', 'customer_shipping_fee', 'seller_discounts', 'platform_commission_fee', 'service_fees', 'shipping_cost', 'taxes', 'product_refunds', 'adjustment_amount'];

    private const MESES = ['jan' => 1, 'fev' => 2, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'apr' => 4, 'mai' => 5, 'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'aug' => 8, 'set' => 9, 'sep' => 9, 'out' => 10, 'oct' => 10, 'nov' => 11, 'dez' => 12, 'dec' => 12];

    /**
     * @return array{formato: string, linhas: int, pedidos: int, liquidacoes: int, liquido: float, de: ?string, ate: ?string, divergencias: list<string>, taxas_reais: int}
     */
    public function importar(string $caminhoAbsoluto, string $nomeArquivo): array
    {
        $texto = (string) file_get_contents($caminhoAbsoluto);
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
        if (! mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }
        $linhasTexto = preg_split('/\r\n|\n|\r/', $texto);

        if (str_contains($linhasTexto[0] ?? '', 'settlement-id')) {
            [$registros, $divergencias] = $this->lerFlatFileV2($linhasTexto);
            $formato = 'liquidacao_v2';
        } else {
            $registros = $this->lerRelatorioDatas($linhasTexto);
            $divergencias = [];
            $formato = 'transacoes_datas';
        }

        if ($registros === []) {
            throw new RuntimeException('Nenhuma transação no arquivo.');
        }

        $agora = now();
        $linhas = [];
        foreach ($registros as $r) {
            $linhas[] = $r + ['channel' => Order::ORIGIN_AMAZON, 'source_file' => mb_substr($nomeArquivo, 0, 255), 'currency' => 'BRL', 'created_at' => $agora, 'updated_at' => $agora];
        }

        DB::transaction(function () use ($linhas) {
            // Transação que apareceu primeiro sem liquidação (relatório por
            // datas antes do fechamento) e agora veio liquidada: sai a linha
            // provisória para não contar duas vezes.
            foreach (array_filter($linhas, fn ($l) => $l['statement_id'] !== '' && $l['external_order_id'] !== '') as $l) {
                DB::table('marketplace_settlement_details')->where('channel', Order::ORIGIN_AMAZON)->where('statement_id', '')
                    ->where('external_order_id', $l['external_order_id'])->where('external_sku_id', $l['external_sku_id'])
                    ->where('transaction_type', $l['transaction_type'])->delete();
            }
            $atualizar = array_values(array_diff(array_keys($linhas[0]), ['channel', 'statement_id', 'external_order_id', 'external_sku_id', 'transaction_type', 'created_at']));
            foreach (array_chunk($linhas, 200) as $lote) {
                DB::table('marketplace_settlement_details')->upsert($lote, ['channel', 'statement_id', 'external_order_id', 'external_sku_id', 'transaction_type'], $atualizar);
            }
        });

        $pedidos = array_values(array_unique(array_filter(array_column($linhas, 'external_order_id'))));
        $datas = array_filter(array_column($linhas, 'settlement_date'));

        return [
            'formato' => $formato,
            'linhas' => count($linhas),
            'pedidos' => count($pedidos),
            'liquidacoes' => count(array_unique(array_filter(array_column($linhas, 'statement_id')))),
            'liquido' => round(array_sum(array_column($linhas, 'payout_amount')), 2),
            'de' => $datas ? min($datas) : null,
            'ate' => $datas ? max($datas) : null,
            'divergencias' => $divergencias,
            'taxas_reais' => $this->aplicarTaxasReais($pedidos),
        ];
    }

    /**
     * Taxa REAL de cada pedido da Amazon: venda (produto + frete cobrado do
     * cliente) − líquido recebido. Entra comissão, tarifas de envio/DBA,
     * promoção paga pela loja e imposto retido. Sem ids, refaz para tudo.
     *
     * @param  list<string>|null  $ids
     */
    public function aplicarTaxasReais(?array $ids = null): int
    {
        $porPedido = DB::table('marketplace_settlement_details')
            ->where('channel', Order::ORIGIN_AMAZON)
            ->where('transaction_type', 'Order')
            ->where('external_order_id', '!=', '')
            ->when($ids !== null, fn ($q) => $q->whereIn('external_order_id', $ids))
            ->groupBy('external_order_id')
            ->selectRaw('external_order_id, SUM(product_net_sales) produto, SUM(customer_shipping_fee) frete_cliente, SUM(payout_amount) liquido, SUM(platform_commission_fee) comissao, SUM(service_fees) servico, SUM(shipping_cost) frete, SUM(seller_discounts) desconto, SUM(taxes) impostos')
            ->get();

        $pedidos = Order::query()->where('origin', Order::ORIGIN_AMAZON)
            ->whereIn('external_order_id', $porPedido->pluck('external_order_id'))
            ->pluck('id', 'external_order_id');

        $gravadas = 0;
        foreach ($porPedido as $l) {
            if (! isset($pedidos[$l->external_order_id])) {
                continue;
            }
            $venda = (float) $l->produto + (float) $l->frete_cliente;
            OrderChannelFee::query()->updateOrCreate(
                ['order_id' => $pedidos[$l->external_order_id], 'channel' => Order::ORIGIN_AMAZON],
                [
                    'gross_amount' => round($venda, 2),
                    'fee_amount' => round($venda - (float) $l->liquido, 2),
                    'commission_fee' => round(abs((float) $l->comissao), 2),
                    'service_fee' => round(abs((float) $l->servico), 2),
                    'shipping_fee' => round(abs((float) $l->frete), 2),
                    'seller_discount' => round(abs((float) $l->desconto), 2),
                    'payout_amount' => round((float) $l->liquido, 2),
                    'breakdown' => ['produto' => round((float) $l->produto, 2), 'frete_cliente' => round((float) $l->frete_cliente, 2), 'impostos' => round((float) $l->impostos, 2)],
                    'source' => OrderChannelFee::SOURCE_REPORT,
                    'computed_at' => now(),
                ],
            );
            $gravadas++;
        }

        return $gravadas;
    }

    /**
     * @param  list<string>  $linhasTexto
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function lerFlatFileV2(array $linhasTexto): array
    {
        $cab = array_map('trim', explode("\t", array_shift($linhasTexto)));
        $faltando = array_diff(['settlement-id', 'transaction-type', 'order-id', 'amount-type', 'amount-description', 'amount'], $cab);
        if ($faltando) {
            throw new RuntimeException('Colunas esperadas não vieram: '.implode(', ', $faltando));
        }

        $grupos = [];
        $totais = [];
        foreach ($linhasTexto as $texto) {
            if (trim($texto) === '') {
                continue;
            }
            $c = array_combine($cab, array_pad(array_slice(explode("\t", $texto), 0, count($cab)), count($cab), ''));
            $c = array_map('trim', $c);
            $liquidacao = $c['settlement-id'];

            // Linha de resumo: só o total depositado da liquidação.
            if ($c['transaction-type'] === '' && $c['amount-type'] === '') {
                $totais[$liquidacao] = $this->numero($c['total-amount'] ?? '');

                continue;
            }

            $pedido = $c['order-id'];
            $tipo = $c['transaction-type'];
            $sku = $c['sku'] ?? '';
            if ($pedido === '' && $sku === '') {
                // Lançamento sem pedido (mensalidade, anúncio, ajuste): um por descrição.
                $sku = Str::limit($c['amount-description'], 180, '');
            }
            $chave = implode('|', [$liquidacao, $pedido, $sku, $tipo]);
            $g = $grupos[$chave] ??= $this->vazio($liquidacao, $pedido, $sku, $tipo) + ['settlement_date' => $this->data($c['posted-date'] ?? ($c['posted-date-time'] ?? ''))];

            $valor = $this->numero($c['amount']);
            $coluna = $this->colunaDoComponente($c['amount-type'], $c['amount-description'], $tipo);
            $g[$coluna] += $valor;
            $g['payout_amount'] += $valor;
            if ($coluna === 'product_net_sales' && $tipo === 'Order') {
                $g['quantity'] += (int) ($c['quantity-purchased'] ?? 0);
            }
            $grupos[$chave] = $g;
        }

        $divergencias = [];
        foreach ($totais as $liquidacao => $total) {
            $liquidacao = (string) $liquidacao; // chave numérica vira int no array
            $soma = array_sum(array_map(fn ($g) => $g['payout_amount'], array_filter($grupos, fn ($g) => $g['statement_id'] === $liquidacao)));
            if (abs(round($soma, 2) - round($total, 2)) > 0.01) {
                $divergencias[] = "liquidação {$liquidacao}: linhas somam R$ ".number_format($soma, 2, ',', '.').', depósito diz R$ '.number_format($total, 2, ',', '.');
            }
        }

        return [array_values(array_map(fn ($g) => $this->arredondar($g), $grupos)), $divergencias];
    }

    /** Componente do Flat File V2 => coluna da tabela. */
    private function colunaDoComponente(string $tipo, string $descricao, string $transacao): string
    {
        $d = mb_strtolower($descricao);

        return match (true) {
            $tipo === 'ItemPrice' && $d === 'principal' => $transacao === 'Order' ? 'product_net_sales' : 'product_refunds',
            $tipo === 'ItemPrice' && str_contains($d, 'shipping') && ! str_contains($d, 'tax') => 'customer_shipping_fee',
            $tipo === 'ItemPrice', $tipo === 'ItemWithheldTax', str_contains($d, 'tax') && ! str_contains($tipo, 'Fee') => 'taxes',
            $tipo === 'Promotion' => 'seller_discounts',
            str_contains($d, 'commission') => 'platform_commission_fee',
            str_contains($d, 'shipping') && str_contains($tipo, 'Fee') => 'shipping_cost',
            str_contains($tipo, 'Fee') => 'service_fees',
            default => 'adjustment_amount',
        };
    }

    /**
     * @param  list<string>  $linhasTexto
     * @return list<array<string, mixed>>
     */
    private function lerRelatorioDatas(array $linhasTexto): array
    {
        // Antes do cabeçalho a Amazon põe algumas linhas de aviso.
        $inicio = null;
        $separador = ',';
        foreach (array_slice($linhasTexto, 0, 30, true) as $i => $texto) {
            foreach ([',', ';', "\t"] as $sep) {
                $cab = array_map(fn ($t) => $this->normalizar($t), str_getcsv($texto, $sep, '"', ''));
                $mapa = array_intersect_key(self::COLUNAS_DATAS, array_flip($cab));
                if (in_array('external_order_id', $mapa, true) && in_array('payout_amount', $mapa, true)) {
                    [$inicio, $separador] = [$i, $sep];
                    break 2;
                }
            }
        }
        if ($inicio === null) {
            throw new RuntimeException('Formato de relatório da Amazon não reconhecido: não achei as colunas de pedido e total. Use o "Flat File V2" da liquidação ou o relatório de transações por datas.');
        }

        $cab = array_map(fn ($t) => $this->normalizar($t), str_getcsv($linhasTexto[$inicio], $separador, '"', ''));
        $grupos = [];
        foreach (array_slice($linhasTexto, $inicio + 1) as $texto) {
            if (trim($texto) === '') {
                continue;
            }
            $valores = str_getcsv($texto, $separador, '"', '');
            $l = ['statement_id' => '', 'external_order_id' => '', 'external_sku_id' => '', 'transaction_type' => '', 'product_name' => null, 'settlement_date' => null, 'quantity' => 0];
            $dinheiro = array_fill_keys(self::DINHEIRO, 0.0);
            foreach ($cab as $i => $titulo) {
                $valor = trim((string) ($valores[$i] ?? ''));
                $coluna = self::COLUNAS_DATAS[$titulo] ?? ((str_contains($titulo, 'tax') || str_contains($titulo, 'imposto')) ? 'taxes' : null);
                if ($coluna === null) {
                    continue;
                }
                match (true) {
                    $coluna === 'settlement_date' => $l['settlement_date'] = $this->data($valor),
                    $coluna === 'quantity' => $l['quantity'] = (int) $valor,
                    in_array($coluna, self::DINHEIRO, true) => $dinheiro[$coluna] += $this->numero($valor),
                    default => $l[$coluna] = $valor,
                };
            }
            $l['transaction_type'] = $this->tipoPadrao($l['transaction_type']);
            if ($l['external_order_id'] === '' && $l['external_sku_id'] === '') {
                $l['external_sku_id'] = Str::limit((string) $l['product_name'], 180, '');
            }

            $chave = implode('|', [$l['statement_id'], $l['external_order_id'], $l['external_sku_id'], $l['transaction_type']]);
            $g = $grupos[$chave] ??= $this->vazio($l['statement_id'], $l['external_order_id'], $l['external_sku_id'], $l['transaction_type']) + ['settlement_date' => $l['settlement_date']];
            foreach ($dinheiro as $coluna => $valor) {
                $g[$coluna] += $valor;
            }
            if ($l['transaction_type'] === 'Order') {
                $g['quantity'] += $l['quantity'];
            }
            $g['product_name'] ??= $l['product_name'];
            $g['settlement_date'] = max($g['settlement_date'] ?? '', $l['settlement_date'] ?? '') ?: null;
            $grupos[$chave] = $g;
        }

        return array_values(array_map(fn ($g) => $this->arredondar($g), $grupos));
    }

    /** Tipo de transação do relatório por datas (inglês ou português) no padrão do Flat File. */
    private function tipoPadrao(string $tipo): string
    {
        return match ($this->normalizar($tipo)) {
            'order', 'pedido' => 'Order',
            'refund', 'reembolso', 'estorno', 'devolucao' => 'Refund',
            default => $tipo,
        };
    }

    private function vazio(string $liquidacao, string $pedido, string $sku, string $tipo): array
    {
        return ['statement_id' => $liquidacao, 'external_order_id' => $pedido, 'external_sku_id' => $sku, 'transaction_type' => $tipo, 'product_name' => null, 'quantity' => 0]
            + array_fill_keys(self::DINHEIRO, 0.0);
    }

    private function arredondar(array $g): array
    {
        foreach (self::DINHEIRO as $coluna) {
            $g[$coluna] = round($g[$coluna], 2);
        }
        $g['item_subtotal_before_discounts'] = $g['product_net_sales'];

        return $g;
    }

    private function normalizar(string $texto): string
    {
        return trim(mb_strtolower(Str::ascii(trim($texto, " \t\"\u{FEFF}"))));
    }

    /** "1,234.56", "1.234,56", "-12,30" ou "R$ 10,00" => float. */
    private function numero(string $valor): float
    {
        $v = preg_replace('/[^\d,.\-]/', '', $valor);
        if ($v === '' || $v === '-') {
            return 0.0;
        }
        $virgula = strrpos($v, ',');
        $ponto = strrpos($v, '.');
        if ($virgula !== false && ($ponto === false || $virgula > $ponto)) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        } else {
            $v = str_replace(',', '', $v);
        }

        return (float) $v;
    }

    /** Datas da Amazon em vários formatos (en/pt, com fuso no fim) => AAAA-MM-DD em São Paulo. */
    private function data(string $valor): ?string
    {
        $v = trim($valor);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
            return rescue(fn () => Carbon::parse($v)->setTimezone('America/Sao_Paulo')->toDateString(), "{$m[1]}-{$m[2]}-{$m[3]}", false);
        }
        if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})/', $v, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        $n = $this->normalizar($v);
        // "5 de out. de 2026 15:04:05 GMT-3" / "05/out/2026"
        if (preg_match('/^(\d{1,2})(?:\s+de\s+|[\s\/\-])([a-z]{3})[a-z]*\.?(?:\s+de\s+|[\s\/\-])(\d{4})/', $n, $m) && isset(self::MESES[$m[2]])) {
            return sprintf('%04d-%02d-%02d', $m[3], self::MESES[$m[2]], $m[1]);
        }
        // "Oct 5, 2026 3:04:05 PM PDT"
        if (preg_match('/^([a-z]{3})[a-z]*\.?\s+(\d{1,2}),?\s+(\d{4})/', $n, $m) && isset(self::MESES[$m[1]])) {
            return sprintf('%04d-%02d-%02d', $m[3], self::MESES[$m[1]], $m[2]);
        }

        return null;
    }
}
