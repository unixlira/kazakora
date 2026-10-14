<?php

namespace App\Modules\Checkout\Services;

use App\Modules\Fiscal\Models\Company;
use App\Services\MelhorEnvio\MelhorEnvioAuthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Opções de entrega fora dos Correios no checkout dos outros sites (pedido do
 * Lira em 2026-10-14: "Correios ou Loggi" no Beauty Kora): a caixa inteira
 * (peso tarifável somado, caixa mínima) cotada no Melhor Envio para o CEP,
 * só das transportadoras de frete_embutido.transportadoras_melhor_envio.
 *
 * Nunca lança nem segura o checkout: sem conta conectada, token vencido ou
 * API fora do ar devolve [] (guardado 10 min, para não repetir a espera a
 * cada CEP digitado). Cotação boa fica 6 h por origem, destino e peso.
 */
class TransportadorasMelhorEnvio
{
    public function __construct(private readonly MelhorEnvioAuthService $auth)
    {
    }

    /**
     * @return list<array{id: string, transportadora: string, servico: string, preco: float, prazo: ?int}>
     */
    public function cotar(string $cep, int $pesoG, float $valor): array
    {
        $aceitas = array_map('mb_strtolower', (array) config('frete_embutido.transportadoras_melhor_envio', []));
        $cep = preg_replace('/\D/', '', $cep) ?? '';
        $origem = preg_replace('/\D/', '', (string) Company::query()->value('zip')) ?? '';

        if ($aceitas === [] || strlen($cep) !== 8 || strlen($origem) !== 8 || $pesoG < 1) {
            return [];
        }

        $chave = 'checkout:melhorenvio:'.md5(json_encode([$origem, $cep, $pesoG, MelhorEnvioTabelaPorEstado::ambiente()]));
        $cotacao = Cache::get($chave);
        if ($cotacao === null) {
            $cotacao = $this->consultar($origem, $cep, $pesoG, $valor);
            Cache::put($chave, $cotacao, $cotacao === [] ? now()->addMinutes(10) : now()->addHours(6));
        }

        return array_values(array_filter($cotacao, fn (array $opcao) => in_array(mb_strtolower($opcao['transportadora']), $aceitas, true)));
    }

    private function consultar(string $origem, string $cep, int $pesoG, float $valor): array
    {
        try {
            if (! $this->auth->currentToken()) {
                return [];
            }
            $token = $this->auth->ensureValidToken($this->auth->currentToken());

            [$comprimento, $largura, $altura] = (array) config('frete_embutido.caixa_cm', [16, 11, 5]);
            $resposta = Http::withToken($token->access_token)
                ->withHeaders(['User-Agent' => config('melhorenvio.user_agent')])
                ->acceptJson()->timeout(8)->connectTimeout(4)
                ->post(rtrim((string) config('services.melhorenvio.api_base_url'), '/').'/me/shipment/calculate', [
                    'from' => ['postal_code' => $origem],
                    'to' => ['postal_code' => $cep],
                    'products' => [[
                        'id' => 'caixa',
                        'width' => $largura, 'height' => $altura, 'length' => $comprimento,
                        'weight' => round($pesoG / 1000, 3),
                        'insurance_value' => round($valor, 2),
                        'quantity' => 1,
                    ]],
                ]);
        } catch (Throwable $exception) {
            Log::channel(config('melhorenvio.log_channel'))->warning('melhorenvio.checkout.cotacao_falhou', ['message' => $exception->getMessage()]);

            return [];
        }

        if ($resposta->failed()) {
            Log::channel(config('melhorenvio.log_channel'))->warning('melhorenvio.checkout.cotacao_falhou', ['status' => $resposta->status()]);

            return [];
        }

        $opcoes = [];
        foreach ((array) $resposta->json() as $q) {
            if (! is_array($q) || ! empty($q['error']) || ! isset($q['price'], $q['id'])) {
                continue;
            }
            $opcoes[] = [
                'id' => 'me:'.$q['id'],
                'transportadora' => (string) ($q['company']['name'] ?? 'Transportadora'),
                'servico' => trim((string) ($q['name'] ?? ''), " .\t"),
                'preco' => round((float) $q['price'], 2),
                'prazo' => isset($q['delivery_time']) ? (int) $q['delivery_time'] : null,
            ];
        }
        usort($opcoes, fn ($a, $b) => $a['preco'] <=> $b['preco']);

        return $opcoes;
    }
}
