<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Services\Amazon\AmazonClient;
use App\Services\Amazon\Exceptions\AmazonException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Taxa REAL da Amazon direto da SP-API, Finances API 2024-06-19
 * (listTransactions), no lugar da coleta pela tela da Seller Central na
 * Cora Box, desligada em 2026-10-17. Teste de 16/10: 34 de 34 taxas iguais
 * às gravadas pelo relatório.
 *
 * A gravação é a MESMA do relatório (ImportadorRelatorioAmazon): cada
 * pedido vira uma linha em marketplace_settlement_details (channel amazon,
 * sem liquidação e sem SKU, a mesma chave da linha que a Cora Box gravava,
 * que é substituída e não duplicada) e a taxa sai de
 * aplicarTaxasReaisDetalhado(): venda (produto + frete do cliente) − líquido.
 *
 * Decisões (2026-10-17):
 * - Só transações "Shipment" (vira linha Order) e "Refund" (linha Refund,
 *   guardada para conferência). Transfer/ServiceFee não têm pedido.
 * - Status: DEFERRED (venda enviada, dinheiro retido até a entrega) já tem
 *   a taxa definitiva e conta. Quando a Amazon libera, ela lança OUTRA
 *   transação (DEFERRED_RELEASED + RELEASED, com DEFERRED_TRANSACTION_ID
 *   apontando para a primeira), com os mesmos valores: as duas viram um
 *   lançamento só (fica o de status mais avançado). Pedido com mais de um
 *   envio soma os envios.
 * - Reembolso NÃO muda a taxa da venda (igual ao relatório: a taxa só olha
 *   as linhas Order). A venda sai da receita pela regra de sempre
 *   (estornado_em / nota de devolução, Order::vendaEfetiva()). A parte da
 *   comissão que a Amazon não devolve (~20%) fica de fora da margem; os
 *   reembolsos de pedidos que ainda contam como venda saem no resumo para
 *   conferência.
 * - Só pedidos criados dentro da janela (--dias): transação de pedido mais
 *   antigo pode ter um envio fora da janela e a soma ficaria pela metade.
 * - Pedido que já tem liquidação oficial importada (Flat File V2, linha com
 *   statement_id) não é tocado: as duas somariam em dobro.
 * - Taxa manual nunca é sobrescrita; taxa igual não é regravada.
 */
class TaxasAmazonSpApi
{
    public const ORIGEM = 'sp-api:finances/2024-06-19';

    private const STATUS_ORDEM = ['DEFERRED' => 1, 'DEFERRED_RELEASED' => 2, 'RELEASED' => 3];

    /** Colunas de dinheiro gravadas (as mesmas do relatório). */
    private const DINHEIRO = ['payout_amount', 'product_net_sales', 'item_subtotal_before_discounts', 'customer_shipping_fee', 'seller_discounts', 'platform_commission_fee', 'service_fees', 'shipping_cost', 'taxes', 'product_refunds', 'adjustment_amount'];

    public function __construct(
        private readonly AmazonClient $client,
        private readonly ImportadorRelatorioAmazon $importador,
    ) {}

    /**
     * Todas as transações postadas desde $desde (paginado; a API aceita
     * 0,5 chamada por segundo, por isso a pausa entre páginas).
     *
     * @return list<array<string, mixed>>
     */
    public function buscar(Carbon $desde): array
    {
        $transacoes = [];
        $proxima = null;
        $paginas = 0;

        do {
            $query = [
                'postedAfter' => $desde->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'marketplaceId' => (string) config('services.amazon.marketplace_id'),
            ];
            if ($proxima) {
                $query['nextToken'] = $proxima;
                Sleep::for(2)->seconds();
            }

            $payload = $this->comRetentativa(fn () => $this->client->get('/finances/2024-06-19/transactions', $query))['payload'] ?? [];
            array_push($transacoes, ...($payload['transactions'] ?? []));
            $proxima = $payload['nextToken'] ?? null;
        } while ($proxima && ++$paginas < 200);

        return $transacoes;
    }

    /**
     * Uma linha por (pedido, tipo) no formato de marketplace_settlement_details.
     *
     * @param  list<array<string, mixed>>  $transacoes
     * @return array<string, array<string, mixed>> chave "pedido|Order" ou "pedido|Refund"
     */
    public function linhasPorPedido(array $transacoes): array
    {
        $lancamentos = [];
        foreach ($transacoes as $t) {
            $tipo = match ($t['transactionType'] ?? '') {
                'Shipment' => 'Order',
                'Refund' => 'Refund',
                default => null,
            };
            $ids = $this->identificadores($t);
            if ($tipo === null || empty($ids['ORDER_ID'])) {
                continue;
            }

            // O lançamento liberado aponta para o diferido: os dois são um só.
            $chave = $ids['ORDER_ID'].'|'.$tipo.'|'.($ids['DEFERRED_TRANSACTION_ID'] ?? $t['transactionId'] ?? md5((string) json_encode($t)));
            $atual = $lancamentos[$chave] ?? null;
            if ($atual === null || $this->ordemStatus($t) > $this->ordemStatus($atual)
                || ($this->ordemStatus($t) === $this->ordemStatus($atual) && ($t['postedDate'] ?? '') > ($atual['postedDate'] ?? ''))) {
                $lancamentos[$chave] = $t;
            }
        }

        // A transação diferida que já foi liberada sai mesmo sem o par na
        // chave (o liberado aponta para ela pelo DEFERRED_TRANSACTION_ID).
        $substituidas = [];
        foreach ($lancamentos as $t) {
            $ids = $this->identificadores($t);
            if (! empty($ids['DEFERRED_TRANSACTION_ID'])) {
                $substituidas[$ids['DEFERRED_TRANSACTION_ID']] = true;
            }
        }

        $linhas = [];
        foreach ($lancamentos as $t) {
            if (isset($substituidas[$t['transactionId'] ?? ''])) {
                continue;
            }
            $ids = $this->identificadores($t);
            $tipo = ($t['transactionType'] ?? '') === 'Refund' ? 'Refund' : 'Order';
            $chave = $ids['ORDER_ID'].'|'.$tipo;
            $linha = $linhas[$chave] ??= [
                'channel' => Order::ORIGIN_AMAZON, 'statement_id' => '', 'external_order_id' => $ids['ORDER_ID'], 'external_sku_id' => '',
                'transaction_type' => $tipo, 'settlement_date' => null, 'payment_id' => null, 'status' => null,
                'product_name' => null, 'quantity' => 0, 'currency' => 'BRL', 'source_file' => self::ORIGEM,
            ] + array_fill_keys(self::DINHEIRO, 0.0);

            $componentes = 0.0;
            foreach ($t['items'] ?? [] as $item) {
                $linha['product_name'] ??= isset($item['description']) ? mb_substr((string) $item['description'], 0, 500) : null;
                foreach ($item['contexts'] ?? [] as $contexto) {
                    if ($tipo === 'Order') {
                        $linha['quantity'] += (int) ($contexto['quantityShipped'] ?? 0);
                    }
                }
                foreach ($item['breakdowns'] ?? [] as $b) {
                    $valor = (float) ($b['breakdownAmount']['currencyAmount'] ?? 0);
                    $componentes += $valor;
                    foreach ($this->colunas($b, $tipo) as $coluna => $parte) {
                        $linha[$coluna] += $parte;
                    }
                }
            }

            $total = (float) ($t['totalAmount']['currencyAmount'] ?? $componentes);
            $linha['payout_amount'] += $total;
            // Algum componente fora dos itens: fica como ajuste, para o líquido fechar.
            $linha['adjustment_amount'] += $total - $componentes;

            $data = isset($t['postedDate']) ? Carbon::parse($t['postedDate'])->setTimezone('America/Sao_Paulo')->toDateString() : null;
            $linha['settlement_date'] = max((string) $linha['settlement_date'], (string) $data) ?: null;
            $linha['status'] = $t['transactionStatus'] ?? null;
            $linha['payment_id'] = $ids['SETTLEMENT_ID'] ?? $linha['payment_id'];
            $linhas[$chave] = $linha;
        }

        foreach ($linhas as $chave => $linha) {
            foreach (self::DINHEIRO as $coluna) {
                $linha[$coluna] = round($linha[$coluna], 2);
            }
            $linha['item_subtotal_before_discounts'] = $linha['product_net_sales'];
            $linhas[$chave] = $linha;
        }

        return $linhas;
    }

    /**
     * Busca, converte e grava (ou só simula, com $seco).
     *
     * @return array<string, mixed>
     */
    public function sincronizar(int $dias, bool $seco = false): array
    {
        $desde = now()->subDays($dias)->startOfDay();
        $transacoes = $this->buscar($desde);
        $linhas = $this->linhasPorPedido($transacoes);

        $externos = array_values(array_unique(array_column($linhas, 'external_order_id')));
        $pedidos = Order::query()->where('origin', Order::ORIGIN_AMAZON)->whereIn('external_order_id', $externos)
            ->get(['id', 'external_order_id', 'status', 'created_at', 'estornado_em'])->keyBy('external_order_id');
        $comLiquidacao = DB::table('marketplace_settlement_details')->where('channel', Order::ORIGIN_AMAZON)
            ->where('transaction_type', 'Order')->where('statement_id', '!=', '')
            ->whereIn('external_order_id', $externos)->distinct()->pluck('external_order_id')->flip();

        $resumo = [
            'desde' => $desde->toDateString(), 'transacoes' => count($transacoes),
            'por_tipo' => collect($transacoes)->countBy(fn ($t) => ($t['transactionType'] ?? '?').'/'.($t['transactionStatus'] ?? '?'))->sortKeys()->all(),
            'pedidos_api' => 0, 'linhas_gravadas' => 0, 'linhas_iguais' => 0,
            'sem_pedido' => [], 'fora_da_janela' => [], 'com_liquidacao' => [], 'reembolsos_contando_como_venda' => [],
            'taxas' => ['novas' => 0, 'alteradas' => 0, 'iguais' => 0, 'manuais' => 0, 'mudancas' => []],
            'conferencia' => [],
        ];

        $aplicar = [];
        foreach ($linhas as $linha) {
            $externo = $linha['external_order_id'];
            $pedido = $pedidos[$externo] ?? null;

            if ($linha['transaction_type'] === 'Refund') {
                if ($pedido && $pedido->estornado_em === null && in_array($pedido->status, ContributionMargin::REVENUE_STATUSES, true)) {
                    $resumo['reembolsos_contando_como_venda'][] = "#{$pedido->id} {$externo}";
                }
            } else {
                $resumo['pedidos_api']++;
            }

            if (! $pedido) {
                $resumo['sem_pedido'][$externo] = $externo;

                continue;
            }
            if ($pedido->created_at && $pedido->created_at->lt($desde)) {
                $resumo['fora_da_janela'][$externo] = "#{$pedido->id}";

                continue;
            }
            if (isset($comLiquidacao[$externo])) {
                $resumo['com_liquidacao'][$externo] = "#{$pedido->id}";

                continue;
            }

            if ($linha['transaction_type'] === 'Order') {
                $aplicar[$externo] = $pedido->id;
            }
            if ($this->linhaIgual($linha)) {
                $resumo['linhas_iguais']++;

                continue;
            }
            if (! $seco) {
                $agora = now();
                DB::table('marketplace_settlement_details')->upsert(
                    [$linha + ['created_at' => $agora, 'updated_at' => $agora]],
                    ['channel', 'statement_id', 'external_order_id', 'external_sku_id', 'transaction_type'],
                    [...array_values(array_diff(array_keys($linha), ['channel', 'statement_id', 'external_order_id', 'external_sku_id', 'transaction_type'])), 'updated_at'],
                );
            }
            $resumo['linhas_gravadas']++;
        }

        $resumo['conferencia'] = $this->conferencia($linhas, $aplicar);
        if ($seco) {
            foreach ($resumo['conferencia'] as $c) {
                match (true) {
                    $c['fonte'] === 'manual' => $resumo['taxas']['manuais']++,
                    $c['sistema'] === null => $resumo['taxas']['novas']++,
                    $c['fonte'] === 'report' && $c['igual'] => $resumo['taxas']['iguais']++,
                    default => $resumo['taxas']['alteradas']++,
                };
            }
        } elseif ($aplicar !== []) {
            $resumo['taxas'] = $this->importador->aplicarTaxasReaisDetalhado(array_keys($aplicar));
        }

        $resumo['sem_pedido'] = array_values($resumo['sem_pedido']);
        $resumo['fora_da_janela'] = array_values($resumo['fora_da_janela']);
        $resumo['com_liquidacao'] = array_values($resumo['com_liquidacao']);

        return $resumo;
    }

    /**
     * Taxa que a API dá × taxa gravada hoje, por pedido (antes de gravar).
     *
     * @param  array<string, array<string, mixed>>  $linhas
     * @param  array<string, int>  $aplicar  externo => order_id
     * @return list<array{order_id: int, pedido: string, api: float, sistema: ?float, fonte: ?string, igual: bool, status: ?string}>
     */
    private function conferencia(array $linhas, array $aplicar): array
    {
        $atuais = DB::table('order_channel_fees')->where('channel', Order::ORIGIN_AMAZON)
            ->whereIn('order_id', array_values($aplicar))->get(['order_id', 'fee_amount', 'source'])->keyBy('order_id');

        $saida = [];
        foreach ($aplicar as $externo => $orderId) {
            $l = $linhas["{$externo}|Order"];
            $api = ImportadorRelatorioAmazon::taxaDoPedido((object) [
                'produto' => $l['product_net_sales'], 'frete_cliente' => $l['customer_shipping_fee'], 'liquido' => $l['payout_amount'],
                'comissao' => $l['platform_commission_fee'], 'servico' => $l['service_fees'], 'frete' => $l['shipping_cost'],
                'desconto' => $l['seller_discounts'], 'impostos' => $l['taxes'],
            ])['fee_amount'];
            $atual = $atuais[$orderId] ?? null;
            $sistema = $atual ? round((float) $atual->fee_amount, 2) : null;
            $saida[] = ['order_id' => $orderId, 'pedido' => $externo, 'api' => $api, 'sistema' => $sistema, 'fonte' => $atual?->source,
                'igual' => $sistema !== null && abs($sistema - $api) < 0.005, 'status' => $l['status']];
        }

        return $saida;
    }

    private function linhaIgual(array $linha): bool
    {
        $atual = DB::table('marketplace_settlement_details')->where('channel', $linha['channel'])->where('statement_id', '')
            ->where('external_order_id', $linha['external_order_id'])->where('external_sku_id', '')
            ->where('transaction_type', $linha['transaction_type'])->first();

        if (! $atual || $atual->source_file !== self::ORIGEM || $atual->status !== $linha['status']) {
            return false;
        }
        foreach (self::DINHEIRO as $coluna) {
            if (abs((float) $atual->{$coluna} - $linha[$coluna]) >= 0.005) {
                return false;
            }
        }

        return true;
    }

    /**
     * Componente de um item => colunas. AmazonFees é aberto nas partes
     * (comissão, frete cobrado da loja, demais tarifas).
     *
     * @return array<string, float>
     */
    private function colunas(array $b, string $tipo): array
    {
        $nome = (string) ($b['breakdownType'] ?? '');
        $valor = (float) ($b['breakdownAmount']['currencyAmount'] ?? 0);

        if ($nome === 'AmazonFees' && ! empty($b['breakdowns'])) {
            $partes = [];
            $soma = 0.0;
            foreach ($b['breakdowns'] as $sub) {
                $subNome = mb_strtolower((string) ($sub['breakdownType'] ?? ''));
                $subValor = (float) ($sub['breakdownAmount']['currencyAmount'] ?? 0);
                $coluna = match (true) {
                    str_contains($subNome, 'commission') => 'platform_commission_fee',
                    str_contains($subNome, 'shipping') => 'shipping_cost',
                    default => 'service_fees',
                };
                $partes[$coluna] = ($partes[$coluna] ?? 0) + $subValor;
                $soma += $subValor;
            }
            if (abs($soma - $valor) >= 0.005) {
                $partes['service_fees'] = ($partes['service_fees'] ?? 0) + ($valor - $soma);
            }

            return $partes;
        }

        $n = mb_strtolower($nome);

        return [match (true) {
            $nome === 'ProductCharges' => $tipo === 'Refund' ? 'product_refunds' : 'product_net_sales',
            $nome === 'Shipping' => 'customer_shipping_fee',
            $nome === 'AmazonFees' => 'platform_commission_fee',
            $nome === 'Expenses' => 'service_fees',
            str_contains($n, 'promo') => 'seller_discounts',
            str_contains($n, 'tax') => 'taxes',
            default => 'adjustment_amount',
        } => $valor];
    }

    /** @return array<string, string> nome => valor */
    private function identificadores(array $t): array
    {
        $ids = [];
        foreach ($t['relatedIdentifiers'] ?? [] as $r) {
            if (isset($r['relatedIdentifierName'], $r['relatedIdentifierValue'])) {
                $ids[$r['relatedIdentifierName']] = (string) $r['relatedIdentifierValue'];
            }
        }

        return $ids;
    }

    private function ordemStatus(array $t): int
    {
        return self::STATUS_ORDEM[$t['transactionStatus'] ?? ''] ?? 0;
    }

    /** 429 (limite de chamadas) e 5xx tentam de novo com pausa crescente. */
    private function comRetentativa(callable $chamada): array
    {
        for ($tentativa = 1; ; $tentativa++) {
            try {
                return $chamada();
            } catch (AmazonException $e) {
                $codigo = (int) $e->getCode();
                if ($tentativa >= 4 || ! ($codigo === 429 || $codigo >= 500)) {
                    throw $e;
                }
                Sleep::for(5 * $tentativa)->seconds();
            }
        }
    }
}
