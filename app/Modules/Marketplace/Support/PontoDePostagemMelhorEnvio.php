<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Fiscal\Models\Company;
use App\Modules\Marketplace\Models\MelhorEnvioEnvio;
use App\Services\MelhorEnvio\MelhorEnvioClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Onde levar o pacote da etiqueta do Melhor Envio (pedido do Lira em
 * 2026-11-09: "levo em um só lugar; se tiver outros pontos, tem que dizer
 * qual unidade"). A agência que o Melhor Envio grava na etiqueta de app é
 * um marcador genérico ("Melhor Ponto - Integrações"), então o lugar sai
 * da lista de agências da cidade da loja:
 *
 *   1. o ponto padrão (config melhorenvio.ponto_padrao), se aceita a
 *      transportadora da etiqueta;
 *   2. senão, a agência mais próxima da loja que aceita.
 *
 * Fica gravado no envio (postagem) e aparece no card do KoraSync.
 */
class PontoDePostagemMelhorEnvio
{
    public function __construct(private readonly MelhorEnvioClient $client) {}

    /** @return array{transportadora: ?string, nome: string, endereco: string, padrao: bool, distancia_km: ?float}|null */
    public function para(MelhorEnvioEnvio $envio): ?array
    {
        try {
            $servico = collect($this->servicos())->firstWhere('id', (int) $envio->servico_id);
            $companhia = $servico['company']['id'] ?? null;
            if (! $companhia) {
                return null;
            }

            $agencias = $this->agencias();
            $aceita = fn (array $a) => collect($a['companies'] ?? [])->contains(fn ($c) => (int) ($c['id'] ?? 0) === (int) $companhia);

            $padrao = collect($agencias)->firstWhere('id', (int) config('melhorenvio.ponto_padrao'));
            $escolhida = $padrao && $aceita($padrao)
                ? $padrao
                : collect($agencias)->filter($aceita)->sortBy(fn ($a) => $this->distancia($a))->first();

            if (! $escolhida) {
                return null;
            }

            $end = $escolhida['address'] ?? [];

            return [
                'transportadora' => $servico['company']['name'] ?? null,
                'nome' => trim(preg_replace('/\s*-\s*Ponto Parceiro$/i', '', (string) $escolhida['name'])),
                'endereco' => trim(implode(', ', array_filter([
                    trim(($end['address'] ?? '').' '.($end['number'] ?? '')),
                    $end['district'] ?? null,
                ]))),
                'padrao' => $padrao !== null && $escolhida['id'] === $padrao['id'],
                'distancia_km' => ($d = $this->distancia($escolhida)) < 999 ? round($d, 1) : null,
            ];
        } catch (Throwable $e) {
            Log::channel(config('melhorenvio.log_channel'))->warning('melhorenvio.ponto_postagem_falhou', ['envio' => $envio->id, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /** Grava no envio se ainda não tem (não trava etiqueta nem acompanhamento). */
    public function gravar(MelhorEnvioEnvio $envio): void
    {
        if ($envio->postagem || ! $envio->servico_id) {
            return;
        }
        if ($ponto = $this->para($envio)) {
            $envio->forceFill(['postagem' => $ponto])->save();
        }
    }

    private function servicos(): array
    {
        return Cache::remember('melhorenvio:servicos', now()->addDay(), fn () => (array) $this->client->get('me/shipment/services'));
    }

    /** Agências da cidade da loja (a lista do Melhor Envio não traz o status). */
    private function agencias(): array
    {
        $empresa = Company::query()->first();
        $uf = strtoupper((string) ($empresa?->state ?: 'SP'));
        $cidade = (string) ($empresa?->city ?: 'São Paulo');

        return Cache::remember("melhorenvio:agencias:{$uf}:{$cidade}", now()->addDay(), function () use ($uf, $cidade) {
            $query = http_build_query(['state' => $uf, 'city' => $cidade]);

            return array_values(array_filter((array) $this->client->get("me/shipment/agencies?{$query}"), 'is_array'));
        });
    }

    private function distancia(array $agencia): float
    {
        $lat = $agencia['address']['latitude'] ?? null;
        $lon = $agencia['address']['longitude'] ?? null;
        if (! $lat || ! $lon) {
            return 999;
        }
        [$lat0, $lon0] = [(float) config('melhorenvio.loja_lat'), (float) config('melhorenvio.loja_lon')];
        $d = sin(deg2rad($lat - $lat0) / 2) ** 2 + cos(deg2rad($lat0)) * cos(deg2rad($lat)) * sin(deg2rad($lon - $lon0) / 2) ** 2;

        return 6371 * 2 * asin(sqrt($d));
    }
}
