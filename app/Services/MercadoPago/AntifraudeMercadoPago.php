<?php

namespace App\Services\MercadoPago;

use App\Modules\Checkout\Models\Order;
use Illuminate\Support\Str;

/**
 * Dados que a prevenção a fraude do Mercado Pago usa para aprovar o cartão
 * (2026-11-10). ACHADO REAL: em 08/11 um cliente preencheu tudo, clicou
 * Finalizar duas vezes e o cartão voltou "high_risk" nas duas; o pedido do
 * cartão ia só com valor, token, nome, CPF e e-mail.
 *
 * Vai agora: telefone e endereço do comprador, endereço de entrega e os
 * itens. Conferido na API de Orders com token falso (sem cobrança):
 * - o esquema é rígido (campo desconhecido = 400 unsupported_properties) e
 *   aceita payer.phone, payer.address, shipment.address e items;
 * - a soma dos itens tem que ser IGUAL ao total_amount
 *   (order_items_total_amount_mismatch). Com frete, desconto ou pagamento
 *   dividido a soma não bate: vai um item só com o resumo e o valor da
 *   cobrança.
 */
final class AntifraudeMercadoPago
{
    /**
     * Campos para juntar ao corpo da Order do cartão.
     *
     * @return array{payer: array<string, mixed>, items?: list<array<string, mixed>>, shipment?: array<string, mixed>}
     */
    public static function campos(Order $order, float $valor): array
    {
        $order->loadMissing('items');

        $endereco = array_filter([
            'zip_code' => self::digitos($order->shipping_zip, 8),
            'street_name' => self::texto($order->shipping_street),
            'street_number' => self::texto($order->shipping_number),
            'neighborhood' => self::texto($order->shipping_neighborhood),
            'city' => self::texto($order->shipping_city),
            'state' => self::texto($order->shipping_state),
            'complement' => self::texto($order->shipping_complement),
        ]);
        $completo = isset($endereco['zip_code'], $endereco['street_name'], $endereco['city'], $endereco['state']);

        $payer = array_filter([
            'phone' => self::telefone($order->shipping_phone),
            'address' => $completo ? $endereco : null,
        ]);

        $campos = ['payer' => $payer];
        if ($completo) {
            $campos['shipment'] = ['address' => $endereco];
        }
        if ($itens = self::itens($order, $valor)) {
            $campos['items'] = $itens;
        }

        return $campos;
    }

    /** Junta os campos ao corpo da Order sem trocar o que já está nele. */
    public static function aplicar(array $corpo, Order $order, float $valor): array
    {
        $campos = rescue(fn () => self::campos($order, $valor), [], false);
        if ($campos === []) {
            return $corpo;
        }

        $corpo['payer'] = ($corpo['payer'] ?? []) + ($campos['payer'] ?? []);
        foreach (['items', 'shipment'] as $chave) {
            if (isset($campos[$chave])) {
                $corpo[$chave] = $campos[$chave];
            }
        }

        return $corpo;
    }

    /** @return list<array<string, mixed>> */
    private static function itens(Order $order, float $valor): array
    {
        $itens = $order->items
            ->filter(fn ($i) => (int) $i->quantity > 0 && filled($i->product_name))
            ->map(fn ($i) => [
                'title' => Str::limit((string) $i->product_name, 120, ''),
                'unit_price' => number_format((float) $i->product_price, 2, '.', ''),
                'quantity' => (int) $i->quantity,
                'external_code' => (string) ($i->product_id ?? $i->id),
            ])->values();

        if ($itens->isEmpty()) {
            return [];
        }

        $soma = $itens->sum(fn ($i) => round((float) $i['unit_price'] * $i['quantity'], 2));
        if (abs($soma - $valor) < 0.005) {
            return $itens->all();
        }

        // Frete, desconto ou pagamento dividido: um item com o resumo e o valor cobrado.
        $primeiro = $itens->first()['title'];
        $resto = $itens->sum('quantity') - $itens->first()['quantity'];

        return [[
            'title' => Str::limit($primeiro.($resto > 0 ? " e mais {$resto} ".($resto === 1 ? 'item' : 'itens') : ''), 120, ''),
            'unit_price' => number_format($valor, 2, '.', ''),
            'quantity' => 1,
            'external_code' => 'pedido-'.$order->id,
        ]];
    }

    /** @return array{area_code: string, number: string}|null */
    private static function telefone(?string $telefone): ?array
    {
        $d = preg_replace('/\D/', '', (string) $telefone) ?? '';
        if (str_starts_with($d, '55') && strlen($d) >= 12) {
            $d = substr($d, 2);
        }

        return strlen($d) >= 10 && strlen($d) <= 11 ? ['area_code' => substr($d, 0, 2), 'number' => substr($d, 2)] : null;
    }

    private static function digitos(?string $valor, int $tamanho): ?string
    {
        $d = preg_replace('/\D/', '', (string) $valor) ?? '';

        return strlen($d) === $tamanho ? $d : null;
    }

    private static function texto(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : Str::limit($valor, 100, '');
    }
}
