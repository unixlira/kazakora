<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;

/**
 * Como este pedido vai sair da loja — Flex, Mercado Envios, Full,
 * Express-Ponto Coleta (Shopee e TikTok), e por aí vai.
 *
 * Pedido explícito 2026-09-14: o KoraSync mostrava só o canal, e canal não
 * responde a pergunta que o operador faz na bancada. Duas vendas do Mercado
 * Livre lado a lado podem ser coisas completamente diferentes: uma sai com
 * o motoboy do Flex hoje, outra vai pra agência, e a do Full nem devia
 * estar aqui (o estoque é do ML).
 *
 * O dado já existia: `channel_shipments.shipping_method` guarda o que cada
 * canal devolve — só que crú e em formato de cada um. O Mercado Livre manda
 * `logistic_type` ("self_service"), a Shopee manda o nome da
 * transportadora ("Shopee Xpress"), o TikTok manda um código de serviço
 * ("LSV-Standard-BR PICKUP") e a Amazon manda o nome do carrier. Traduzir
 * isso na tela de cada consumidor seria escrever a mesma tabela três vezes
 * (app desktop, espelho no admin e sync.alphakora), então a tradução mora
 * aqui e viaja pronta no payload.
 *
 * `tipo` é o slug pra tela pintar/agrupar, `label` é o texto completo e
 * `curto` é o que cabe num selo ao lado do nome do canal.
 */
final class TipoDeEnvio
{
    public const FLEX = 'flex';

    public const FULL = 'full';

    public const MERCADO_ENVIOS = 'mercado_envios';

    /**
     * Shopee e TikTok saem do mesmo jeito daqui (pedido do usuário em
     * 2026-09-21: "as 2 são Express-Ponto Coleta") — o pacote vai pro ponto
     * de coleta, a transportadora leva. Eram dois tipos diferentes no selo
     * ("Shopee Xpress" e "Coleta") pra uma coisa só na bancada, e o nome
     * que o operador usa não era nenhum dos dois.
     */
    public const EXPRESS_COLETA = 'express_coleta';

    public const RETIRADA = 'retirada';

    /**
     * Correios pela pré-postagem da própria loja (Amazon via Bling desde
     * 2026-09-25, ver CorreiosAutoShipping). PAC e SEDEX em tipos
     * separados: pedido explícito do usuário — o card tem que dizer
     * "Correios - PAC" ou "Correios - SEDEX", que é o que decide pra qual
     * lote o pacote vai.
     */
    public const CORREIOS_PAC = 'correios_pac';

    public const CORREIOS_SEDEX = 'correios_sedex';

    /** Correios, mas o serviço só é escolhido (o mais barato) quando a pré-postagem sai. */
    public const CORREIOS = 'correios';

    public const PROPRIO = 'proprio';

    public const OUTRO = 'outro';

    public const DESCONHECIDO = 'desconhecido';

    /**
     * Valores reais confirmados em produção (14/09/2026, tabela
     * channel_shipments): xd_drop_off 400, self_service 101, drop_off 11,
     * fulfillment 2. `cross_docking` não apareceu ainda, mas é o mesmo
     * fluxo de coleta do xd_drop_off e já fica mapeado.
     */
    private const MERCADO_LIVRE = [
        'self_service' => [self::FLEX, 'Flex — entrega própria', 'Flex'],
        'fulfillment' => [self::FULL, 'Full — sai do estoque do Mercado Livre', 'Full'],
        'drop_off' => [self::MERCADO_ENVIOS, 'Mercado Envios — despacho em agência', 'Mercado Envios'],
        'xd_drop_off' => [self::MERCADO_ENVIOS, 'Mercado Envios — coleta', 'Mercado Envios'],
        'cross_docking' => [self::MERCADO_ENVIOS, 'Mercado Envios — coleta', 'Mercado Envios'],
    ];

    /**
     * @return array{tipo: string, label: string, curto: string}
     */
    public static function doPedido(Order $order): array
    {
        $metodo = $order->channelShipment?->shipping_method;

        // Amazon com pré-postagem feita pela tela do menu Correios (não pelo
        // fluxo automático): o serviço está só na pré-postagem, o envio do
        // canal ficou sem método — caso real do pedido #2451 em 25/09.
        if ($order->origin === 'amazon' && ! $metodo) {
            $servico = CorreiosPrePostagem::query()
                ->where('order_id', $order->id)
                ->where('status', CorreiosPrePostagem::STATUS_GERADA)
                ->latest('id')
                ->value('service_label');

            $metodo = $servico ? "Correios {$servico}" : null;
        }

        return self::montar($order->origin, $metodo, $order->shipping_carrier_name);
    }

    /**
     * @return array{tipo: string, label: string, curto: string}
     */
    public static function montar(?string $canal, ?string $metodo, ?string $transportadora = null): array
    {
        $metodo = trim((string) $metodo);

        [$tipo, $label, $curto] = match ($canal) {
            'mercado_livre' => self::mercadoLivre($metodo),
            'shopee' => self::shopee($metodo),
            'tiktok_shop' => self::tiktok($metodo),
            'amazon' => self::amazon($metodo),
            'loja' => self::loja($metodo ?: (string) $transportadora),
            default => self::generico($metodo),
        };

        return ['tipo' => $tipo, 'label' => $label, 'curto' => $curto];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function mercadoLivre(string $metodo): array
    {
        return self::MERCADO_LIVRE[$metodo] ?? self::naoInformado($metodo);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function shopee(string $metodo): array
    {
        if ($metodo === '') {
            return self::naoInformado($metodo);
        }

        $normalizado = mb_strtolower($metodo);

        // "Retirada pelo Comprador" vem ANTES do Xpress de propósito: o
        // cliente busca na loja, não vai pra transportadora nenhuma — o
        // pacote não pode entrar no lote de despacho junto com o resto.
        if (str_contains($normalizado, 'retirada')) {
            return [self::RETIRADA, 'Retirada pelo comprador', 'Retirada'];
        }

        if (str_contains($normalizado, 'xpress')) {
            return [self::EXPRESS_COLETA, 'Shopee Xpress — Express, entrega no ponto de coleta', 'Express-Ponto Coleta'];
        }

        return [self::OUTRO, $metodo, $metodo];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function tiktok(string $metodo): array
    {
        if ($metodo === '') {
            return self::naoInformado($metodo);
        }

        // O TikTok manda o código do serviço, não um nome ("LSV-Standard-BR
        // PICKUP", o único valor visto até hoje). O que importa pro
        // operador é o sufixo: PICKUP = vai pro ponto de coleta, igual à
        // Shopee. O código continua no label pra dar pra rastrear do outro
        // lado.
        if (str_contains(mb_strtoupper($metodo), 'PICKUP')) {
            return [self::EXPRESS_COLETA, "TikTok — Express, entrega no ponto de coleta ({$metodo})", 'Express-Ponto Coleta'];
        }

        return [self::OUTRO, "TikTok — {$metodo}", $metodo];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function amazon(string $metodo): array
    {
        // Amazon pelo Bling sai sempre pelos Correios da loja; antes da
        // pré-postagem (nota ainda saindo) o serviço ainda não foi escolhido.
        if ($metodo === '') {
            return [self::CORREIOS, 'Correios — PAC ou SEDEX, o mais barato, escolhido na pré-postagem', 'Correios'];
        }

        // CorreiosAutoShipping grava "Correios PAC (contrato)" /
        // "Correios SEDEX (contrato)".
        if ($correios = self::correios($metodo)) {
            return $correios;
        }

        // AmazonDriver::confirmShipping() grava o CarrierName do
        // ShippingService quando a Amazon devolve um, e cai no literal
        // 'merchant_fulfillment' quando não vem nenhum — nos dois casos é
        // pedido que sai daqui (MFN), não do estoque da Amazon.
        if ($metodo === 'merchant_fulfillment') {
            return [self::PROPRIO, 'Amazon — enviado pelo vendedor', 'Vendedor'];
        }

        return [self::OUTRO, "Amazon — {$metodo}", $metodo];
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private static function correios(string $metodo): ?array
    {
        $normalizado = mb_strtolower($metodo);

        if (! str_contains($normalizado, 'correios')) {
            return null;
        }

        return match (true) {
            str_contains($normalizado, 'sedex') => [self::CORREIOS_SEDEX, 'Correios - SEDEX', 'Correios - SEDEX'],
            str_contains($normalizado, 'pac') => [self::CORREIOS_PAC, 'Correios - PAC', 'Correios - PAC'],
            default => [self::CORREIOS, 'Correios', 'Correios'],
        };
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function loja(string $transportadora): array
    {
        if ($transportadora === '') {
            return self::naoInformado($transportadora);
        }

        return [self::OUTRO, $transportadora, $transportadora];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function generico(string $metodo): array
    {
        return $metodo === ''
            ? self::naoInformado($metodo)
            : [self::OUTRO, $metodo, $metodo];
    }

    /**
     * Pedido sem envio criado ainda (o canal só manda o envio depois de
     * confirmar o pagamento) ou método que não conhecemos. Dizer "não
     * informado" é melhor que chutar um tipo: o operador precisa saber que
     * o sistema não sabe, não receber um palpite.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function naoInformado(string $metodo): array
    {
        return $metodo === ''
            ? [self::DESCONHECIDO, 'Envio não informado', '—']
            : [self::OUTRO, $metodo, $metodo];
    }
}
