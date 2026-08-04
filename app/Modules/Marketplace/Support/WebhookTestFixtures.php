<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;

/**
 * Payloads fake usados pela tela de teste de webhook
 * (Admin/Impressoes/TesteWebhook) — não uma cópia literal do payload que
 * cada marketplace envia de verdade (o webhook real do Mercado Livre, por
 * exemplo, só traz `{"resource":"/orders/{id}"}`, e o resto vem de uma
 * chamada de API separada — ver MercadoLivreDriver::importOrder()), mas sim
 * o formato realista do que a API de cada canal devolve depois dessa busca,
 * já que é isso que o pedido de teste precisa simular pra exercitar o
 * pipeline real (import -> estoque -> etiqueta -> nota).
 *
 * Mercado Livre e Shopee seguem o schema real documentado/confirmado ao
 * vivo (ver MercadoLivreDriver/ShopeeDriver). TikTok Shop, Amazon e Shein
 * NÃO têm integração real construída ainda — o formato usado pra elas aqui
 * é genérico/ilustrativo, não o payload real desses marketplaces.
 */
class WebhookTestFixtures
{
    public const CHANNELS = [
        MarketplaceAccount::CHANNEL_MERCADO_LIVRE => 'Mercado Livre',
        MarketplaceAccount::CHANNEL_SHOPEE => 'Shopee',
        MarketplaceAccount::CHANNEL_TIKTOK_SHOP => 'TikTok Shop',
        MarketplaceAccount::CHANNEL_AMAZON => 'Amazon',
        MarketplaceAccount::CHANNEL_SHEIN => 'Shein',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function raw(string $channel): array
    {
        return match ($channel) {
            MarketplaceAccount::CHANNEL_MERCADO_LIVRE => self::mercadoLivre(),
            MarketplaceAccount::CHANNEL_SHOPEE => self::shopee(),
            MarketplaceAccount::CHANNEL_TIKTOK_SHOP => self::generico('TikTok Shop'),
            MarketplaceAccount::CHANNEL_AMAZON => self::generico('Amazon'),
            MarketplaceAccount::CHANNEL_SHEIN => self::generico('Shein'),
            default => throw new \InvalidArgumentException("Canal desconhecido: {$channel}"),
        };
    }

    /**
     * Converte o payload cru (`raw()`) pro formato normalizado que
     * OrderImportService::importNormalized() espera — mesmo mapeamento de
     * campos que o driver real faz, só que a partir de dados fake em vez de
     * uma chamada HTTP de verdade. `external_order_id` ganha um sufixo
     * único a cada chamada pra nunca colidir com um teste anterior.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function normalize(string $channel, array $raw): array
    {
        $suffix = now()->format('YmdHisv');

        return match ($channel) {
            MarketplaceAccount::CHANNEL_MERCADO_LIVRE => self::normalizeMercadoLivre($raw, $suffix),
            MarketplaceAccount::CHANNEL_SHOPEE => self::normalizeShopee($raw, $suffix),
            default => self::normalizeGenerico($raw, $suffix),
        };
    }

    private static function mercadoLivre(): array
    {
        return [
            'order' => [
                'id' => 2000099999999,
                'status' => 'paid',
                'total_amount' => 43.99,
                'order_items' => [
                    [
                        'item' => ['id' => 'MLB4923941751', 'title' => 'Tabua De Descongelar Rápido Carne Alimentos Defrost Express Preto'],
                        'quantity' => 1,
                        'unit_price' => 43.99,
                        'sale_fee' => 6.16,
                    ],
                ],
                'buyer' => [
                    'first_name' => 'João',
                    'last_name' => 'Comprador Teste',
                    'email' => 'teste12345abc@mail.mercadolivre.com',
                    'phone' => ['area_code' => '11', 'number' => '999999999'],
                    'alternative_phone' => ['area_code' => '', 'number' => ''],
                ],
                'shipping' => ['id' => 40999999999],
            ],
            'shipment' => [
                'id' => 40999999999,
                'status' => 'ready_to_ship',
                'substatus' => 'ready_to_print',
                'logistic_type' => 'self_service',
                'destination' => [
                    'shipping_address' => [
                        'zip_code' => '01310-100',
                        'street_name' => 'Avenida Paulista',
                        'street_number' => '1000',
                        'comment' => 'Apto 45',
                        'neighborhood' => ['name' => 'Bela Vista'],
                        'city' => ['name' => 'São Paulo'],
                        'state' => ['id' => 'BR-SP'],
                    ],
                ],
            ],
        ];
    }

    private static function normalizeMercadoLivre(array $raw, string $suffix): array
    {
        $order = $raw['order'];
        $address = $raw['shipment']['destination']['shipping_address'];
        $buyer = $order['buyer'];

        $itemsSubtotal = 0.0;
        $marketplaceFee = 0.0;
        $items = [];

        foreach ($order['order_items'] as $item) {
            $itemsSubtotal += $item['unit_price'] * $item['quantity'];
            $marketplaceFee += ($item['sale_fee'] ?? 0) * $item['quantity'];
            $items[] = ['external_id' => $item['item']['id'], 'quantity' => $item['quantity'], 'unit_price' => $item['unit_price']];
        }

        return [
            'external_order_id' => "TESTE-{$order['id']}-{$suffix}",
            'status' => Order::STATUS_PAID,
            'subtotal' => round($itemsSubtotal, 2),
            'shipping_cost' => 0.0,
            'total' => round($order['total_amount'], 2),
            'marketplace_fee' => round($marketplaceFee, 2),
            'buyer_name' => trim($buyer['first_name'].' '.$buyer['last_name']),
            'buyer_phone' => $buyer['phone']['number'] ?? null,
            'buyer_email' => $buyer['email'] ?? null,
            'buyer_whatsapp' => $buyer['alternative_phone']['number'] ?: null,
            'shipping_zip' => $address['zip_code'],
            'shipping_street' => $address['street_name'],
            'shipping_number' => $address['street_number'],
            'shipping_complement' => $address['comment'] ?: null,
            'shipping_neighborhood' => $address['neighborhood']['name'],
            'shipping_city' => $address['city']['name'],
            'shipping_state' => substr($address['state']['id'], -2),
            'external_shipment_id' => (string) $raw['shipment']['id'],
            // 'simulated_shipping_method' não faz parte do formato real de
            // importOrder() — usado só pelo WebhookTestController pra saber
            // que tipo de envio simular na etapa de confirmação (ver lá).
            'simulated_shipping_method' => $raw['shipment']['logistic_type'],
            'items' => $items,
        ];
    }

    private static function shopee(): array
    {
        return [
            'order_sn' => 'TESTEBR999999999',
            'order_status' => 'READY_TO_SHIP',
            'total_amount' => 43.99,
            'buyer_username' => 'compradora_teste_shopee',
            'recipient_address' => [
                'name' => 'Maria Compradora Teste',
                'phone' => '11988887777',
                'full_address' => 'Rua Augusta, 500',
                'zipcode' => '01305-000',
                'district' => 'Consolação',
                'city' => 'São Paulo',
                'state' => 'SP',
            ],
            'item_list' => [
                [
                    'item_id' => 999999999,
                    'model_quantity_purchased' => 1,
                    'model_discounted_price' => 43.99,
                ],
            ],
            'actual_shipping_fee' => 0,
        ];
    }

    private static function normalizeShopee(array $raw, string $suffix): array
    {
        $address = $raw['recipient_address'];
        $itemsSubtotal = 0.0;
        $items = [];

        foreach ($raw['item_list'] as $item) {
            $itemsSubtotal += $item['model_discounted_price'] * $item['model_quantity_purchased'];
            $items[] = ['external_id' => (string) $item['item_id'], 'quantity' => $item['model_quantity_purchased'], 'unit_price' => $item['model_discounted_price']];
        }

        return [
            'external_order_id' => "TESTE-{$raw['order_sn']}-{$suffix}",
            'status' => Order::STATUS_PAID,
            'subtotal' => round($itemsSubtotal, 2),
            'shipping_cost' => round((float) $raw['actual_shipping_fee'], 2),
            'total' => round($raw['total_amount'], 2),
            'buyer_name' => $address['name'] ?? $raw['buyer_username'],
            'buyer_phone' => $address['phone'] ?? null,
            'shipping_zip' => $address['zipcode'],
            'shipping_street' => $address['full_address'],
            'shipping_number' => 'S/N',
            'shipping_complement' => null,
            'shipping_neighborhood' => $address['district'],
            'shipping_city' => $address['city'],
            'shipping_state' => substr(strtoupper($address['state']), 0, 2),
            'external_shipment_id' => null,
            'simulated_shipping_method' => 'drop_off',
            'items' => $items,
        ];
    }

    private static function generico(string $displayName): array
    {
        return [
            '_aviso' => "Formato ilustrativo — {$displayName} ainda não tem integração real construída, esse NÃO é o payload real desse marketplace.",
            'order_id' => 'TESTE999999999',
            'status' => 'paid',
            'buyer' => ['name' => 'Cliente Teste', 'phone' => '11966665555'],
            'shipping_address' => [
                'zip' => '04538-133',
                'street' => 'Avenida Brigadeiro Faria Lima',
                'number' => '2000',
                'complement' => null,
                'neighborhood' => 'Itaim Bibi',
                'city' => 'São Paulo',
                'state' => 'SP',
            ],
            'items' => [
                ['id' => 'TESTE-ITEM-001', 'quantity' => 1, 'unit_price' => 43.99],
            ],
            'total' => 43.99,
        ];
    }

    private static function normalizeGenerico(array $raw, string $suffix): array
    {
        $address = $raw['shipping_address'];
        $itemsSubtotal = 0.0;
        $items = [];

        foreach ($raw['items'] as $item) {
            $itemsSubtotal += $item['unit_price'] * $item['quantity'];
            $items[] = ['external_id' => $item['id'], 'quantity' => $item['quantity'], 'unit_price' => $item['unit_price']];
        }

        return [
            'external_order_id' => "TESTE-{$raw['order_id']}-{$suffix}",
            'status' => Order::STATUS_PAID,
            'subtotal' => round($itemsSubtotal, 2),
            'shipping_cost' => 0.0,
            'total' => round($raw['total'], 2),
            'buyer_name' => $raw['buyer']['name'],
            'buyer_phone' => $raw['buyer']['phone'],
            'shipping_zip' => $address['zip'],
            'shipping_street' => $address['street'],
            'shipping_number' => $address['number'],
            'shipping_complement' => $address['complement'],
            'shipping_neighborhood' => $address['neighborhood'],
            'shipping_city' => $address['city'],
            'shipping_state' => $address['state'],
            'external_shipment_id' => null,
            'simulated_shipping_method' => 'drop_off',
            'items' => $items,
        ];
    }
}
