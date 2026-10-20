<?php

namespace App\Services\Shopee;

use Illuminate\Support\Carbon;

/**
 * Saldo disponível pra saque (carteira Shopee, dinheiro de venda já
 * liberado) — pedido explícito 2026-08-09 ("saldo atual disponível para
 * saque nas plataformas"). Confirmado ao vivo 2026-08-09: a própria Shopee
 * não tem um endpoint "saldo atual" direto, mas cada transação do extrato
 * (get_wallet_transaction_list) já vem com current_balance — o saldo
 * imediatamente depois daquela transação. A mais recente = o saldo agora.
 */
class ShopeeWalletService
{
    public function __construct(private readonly ShopeeClient $client)
    {
    }

    public function currentBalance(): ?float
    {
        // Janela de 7 dias: limite máximo confirmado ao vivo pra esse
        // endpoint ("time period too large" acima disso). Semana sem
        // nenhuma transação (loja parada) volta mais 3 semanas, de 7 em 7
        // dias, antes de desistir (2026-10-19, aba Saldos nos canais).
        $ate = Carbon::now();

        for ($janela = 0; $janela < 4; $janela++) {
            $response = $this->client->get('/api/v2/payment/get_wallet_transaction_list', [
                'page_no' => 1,
                'page_size' => 20,
                'create_time_from' => $ate->copy()->subDays(7)->timestamp,
                'create_time_to' => $ate->timestamp,
            ]);

            $transactions = $response['response']['transaction_list'] ?? [];

            if (! empty($transactions)) {
                // A API não documenta a ordenação: ordena por create_time
                // pra pegar o saldo depois da transação mais recente.
                usort($transactions, fn ($a, $b) => ($b['create_time'] ?? 0) <=> ($a['create_time'] ?? 0));

                return isset($transactions[0]['current_balance']) ? (float) $transactions[0]['current_balance'] : null;
            }

            $ate = $ate->copy()->subDays(7);
        }

        return null;
    }

    /**
     * Total que a Shopee ainda vai liberar (pedidos pagos, escrow não
     * liberado) e o total já liberado, como na tela "Minha renda" do
     * Seller Center. Confirmado ao vivo em 2026-10-19:
     * {"total_income":{"pending_amount":5302.97,"released_amount":35149.48}}.
     *
     * @return array{pendente: float, liberado: float}
     */
    public function incomeOverview(): array
    {
        $response = $this->client->get('/api/v2/payment/get_income_overview');
        $total = $response['response']['total_income'] ?? [];

        return [
            'pendente' => round((float) ($total['pending_amount'] ?? 0), 2),
            'liberado' => round((float) ($total['released_amount'] ?? 0), 2),
        ];
    }

    /**
     * Pedidos com renda pendente (income_status 2), um por pedido:
     * order_sn, estimated_escrow_amount, estimated_payout_time (0 enquanto
     * o pedido não foi concluído; com data quando o pagamento já foi
     * iniciado) e status em texto. Loja local não aceita o status 0 ("To
     * Release"), achado ao vivo em 2026-10-19.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingIncome(): array
    {
        $lista = [];
        $cursor = '';

        for ($pagina = 0; $pagina < 50; $pagina++) {
            $response = $this->client->get('/api/v2/payment/get_income_detail', [
                'income_status' => 2,
                'page_size' => 100,
                'cursor' => $cursor,
            ]);

            $itens = $response['response']['list'] ?? [];
            array_push($lista, ...$itens);
            $cursor = (string) ($response['response']['next_page']['cursor'] ?? '');

            if ($cursor === '' || $itens === []) {
                break;
            }
        }

        return $lista;
    }
}
