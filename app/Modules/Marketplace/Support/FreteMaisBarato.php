<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Services\CorreiosFreightQuoteService;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\MelhorEnvioEnvio;
use App\Services\Correios\CorreiosPrePostagemService;
use App\Services\Correios\CorreiosTokenService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Compara o Melhor Envio com o contrato dos Correios antes de comprar a
 * etiqueta de um pedido do site (pedido do Lira em 2026-11-14, #4172: o
 * SEDEX saiu pelo Melhor Envio a R$ 16,53 e o contrato cobrava R$ 14,98
 * pelo mesmo pacote; "não pode falhar mais, deixa arrumado para todos os
 * projetos").
 *
 * Com o Melhor Envio conectado em Logística, a transportadora mais barata
 * dele continua sendo escolhida no pedido; na hora da etiqueta, se o
 * contrato dos Correios levar o MESMO pacote por menos, a etiqueta sai pela
 * pré-postagem dos Correios. Pedido com TURBO compara só com o SEDEX.
 *
 * Nunca trava a etiqueta: sem contrato configurado, sem cotação de um dos
 * lados ou com qualquer erro, fica o Melhor Envio, como antes.
 */
class FreteMaisBarato
{
    public function __construct(
        private readonly CorreiosTokenService $tokens,
        private readonly CorreiosFreightQuoteService $precos,
        private readonly PackageDataResolver $pacotes,
        private readonly MelhorEnvioAutoShipping $melhorEnvio,
    ) {}

    /** true = a etiqueta deste pedido sai pelos Correios, que cobram menos. */
    public function correiosGanha(Order $order): bool
    {
        if (! config('melhorenvio.comparar_correios', true) || ! $this->tokens->isConfigured()) {
            return false;
        }

        // Os Correios já recusaram a pré-postagem deste pedido: não insiste,
        // a etiqueta sai pelo Melhor Envio.
        if (CorreiosPrePostagem::query()->deEnvio()->where('order_id', $order->id)->where('status', CorreiosPrePostagem::STATUS_ERRO)->exists()) {
            return false;
        }

        try {
            $envio = $this->melhorEnvio->doPedido($order) ?? $this->melhorEnvio->escolherNoPedido($order);

            // Já comprado no Melhor Envio, ou sem preço para comparar: fica nele.
            if (! $envio || $envio->me_order_id || $envio->preco === null) {
                return false;
            }

            $correios = $this->correios($order);
            if ($correios === null || $correios['preco'] >= (float) $envio->preco) {
                return false;
            }

            Log::channel(config('melhorenvio.log_channel'))->info('frete.correios_mais_barato', [
                'order_id' => $order->id,
                'correios' => $correios,
                'melhor_envio' => ['servico' => $envio->servico, 'preco' => (float) $envio->preco],
            ]);

            // A cotação do Melhor Envio sai do pedido: o custo do frete e a
            // transportadora do admin passam a vir da pré-postagem dos Correios.
            if ($envio->status === MelhorEnvioEnvio::STATUS_COTADA || $envio->status === MelhorEnvioEnvio::STATUS_ERRO) {
                $envio->delete();
            }

            return true;
        } catch (Throwable $e) {
            Log::channel(config('melhorenvio.log_channel'))->warning('frete.comparacao_falhou', ['order_id' => $order->id, 'message' => $e->getMessage()]);

            return false;
        }
    }

    /** @return array{servico: string, preco: float}|null o mais barato do contrato para o pacote do pedido */
    private function correios(Order $order): ?array
    {
        $pacote = $this->pacotes->forOrder($order);
        $turbo = str_contains(mb_strtolower((string) $order->shipping_carrier_name), 'turbo');
        $servicos = $turbo ? [CorreiosPrePostagemService::SERVICO_SEDEX] : array_keys(CorreiosPrePostagemService::SERVICOS_CONTRATO);

        $melhor = null;
        foreach ($servicos as $servico) {
            $preco = $this->precos->priceFor(
                (string) $servico,
                (string) $order->shipping_zip,
                (int) $pacote['weight_grams'],
                (string) $pacote['format'],
                $pacote['height'],
                $pacote['width'],
                $pacote['length'],
                null,
            );

            if ($preco !== null && $preco > 0 && ($melhor === null || $preco < $melhor['preco'])) {
                $melhor = ['servico' => (string) $servico, 'preco' => (float) $preco];
            }
        }

        return $melhor;
    }
}
