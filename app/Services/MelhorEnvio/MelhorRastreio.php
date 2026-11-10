<?php

namespace App\Services\MelhorEnvio;

use App\Modules\Marketplace\Models\MelhorEnvioEnvio;
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
     * Eventos da etiqueta do Melhor Envio. ACHADO REAL 2026-11-10: o tipo
     * tem que bater com o código. O do próprio Melhor Envio (ME262FTSE99BR)
     * vai com "melhorenvio" e serve para qualquer transportadora; o da
     * transportadora vai com o tipo dela (888031040957845 + "jet"). O
     * 888031040957845 com "melhorenvio" dá "Bad Request" e a tela ficava
     * sem os eventos.
     *
     * @return list<array{titulo: string, descricao: ?string, quando: ?string, local: ?string}>  do mais novo ao mais antigo
     */
    public function eventosDoEnvio(MelhorEnvioEnvio $envio): array
    {
        $tentativas = array_filter([
            $envio->rastreio_me ? [$envio->rastreio_me, 'melhorenvio'] : null,
            $envio->rastreio && ($tipo = self::tipoDaTransportadora($envio->servico)) ? [$envio->rastreio, $tipo] : null,
        ]);

        foreach ($tentativas as [$codigo, $tipo]) {
            if ($eventos = $this->eventos($codigo, $tipo)) {
                return $eventos;
            }
        }

        return [];
    }

    /** Tipo do Melhor Rastreio pelo nome do serviço da etiqueta (só "jet" conferido ao vivo). */
    public static function tipoDaTransportadora(?string $servico): ?string
    {
        $s = mb_strtolower((string) $servico);

        return match (true) {
            str_contains($s, 'jet') || str_contains($s, 'j&t') => 'jet',
            str_contains($s, 'jadlog') => 'jadlog',
            str_contains($s, 'loggi') => 'loggi',
            str_contains($s, 'correios') => 'correios',
            default => null,
        };
    }

    /**
     * @return list<array{titulo: string, descricao: ?string, quando: ?string, local: ?string}>  do mais novo ao mais antigo
     */
    public function eventos(?string $codigo, string $tipo = 'melhorenvio'): array
    {
        $codigo = trim((string) $codigo);
        if ($codigo === '') {
            return [];
        }

        $chave = "melhor-rastreio:{$tipo}:{$codigo}";
        if (is_array($salvo = Cache::get($chave))) {
            return $salvo;
        }

        $eventos = $this->buscar($codigo, $tipo);
        // Vazio fica pouco tempo: o pacote pode ganhar o primeiro evento logo.
        Cache::put($chave, $eventos, now()->addMinutes($eventos === [] ? 2 : self::CACHE_MINUTOS));

        return $eventos;
    }

    private function buscar(string $codigo, string $tipo): array
    {
        try {
            $resposta = Http::acceptJson()->asJson()->timeout(8)->connectTimeout(4)
                ->withHeaders(['Origin' => 'https://www.melhorrastreio.com.br'])
                ->post(self::URL, [
                    'query' => self::QUERY,
                    'variables' => ['tracker' => ['trackingCode' => $codigo, 'type' => $tipo]],
                ]);
        } catch (Throwable $e) {
            Log::channel(config('melhorenvio.log_channel'))->info('melhorrastreio.falhou', ['codigo' => $codigo, 'message' => $e->getMessage()]);

            return [];
        }

        $eventos = $resposta->successful() ? $resposta->json('data.result.trackingEvents') : null;
        if (! is_array($eventos)) {
            Log::channel(config('melhorenvio.log_channel'))->info('melhorrastreio.sem_eventos', ['codigo' => $codigo, 'tipo' => $tipo, 'erro' => $resposta->json('errors.0.message')]);

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
