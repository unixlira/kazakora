<?php

namespace App\Services\MelhorEnvio;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Linha do tempo da entrega pelo Melhor Rastreio (pedido do Lira em
 * 2026-11-09: "a mesma linha do tempo que tem no Melhor Envio dentro da
 * KazaKora"). A API do Melhor Envio (me/shipment/tracking) só dá os marcos
 * (paga, gerada, postada, entregue); os eventos da transportadora
 * ("coletado", "transferido para outra unidade", cidade) vêm da API pública
 * do Melhor Rastreio, a mesma que a página melhorrastreio.com.br usa.
 *
 * ACHADO REAL 2026-11-09 (#3978, J&T): o createdAt vem com "Z" mas é a hora
 * de Brasília ("Adicionado no sistema" 11:21:41Z = generated_at 14:21:40
 * UTC do Melhor Envio). Por isso a hora é lida sem o fuso, em São Paulo.
 *
 * Falha da API = lista vazia (a tela continua com as etapas e o link).
 */
class MelhorRastreio
{
    public const URL = 'https://melhor-rastreio-api.melhorrastreio.com.br/graphql';

    private const CACHE_MINUTOS = 20;

    private const QUERY = 'mutation searchParcel($tracker: TrackerSearchInput!) { result: searchParcel(tracker: $tracker) { id lastStatus trackingEvents { createdAt title description location { city state } } } }';

    /**
     * @return list<array{titulo: string, descricao: ?string, quando: ?string, local: ?string}>  do mais novo ao mais antigo
     */
    public function eventos(?string $codigo): array
    {
        $codigo = trim((string) $codigo);
        if ($codigo === '') {
            return [];
        }

        return Cache::remember('melhor-rastreio:'.$codigo, now()->addMinutes(self::CACHE_MINUTOS), function () use ($codigo) {
            try {
                $resposta = Http::acceptJson()->asJson()->timeout(8)->connectTimeout(4)
                    ->withHeaders(['Origin' => 'https://www.melhorrastreio.com.br'])
                    ->post(self::URL, [
                        'query' => self::QUERY,
                        'variables' => ['tracker' => ['trackingCode' => $codigo, 'type' => 'melhorenvio']],
                    ]);
            } catch (Throwable $e) {
                Log::channel(config('melhorenvio.log_channel'))->info('melhorrastreio.falhou', ['codigo' => $codigo, 'message' => $e->getMessage()]);

                return [];
            }

            $eventos = $resposta->successful() ? $resposta->json('data.result.trackingEvents') : null;
            if (! is_array($eventos)) {
                return [];
            }

            return collect($eventos)
                ->filter(fn ($e) => is_array($e) && filled($e['title'] ?? null))
                ->map(fn ($e) => [
                    'titulo' => (string) $e['title'],
                    'descricao' => filled($e['description'] ?? null) ? (string) $e['description'] : null,
                    'quando' => self::hora($e['createdAt'] ?? null),
                    'local' => self::local($e['location'] ?? null),
                ])
                ->sortByDesc('quando')
                ->values()
                ->all();
        });
    }

    private static function hora(mixed $valor): ?string
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(substr(preg_replace('/(\.\d+)?Z$/', '', $valor), 0, 19), 'America/Sao_Paulo')->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private static function local(mixed $local): ?string
    {
        if (! is_array($local)) {
            return null;
        }

        return trim(implode('/', array_filter([$local['city'] ?? null, $local['state'] ?? null])), '/') ?: null;
    }
}
