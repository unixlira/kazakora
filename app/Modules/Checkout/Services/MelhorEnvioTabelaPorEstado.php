<?php

namespace App\Modules\Checkout\Services;

use App\Modules\Fiscal\Models\Company;
use App\Services\MelhorEnvio\MelhorEnvioAuthService;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aba "Melhor Envio" de Logística → Fretes por estado (pedido do Lira em
 * 2026-10-12, para achar frete mais barato que o contrato dos Correios e dar
 * frete grátis): todas as transportadoras que a conta conectada cota
 * (Correios pelo ME, Jadlog, Loggi, J&T...) para as mesmas 27 capitais da
 * aba Correios (CorreiosTabelaPorEstado::CAPITAIS).
 *
 * Mesma conta e token do checkout (MelhorEnvioAuthService). A API cota um
 * destino por chamada (me/shipment/calculate), então as 27 vão juntas pelo
 * Http::pool. Guardado por 6 h por pacote e ambiente (sandbox x produção).
 */
class MelhorEnvioTabelaPorEstado
{
    public function __construct(private readonly MelhorEnvioAuthService $auth)
    {
    }

    public static function ambiente(): string
    {
        return str_contains((string) config('services.melhorenvio.api_base_url'), 'sandbox') ? 'sandbox' : 'producao';
    }

    /**
     * @param  array{peso_g: int, comprimento: float, largura: float, altura: float, valor: float}  $pacote
     * @return array{origem: ?string, ambiente: string, erro: ?string, reconectar: bool, linhas: array<int, array<string, mixed>>}
     */
    public function cotar(array $pacote): array
    {
        $origem = preg_replace('/\D/', '', (string) Company::query()->first()?->zip);
        $base = ['origem' => $origem ?: null, 'ambiente' => self::ambiente(), 'erro' => null, 'reconectar' => false, 'linhas' => []];

        if (! $origem) {
            return ['erro' => 'Cadastre o CEP da empresa em Configurações → Empresa.'] + $base;
        }

        try {
            $token = $this->auth->ensureValidToken($this->auth->currentToken());
        } catch (Throwable $exception) {
            Log::channel(config('melhorenvio.log_channel'))->warning('melhorenvio.tabela_estados.token_failed', ['message' => $exception->getMessage()]);

            return [
                'erro' => $this->auth->currentToken()
                    ? 'O acesso ao Melhor Envio venceu. Conecte a conta de novo.'
                    : 'Nenhuma conta do Melhor Envio está conectada.',
                'reconectar' => true,
            ] + $base;
        }

        $chave = 'melhorenvio.tabela-estados.'.md5(json_encode([$origem, $pacote, self::ambiente()]));
        $resultado = Cache::get($chave);
        if ($resultado === null) {
            $resultado = $this->consultar($token->access_token, $origem, $pacote) + $base;
            if (collect($resultado['linhas'])->contains(fn ($l) => $l['opcoes'] !== [])) {
                Cache::put($chave, $resultado, now()->addHours(6));
            }
        }

        return $resultado;
    }

    private function consultar(string $token, string $origem, array $pacote): array
    {
        $produto = [
            'id' => 'pacote',
            'width' => $pacote['largura'],
            'height' => $pacote['altura'],
            'length' => $pacote['comprimento'],
            'weight' => round($pacote['peso_g'] / 1000, 3),
            'insurance_value' => $pacote['valor'],
            'quantity' => 1,
        ];
        $url = rtrim((string) config('services.melhorenvio.api_base_url'), '/').'/me/shipment/calculate';

        $respostas = Http::pool(function (Pool $pool) use ($token, $origem, $produto, $url) {
            $pedidos = [];
            foreach (CorreiosTabelaPorEstado::CAPITAIS as $uf => [, , , $cep]) {
                $pedidos[$uf] = $pool->as($uf)->withToken($token)
                    ->withHeaders(['User-Agent' => config('melhorenvio.user_agent')])
                    ->acceptJson()->timeout(20)
                    ->post($url, ['from' => ['postal_code' => $origem], 'to' => ['postal_code' => $cep], 'products' => [$produto]]);
            }

            return $pedidos;
        });

        $linhas = [];
        $falhas = 0;
        $naoAutorizado = false;
        foreach (CorreiosTabelaPorEstado::CAPITAIS as $uf => [$estado, $capital, $regiao, $cep]) {
            $resposta = $respostas[$uf] ?? null;
            $opcoes = [];
            if (! $resposta instanceof Response || $resposta->failed()) {
                $falhas++;
                $naoAutorizado = $naoAutorizado || ($resposta instanceof Response && $resposta->status() === 401);
                Log::channel(config('melhorenvio.log_channel'))->warning('melhorenvio.tabela_estados.failed', [
                    'uf' => $uf,
                    'status' => $resposta instanceof Response ? $resposta->status() : null,
                ]);
            } else {
                foreach ((array) $resposta->json() as $q) {
                    if (! is_array($q) || ! empty($q['error']) || ! isset($q['price'])) {
                        continue;
                    }
                    $opcoes[] = [
                        'transportadora' => (string) ($q['company']['name'] ?? 'Transportadora'),
                        'servico' => (string) ($q['name'] ?? ''),
                        'preco' => round((float) $q['price'], 2),
                        'prazo' => isset($q['delivery_time']) ? (int) $q['delivery_time'] : null,
                    ];
                }
                usort($opcoes, fn ($a, $b) => $a['preco'] <=> $b['preco']);
            }
            $linhas[] = ['uf' => $uf, 'estado' => $estado, 'capital' => $capital, 'regiao' => $regiao, 'cep' => $cep, 'opcoes' => $opcoes];
        }

        $erro = null;
        if ($naoAutorizado) {
            $erro = 'O Melhor Envio recusou o acesso. Conecte a conta de novo.';
        } elseif ($falhas === count($linhas)) {
            $erro = 'A API do Melhor Envio não respondeu. Tente de novo em instantes.';
        }

        return ['erro' => $erro, 'reconectar' => $naoAutorizado, 'linhas' => $linhas];
    }
}
