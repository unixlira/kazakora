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
        // Janela de 7 dias — mesmo limite máximo confirmado ao vivo pra
        // esse endpoint ("time period too large" acima disso). Só precisa
        // da transação mais recente pra saber o saldo agora.
        $response = $this->client->get('/api/v2/payment/get_wallet_transaction_list', [
            'page_no' => 1,
            'page_size' => 20,
            'create_time_from' => Carbon::now()->subDays(7)->timestamp,
            'create_time_to' => Carbon::now()->timestamp,
        ]);

        $transactions = $response['response']['transaction_list'] ?? [];

        if (empty($transactions)) {
            return null;
        }

        // A API não documenta a ordenação — ordena por create_time aqui
        // pra garantir que pega o saldo depois da transação mais recente,
        // não depender do que a Shopee devolveu primeiro.
        usort($transactions, fn ($a, $b) => ($b['create_time'] ?? 0) <=> ($a['create_time'] ?? 0));

        return isset($transactions[0]['current_balance']) ? (float) $transactions[0]['current_balance'] : null;
    }
}
