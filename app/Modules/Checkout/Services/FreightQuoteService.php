<?php

namespace App\Modules\Checkout\Services;

use App\Modules\Fiscal\Models\Company;
use App\Services\MelhorEnvio\Exceptions\MelhorEnvioException;
use App\Services\MelhorEnvio\MelhorEnvioClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Cota frete real pro carrinho atual — Melhor Envio e Correios (ver
 * CorreiosFreightQuoteService), combinados numa lista só. Nunca lança —
 * cada fonte devolve [] sempre que a cotação não é possível pra ela (sem
 * token configurado, empresa sem CEP, produto sem peso/dimensões, API
 * fora do ar) sem afetar a outra; se as duas vierem vazias, o checkout cai
 * de volta pras formas de envio estáticas (ShippingMethod), nunca trava a
 * compra.
 */
class FreightQuoteService
{
    public function __construct(
        private readonly MelhorEnvioClient $client,
        private readonly CorreiosFreightQuoteService $correios,
    ) {
    }

    /**
     * @param  Collection<int, array{product: \App\Modules\Catalog\Models\Product, quantity: int}>  $cartItems
     * @return array<int, array{id: string, name: string, carrier_name: string, price: float, estimated_days: int}>
     */
    public function quote(Collection $cartItems, string $destinationZip): array
    {
        $quotes = [
            ...$this->quoteMelhorEnvio($cartItems, $destinationZip),
            ...$this->correios->quote($cartItems, $destinationZip),
        ];

        usort($quotes, fn ($a, $b) => $a['price'] <=> $b['price']);

        return $quotes;
    }

    /**
     * @param  Collection<int, array{product: \App\Modules\Catalog\Models\Product, quantity: int}>  $cartItems
     * @return array<int, array{id: string, name: string, carrier_name: string, price: float, estimated_days: int}>
     */
    private function quoteMelhorEnvio(Collection $cartItems, string $destinationZip): array
    {
        if (! $this->client->isConfigured()) {
            return [];
        }

        $fromZip = Company::query()->first()?->zip;

        if (! $fromZip) {
            return [];
        }

        $products = $this->buildProductsPayload($cartItems);

        if ($products === null) {
            return [];
        }

        try {
            $response = $this->client->post('me/shipment/calculate', [
                'from' => ['postal_code' => $this->digitsOnly($fromZip)],
                'to' => ['postal_code' => $this->digitsOnly($destinationZip)],
                'products' => $products,
            ]);
        } catch (MelhorEnvioException $exception) {
            Log::channel(config('melhorenvio.log_channel'))->warning('melhorenvio.quote.failed', [
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        return $this->mapQuotes($response);
    }

    /**
     * @param  Collection<int, array{product: \App\Modules\Catalog\Models\Product, quantity: int}>  $cartItems
     */
    private function buildProductsPayload(Collection $cartItems): ?array
    {
        $products = [];

        foreach ($cartItems as $item) {
            $product = $item['product'];
            $fiscal = $product->fiscalData;

            if (! $fiscal || ! $fiscal->peso_bruto || ! $fiscal->altura_cm || ! $fiscal->largura_cm || ! $fiscal->profundidade_cm) {
                // Não inventamos peso/dimensão pra um produto sem cadastro —
                // sem esse dado a cotação da transportadora não é confiável.
                return null;
            }

            $products[] = [
                'id' => (string) $product->id,
                'width' => (float) $fiscal->largura_cm,
                'height' => (float) $fiscal->altura_cm,
                'length' => (float) $fiscal->profundidade_cm,
                'weight' => (float) $fiscal->peso_bruto,
                'insurance_value' => (float) $product->final_price,
                'quantity' => (int) $item['quantity'],
            ];
        }

        return $products;
    }

    /**
     * @return array<int, array{id: string, name: string, carrier_name: string, price: float, estimated_days: int}>
     */
    private function mapQuotes(array $response): array
    {
        $quotes = [];

        foreach ($response as $option) {
            if (! is_array($option) || isset($option['error']) || ! isset($option['price'])) {
                // Melhor Envio retorna 200 com um array onde cada
                // transportadora pode ter falhado individualmente (sem
                // atender a rota, produto muito pesado, etc) — só
                // descartamos essa opção, não a cotação inteira.
                continue;
            }

            $quotes[] = [
                'id' => 'me:'.$option['id'],
                'name' => $option['name'] ?? 'Frete',
                'carrier_name' => $option['company']['name'] ?? ($option['name'] ?? 'Melhor Envio'),
                'price' => (float) $option['price'],
                'estimated_days' => (int) ($option['delivery_time'] ?? 0),
            ];
        }

        usort($quotes, fn ($a, $b) => $a['price'] <=> $b['price']);

        return $quotes;
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }
}
