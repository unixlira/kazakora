<?php

namespace App\Services\MelhorEnvio;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Várias cotações do Melhor Envio de uma vez (me/shipment/calculate, um
 * pacote por chamada, em paralelo pelo Http::pool). Usada quando o Melhor
 * Envio é o provedor do frete (App\Support\FreteProvedor): frete embutido
 * no preço, tabela por localidade e etiqueta.
 *
 * Cada pacote volta com as opções de todas as transportadoras ativas na
 * conta, da mais barata para a mais cara. Sem token válido lança (quem chama
 * decide se para ou segue sem cotação).
 */
class CotacaoMelhorEnvio
{
    /**
     * Chaves da última cotar() que o Melhor Envio recusou por CEP inválido
     * (422 em postal_code). Não adianta tentar de novo: ACHADO REAL
     * 2026-11-09, CEPs que os Correios aceitam (ex.: 06730-001) e o Melhor
     * Envio não; a tabela por localidade parava neles.
     *
     * @var list<string>
     */
    public array $cepRecusado = [];

    /** Pedidos ao mesmo tempo, para não estourar o limite da API. */
    private const SIMULTANEOS = 8;

    public function __construct(private readonly MelhorEnvioAuthService $auth) {}

    /**
     * @param  array<string, array{origem: string, cep: string, peso_g: int, comprimento: float, largura: float, altura: float, valor?: float}>  $pacotes  chave => pacote
     * @return array<string, list<array{servico_id: int, transportadora: string, servico: string, nome: string, preco: float, prazo: ?int}>|null>  chave => opções (null = sem resposta da API)
     */
    public function cotar(array $pacotes): array
    {
        if ($pacotes === []) {
            return [];
        }

        $token = $this->auth->ensureValidToken($this->auth->currentToken())->access_token;
        $url = rtrim((string) config('services.melhorenvio.api_base_url'), '/').'/me/shipment/calculate';

        $resultado = [];
        $this->cepRecusado = [];
        foreach (array_chunk($pacotes, self::SIMULTANEOS, true) as $grupo) {
            try {
                $respostas = Http::pool(function (Pool $pool) use ($grupo, $token, $url) {
                    $pedidos = [];
                    foreach ($grupo as $chave => $p) {
                        $pedidos[] = $pool->as((string) $chave)->withToken($token)
                            ->withHeaders(['User-Agent' => config('melhorenvio.user_agent')])
                            ->acceptJson()->timeout(20)->connectTimeout(5)
                            ->post($url, self::corpo($p));
                    }

                    return $pedidos;
                });
            } catch (Throwable $e) {
                Log::channel(config('melhorenvio.log_channel'))->warning('melhorenvio.cotacao_lote_falhou', ['message' => $e->getMessage()]);
                foreach (array_keys($grupo) as $chave) {
                    $resultado[$chave] = null;
                }

                continue;
            }

            foreach (array_keys($grupo) as $chave) {
                $resposta = $respostas[(string) $chave] ?? null;
                if ($resposta instanceof Response && $resposta->status() === 422 && $resposta->json('errors.postal_code')) {
                    $this->cepRecusado[] = (string) $chave;
                }
                if (! $resposta instanceof Response || $resposta->failed()) {
                    Log::channel(config('melhorenvio.log_channel'))->warning('melhorenvio.cotacao_falhou', [
                        'chave' => $chave,
                        'status' => $resposta instanceof Response ? $resposta->status() : null,
                    ]);
                    $resultado[$chave] = null;

                    continue;
                }
                $resultado[$chave] = self::opcoes((array) $resposta->json());
            }
        }

        return $resultado;
    }

    /** Uma cotação só (null = sem resposta). */
    public function cotarUm(array $pacote): ?array
    {
        return $this->cotar(['um' => $pacote])['um'] ?? null;
    }

    /** A mais barata (desempate: a mais rápida). */
    public static function maisBarata(array $opcoes): ?array
    {
        usort($opcoes, fn ($a, $b) => [$a['preco'], $a['prazo'] ?? 999] <=> [$b['preco'], $b['prazo'] ?? 999]);

        return $opcoes[0] ?? null;
    }

    /** A que chega antes (desempate: a mais barata). */
    public static function maisRapida(array $opcoes): ?array
    {
        usort($opcoes, fn ($a, $b) => [$a['prazo'] ?? 999, $a['preco']] <=> [$b['prazo'] ?? 999, $b['preco']]);

        return $opcoes[0] ?? null;
    }

    private static function corpo(array $p): array
    {
        return [
            'from' => ['postal_code' => $p['origem']],
            'to' => ['postal_code' => $p['cep']],
            'package' => [
                'height' => (float) $p['altura'],
                'width' => (float) $p['largura'],
                'length' => (float) $p['comprimento'],
                'weight' => round(max(1, (int) $p['peso_g']) / 1000, 3),
            ],
            'options' => ['insurance_value' => round((float) ($p['valor'] ?? 0), 2), 'receipt' => false, 'own_hand' => false],
            'services' => (string) config('melhorenvio.servicos'),
        ];
    }

    /** @return list<array{servico_id: int, transportadora: string, servico: string, nome: string, preco: float, prazo: ?int}> */
    private static function opcoes(array $resposta): array
    {
        $opcoes = [];
        // Um serviço só vem como objeto, não como lista.
        if (isset($resposta['id'])) {
            $resposta = [$resposta];
        }
        foreach ($resposta as $q) {
            if (! is_array($q) || ! empty($q['error']) || ! isset($q['id']) || ! isset($q['price']) && ! isset($q['custom_price'])) {
                continue;
            }
            $transportadora = trim((string) ($q['company']['name'] ?? 'Transportadora'));
            $servico = trim((string) ($q['name'] ?? ''), " .\t");
            $opcoes[] = [
                'servico_id' => (int) $q['id'],
                'transportadora' => $transportadora,
                'servico' => $servico,
                'nome' => trim("{$transportadora} {$servico}"),
                'preco' => round((float) ($q['price'] ?? $q['custom_price']), 2),
                'prazo' => isset($q['delivery_time']) ? (int) $q['delivery_time'] : (isset($q['custom_delivery_time']) ? (int) $q['custom_delivery_time'] : null),
            ];
        }
        usort($opcoes, fn ($a, $b) => $a['preco'] <=> $b['preco']);

        return $opcoes;
    }
}
