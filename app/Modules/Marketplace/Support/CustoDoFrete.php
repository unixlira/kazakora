<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\MelhorEnvioEnvio;

/**
 * Quanto a loja paga de frete num pedido do site (pedido do Lira 2026-11-14:
 * "o frete é grátis mas tem um custo, põe ele aí"; vale para KazaKora,
 * FerraKora e Beauty Kora). Frete grátis para o cliente não é frete de graça
 * para a loja: o detalhe do pedido no admin mostra esse valor à parte, sem
 * somar no total pago.
 *
 * Vem do que o pedido já tem gravado: a transportadora do Melhor Envio
 * (cotada na hora do pedido; depois de comprada a etiqueta, o valor pago) ou
 * a pré-postagem dos Correios. Sem nenhum dos dois, não inventa valor.
 */
class CustoDoFrete
{
    public const COTADO = 'cotado';

    public const PAGO = 'pago';

    /** @return array{valor: float, servico: string, situacao: string}|null */
    public static function doPedido(Order $order): ?array
    {
        $melhorEnvio = $order->melhorEnvioEnvio;

        if ($melhorEnvio && $melhorEnvio->preco !== null && $melhorEnvio->cancelada_em === null) {
            return [
                'valor' => (float) $melhorEnvio->preco,
                'servico' => (string) ($melhorEnvio->servico ?: 'Melhor Envio'),
                'situacao' => $melhorEnvio->paga_em ? self::PAGO : self::COTADO,
            ];
        }

        $correios = $order->latestCorreiosPrePostagem;

        if ($correios && $correios->status === CorreiosPrePostagem::STATUS_GERADA && $correios->postage_price !== null) {
            return [
                'valor' => (float) $correios->postage_price,
                'servico' => trim('Correios '.$correios->service_label),
                'situacao' => self::PAGO,
            ];
        }

        return null;
    }

    /** Transportadora do Melhor Envio escolhida no pedido, para a coluna e o bloco de envio do admin. */
    public static function servicoDoMelhorEnvio(Order $order): ?string
    {
        $melhorEnvio = $order->melhorEnvioEnvio;

        return $melhorEnvio && $melhorEnvio->cancelada_em === null && $melhorEnvio->status !== MelhorEnvioEnvio::STATUS_ERRO
            ? ($melhorEnvio->servico ?: null)
            : null;
    }
}
