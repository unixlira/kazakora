<?php

namespace App\Modules\Checkout\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Juros reais do parcelamento (Botrel, 2026-11-02): a loja mostrava preço ÷ 12,
 * e o Mercado Pago cobra juros a partir de 2x. A taxa vem da API e vale para
 * Visa, Master, Elo e Amex (conferido em 02/11); a parcela é calculada no navegador
 * com o preço da tela (Shared/parcelas.js). Sem resposta da API a tabela fica
 * vazia e a tela mostra só "em até 12x", sem valor.
 */
class ParcelasCartao
{
    public const MAXIMO = 12; // mesmo limite do checkout (CheckoutV2, maxInstallments)

    private const CHAVE = 'parcelas-cartao:tabela';

    private const TTL = 21600; // 6 h

    private const TTL_FALHA = 600;

    /** @return list<array{parcelas: int, taxa: float, minimo: float}> */
    public static function tabela(): array
    {
        $tabela = Cache::get(self::CHAVE);
        if (is_array($tabela)) {
            return $tabela;
        }

        $tabela = self::consultar();
        Cache::put(self::CHAVE, $tabela, $tabela === [] ? self::TTL_FALHA : self::TTL);

        return $tabela;
    }

    /** @return list<array{parcelas: int, taxa: float, minimo: float}> */
    private static function consultar(): array
    {
        $token = config('services.mercadopago.access_token');
        if (blank($token)) {
            return [];
        }

        try {
            $resposta = Http::withToken((string) $token)->timeout(3)
                ->get('https://api.mercadopago.com/v1/payment_methods/installments', ['amount' => 1000, 'payment_method_id' => 'visa']);
            $custos = $resposta->successful() ? ($resposta->json('0.payer_costs') ?? []) : [];
        } catch (Throwable) {
            return [];
        }

        return collect($custos)
            ->filter(fn ($custo) => (int) $custo['installments'] <= self::MAXIMO)
            ->map(fn ($custo) => [
                'parcelas' => (int) $custo['installments'],
                'taxa' => (float) $custo['installment_rate'],
                'minimo' => (float) $custo['min_allowed_amount'],
            ])
            ->sortBy('parcelas')
            ->values()
            ->all();
    }
}
