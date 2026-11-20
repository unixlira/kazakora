<?php

namespace App\Modules\Marketplace\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Eventos reais do pacote na transportadora (2026-11-20, pedido #4172): o
 * SEDEX foi para "disponível para retirada" na agência de São Vicente e o
 * nosso rastreio só mostrava as etapas internas, sem o endereço da agência.
 *
 * A API de rastro dos Correios (srorastro) não está no nosso contrato
 * (GTW-012, API 87), então os eventos vêm do Melhor Rastreio, o mesmo
 * serviço da página de rastreio do Melhor Envio. O campo additionalInfo
 * traz o "Retire sua encomenda em <endereço>". Guarda 20 minutos.
 */
class RastreioDosCorreios
{
    private const URL = 'https://melhor-rastreio-api.melhorrastreio.com.br/graphql';

    private const CONSULTA = 'mutation searchParcel ($tracker: TrackerSearchInput!) { result: searchParcel (tracker: $tracker) { trackingEvents { createdAt title description additionalInfo location { city state } } } }';

    /** Dias que os Correios guardam o pacote na agência antes de devolver. */
    public const DIAS_NA_AGENCIA = 7;

    /**
     * Eventos do mais novo para o mais antigo.
     *
     * @return list<array{em: string|null, titulo: string, descricao: string|null, info: string|null, local: string|null}>
     */
    public function eventos(string $codigo, bool $semCache = false): array
    {
        $codigo = strtoupper(trim($codigo));
        if (! preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', $codigo)) {
            return [];
        }
        $chave = "rastreio-correios:{$codigo}";
        if ($semCache) {
            Cache::forget($chave);
        }

        return Cache::remember($chave, now()->addMinutes(20), function () use ($codigo) {
            try {
                $r = Http::timeout(12)->acceptJson()->post(self::URL, [
                    'query' => self::CONSULTA,
                    'variables' => ['tracker' => ['trackingCode' => $codigo, 'type' => 'correios']],
                ]);
                $lista = $r->successful() ? ($r->json('data.result.trackingEvents') ?? []) : [];
            } catch (Throwable $e) {
                Log::info('rastreio_correios_falhou', ['codigo' => $codigo, 'erro' => $e->getMessage()]);
                $lista = [];
            }

            return array_values(array_map(fn (array $e) => [
                'em' => filled($e['createdAt'] ?? null) ? CarbonImmutable::parse($e['createdAt'])->setTimezone(config('app.timezone'))->toIso8601String() : null,
                'titulo' => (string) ($e['title'] ?? ''),
                'descricao' => $e['description'] ?? null,
                'info' => $e['additionalInfo'] ?? null,
                'local' => trim(implode('/', array_filter([$e['location']['city'] ?? null, $e['location']['state'] ?? null])), '/') ?: null,
            ], array_filter($lista, 'is_array')));
        });
    }

    /**
     * Se o último evento é "disponível para retirada": endereço da agência,
     * desde quando e até quando fica lá. Senão, null.
     *
     * @param  list<array<string, mixed>>  $eventos
     * @return array{endereco: string|null, desde: string|null, ate: string|null, mapa: string|null}|null
     */
    public static function retirada(array $eventos): ?array
    {
        $ultimo = $eventos[0] ?? null;
        if (! $ultimo || ! preg_match('/retirada|aguardando retirada|retirar/i', (string) $ultimo['titulo'])
            || preg_match('/transportadora vai|pela transportadora|coleta/i', (string) $ultimo['titulo'].' '.(string) $ultimo['descricao'])) {
            return null;
        }

        $endereco = null;
        foreach ($eventos as $e) {
            if (preg_match('/Retire sua encomenda (?:em|na) (.+?)(?:\.\s*Para retirar|\.?$)/iu', (string) ($e['info'] ?? ''), $m)) {
                $endereco = trim($m[1]);
                break;
            }
        }
        $desde = $ultimo['em'] ? CarbonImmutable::parse($ultimo['em']) : null;

        return [
            'endereco' => $endereco ? self::bonito($endereco) : null,
            'desde' => $desde?->toIso8601String(),
            'ate' => $desde?->addDays(self::DIAS_NA_AGENCIA)->toDateString(),
            'mapa' => $endereco ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode('Correios '.$endereco) : null,
        ];
    }

    /** "RUA FREI GASPAR, 2685 - PARQUE SAO VICENTE" -> "Rua Frei Gaspar, 2685 - Parque Sao Vicente", com CEP formatado. */
    private static function bonito(string $endereco): string
    {
        $texto = mb_convert_case(mb_strtolower($endereco), MB_CASE_TITLE, 'UTF-8');
        $texto = preg_replace_callback('#/([a-z]{2})\b#i', fn ($m) => '/'.strtoupper($m[1]), $texto);
        $texto = preg_replace_callback('/(?<=\s)(De|Da|Do|Das|Dos|E)(?=\s)/u', fn ($m) => mb_strtolower($m[1]), $texto);

        return (string) preg_replace('/Cep (\d{5})-?(\d{3})/i', 'CEP $1-$2', (string) $texto);
    }
}
