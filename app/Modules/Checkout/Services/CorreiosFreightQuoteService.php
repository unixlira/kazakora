<?php

namespace App\Modules\Checkout\Services;

use App\Modules\Fiscal\Models\Company;
use App\Services\Correios\CorreiosTokenService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cota frete real via API dos Correios (produtos "Preço" e "Prazo",
 * endpoint /v1/nacional de cada um) — pedido explícito 2026-08-16, usuário
 * já tem credencial da API dos Correios e não tem Melhor Envio conectado.
 * Mesma credencial de CorreiosTokenService (já usada pra pré-postagem),
 * mas é um produto de API diferente (path /preco e /prazo, não
 * /prepostagem) — confirmado contra o schema real (OpenAPI publicado em
 * apihom.correios.com.br/preco/v3/api-docs e /prazo/v3/api-docs) antes de
 * implementar, não uma suposição de blog/manual resumido.
 *
 * Só cota PAC e SEDEX (03298/03220) — os 2 serviços "encomenda normal"
 * que qualquer contrato cobre; outros códigos (Mini Envios, SEDEX 10/12
 * etc.) exigem serviço adicional específico que não dá pra confirmar que
 * a loja tem habilitado.
 *
 * Nunca lança — devolve [] sempre que a cotação não é possível (token não
 * configurado, empresa sem CEP, produto sem peso/dimensão, API fora do
 * ar) — mesma postura de graceful degradation de FreightQuoteService
 * (Melhor Envio), que o checkout já sabe tratar (cai pras formas de envio
 * estáticas).
 */
class CorreiosFreightQuoteService
{
    private const PRODUCTS = ['03298' => 'PAC', '03220' => 'SEDEX'];

    public function __construct(private readonly CorreiosTokenService $tokenService)
    {
    }

    /**
     * @param  Collection<int, array{product: \App\Modules\Catalog\Models\Product, quantity: int}>  $cartItems
     * @return array<int, array{id: string, name: string, carrier_name: string, price: float, estimated_days: int}>
     */
    public function quote(Collection $cartItems, string $destinationZip): array
    {
        if (! $this->tokenService->isConfigured()) {
            return [];
        }

        $fromZip = Company::query()->first()?->zip;

        if (! $fromZip) {
            return [];
        }

        $package = $this->buildPackage($cartItems);

        if ($package === null) {
            return [];
        }

        try {
            $token = $this->tokenService->token();
        } catch (Throwable $exception) {
            Log::channel('correios')->warning('correios.quote.token_failed', ['message' => $exception->getMessage()]);

            return [];
        }

        $fromDigits = $this->digitsOnly($fromZip);
        $toDigits = $this->digitsOnly($destinationZip);

        $prices = $this->fetchPrices($token, $package, $fromDigits, $toDigits);

        if (! $prices) {
            return [];
        }

        $deadlines = $this->fetchDeadlines($token, array_keys($prices), $fromDigits, $toDigits);

        $quotes = [];

        foreach ($prices as $coProduto => $price) {
            $quotes[] = [
                'id' => 'correios:'.$coProduto,
                'name' => self::PRODUCTS[$coProduto] ?? "Correios {$coProduto}",
                'carrier_name' => 'Correios',
                'price' => $price,
                'estimated_days' => $deadlines[$coProduto] ?? 0,
            ];
        }

        usort($quotes, fn ($a, $b) => $a['price'] <=> $b['price']);

        return $quotes;
    }

    /**
     * @param  Collection<int, array{product: \App\Modules\Catalog\Models\Product, quantity: int}>  $cartItems
     * @return ?array{weight_g: int, length: float, width: float, height: float}
     */
    private function buildPackage(Collection $cartItems): ?array
    {
        $weightKg = 0.0;
        $length = $width = $height = 0.0;

        // Diferente da Melhor Envio (que recebe cada item separado e
        // decide sozinha como agrupar em caixa), a API dos Correios cota
        // 1 pacote só por chamada — sem inventar um algoritmo de
        // empacotamento real, a aproximação honesta possível é: peso soma
        // de verdade (peso realmente soma), dimensão usa a do maior item
        // do carrinho (assume que os demais entram junto na mesma caixa).
        foreach ($cartItems as $item) {
            $fiscal = $item['product']->fiscalData;

            if (! $fiscal || ! $fiscal->peso_bruto || ! $fiscal->altura_cm || ! $fiscal->largura_cm || ! $fiscal->profundidade_cm) {
                return null;
            }

            $weightKg += (float) $fiscal->peso_bruto * $item['quantity'];
            $length = max($length, (float) $fiscal->profundidade_cm);
            $width = max($width, (float) $fiscal->largura_cm);
            $height = max($height, (float) $fiscal->altura_cm);
        }

        // Mínimos reais do Correios pra "Pacote" (tpObjeto=2): 16x11x2cm —
        // evita rejeição de item pequeno isolado sem fingir que o produto
        // é maior do que é de verdade.
        return [
            'weight_g' => max(1, (int) round($weightKg * 1000)),
            'length' => max(16.0, $length),
            'width' => max(11.0, $width),
            'height' => max(2.0, $height),
        ];
    }

    /**
     * @param  array{weight_g: int, length: float, width: float, height: float}  $package
     * @return array<string, float> coProduto => preço
     */
    private function fetchPrices(string $token, array $package, string $fromZip, string $toZip): array
    {
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(15)
                ->post(rtrim((string) config('services.correios.preco_base_url'), '/').'/v1/nacional', [
                    'idLote' => (string) Str::uuid(),
                    'parametrosProduto' => collect(self::PRODUCTS)->keys()->map(fn ($coProduto) => [
                        'coProduto' => $coProduto,
                        'nuRequisicao' => $coProduto,
                        'cepOrigem' => $fromZip,
                        'cepDestino' => $toZip,
                        'psObjeto' => (string) $package['weight_g'],
                        'tpObjeto' => '2',
                        'comprimento' => (string) $package['length'],
                        'largura' => (string) $package['width'],
                        'altura' => (string) $package['height'],
                    ])->values()->all(),
                ]);
        } catch (Throwable $exception) {
            Log::channel('correios')->warning('correios.quote.price_request_failed', ['message' => $exception->getMessage()]);

            return [];
        }

        if ($response->failed()) {
            Log::channel('correios')->warning('correios.quote.price_failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [];
        }

        $prices = [];

        foreach ((array) $response->json() as $item) {
            if (! is_array($item) || ! empty($item['txErro']) || ! isset($item['pcFinal'], $item['coProduto'])) {
                continue;
            }

            // pcFinal vem como string com vírgula decimal (ex.: "23,45").
            $prices[(string) $item['coProduto']] = (float) str_replace(',', '.', (string) $item['pcFinal']);
        }

        return $prices;
    }

    /**
     * @param  array<int, string>  $productCodes
     * @return array<string, int> coProduto => dias úteis
     */
    private function fetchDeadlines(string $token, array $productCodes, string $fromZip, string $toZip): array
    {
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(15)
                ->post(rtrim((string) config('services.correios.prazo_base_url'), '/').'/v1/nacional', [
                    'idLote' => (string) Str::uuid(),
                    'parametrosPrazo' => collect($productCodes)->map(fn ($coProduto) => [
                        'coProduto' => $coProduto,
                        'nuRequisicao' => $coProduto,
                        'cepOrigem' => $fromZip,
                        'cepDestino' => $toZip,
                    ])->values()->all(),
                ]);
        } catch (Throwable $exception) {
            Log::channel('correios')->warning('correios.quote.deadline_request_failed', ['message' => $exception->getMessage()]);

            return [];
        }

        if ($response->failed()) {
            Log::channel('correios')->warning('correios.quote.deadline_failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [];
        }

        $deadlines = [];

        foreach ((array) $response->json() as $item) {
            if (! is_array($item) || ! isset($item['coProduto'], $item['prazoEntrega'])) {
                continue;
            }

            $deadlines[(string) $item['coProduto']] = (int) $item['prazoEntrega'];
        }

        return $deadlines;
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }
}
