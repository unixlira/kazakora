<?php

namespace App\Modules\Admin\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dólar comercial (compra) para a Calculadora de importação. Fonte: AwesomeAPI
 * (gratuita, sem chave) e, se ela falhar, a exchangerate-api. Guarda 15
 * minutos para não bater na API a cada tela aberta. Se as duas falharem,
 * devolve null e a tela pede o valor à mão.
 */
class CotacaoDolarService
{
    public const FONTE = 'https://economia.awesomeapi.com.br/json/last/USD-BRL';

    public const FONTE_RESERVA = 'https://open.er-api.com/v6/latest/USD';

    private const CACHE = 'admin:cotacao-usd-brl';

    /** @return array{valor: float, atualizado_em: string, fonte: string}|null */
    public function atual(bool $renovar = false): ?array
    {
        if ($renovar) {
            Cache::forget(self::CACHE);
        }

        $cotacao = Cache::get(self::CACHE);
        if ($cotacao !== null) {
            return $cotacao;
        }

        $cotacao = $this->buscar();
        if ($cotacao !== null) {
            Cache::put(self::CACHE, $cotacao, now()->addMinutes(15));
        }

        return $cotacao;
    }

    private function buscar(): ?array
    {
        return $this->awesomeApi() ?? $this->exchangeRateApi();
    }

    /** Cotação ao longo do dia. Responde 429 para IP de servidor sem chave. */
    private function awesomeApi(): ?array
    {
        try {
            $resposta = Http::timeout(8)->acceptJson()->get(self::FONTE);
            $dados = $resposta->json('USDBRL');

            if (! $resposta->successful() || ! is_array($dados) || ! is_numeric($dados['bid'] ?? null) || (float) $dados['bid'] <= 0) {
                Log::warning('Cotação do dólar: resposta inesperada da AwesomeAPI.', ['status' => $resposta->status()]);

                return null;
            }

            // create_date já vem no horário de Brasília.
            $quando = isset($dados['create_date'])
                ? Carbon::parse($dados['create_date'], 'America/Sao_Paulo')
                : now();

            return [
                'valor' => round((float) $dados['bid'], 4),
                'atualizado_em' => $quando->toIso8601String(),
                'fonte' => 'AwesomeAPI',
            ];
        } catch (Throwable $e) {
            Log::warning('Cotação do dólar (AwesomeAPI) indisponível: '.$e->getMessage());

            return null;
        }
    }

    /** Reserva: uma cotação por dia, a mesma fonte da calculadora original. */
    private function exchangeRateApi(): ?array
    {
        try {
            $resposta = Http::timeout(8)->acceptJson()->get(self::FONTE_RESERVA);
            $valor = $resposta->json('rates.BRL');

            if (! $resposta->successful() || ! is_numeric($valor) || (float) $valor <= 0) {
                Log::warning('Cotação do dólar: resposta inesperada da exchangerate-api.', ['status' => $resposta->status()]);

                return null;
            }

            $unix = $resposta->json('time_last_update_unix');

            return [
                'valor' => round((float) $valor, 4),
                'atualizado_em' => (is_numeric($unix) ? Carbon::createFromTimestamp((int) $unix) : now())
                    ->setTimezone('America/Sao_Paulo')->toIso8601String(),
                'fonte' => 'exchangerate-api',
            ];
        } catch (Throwable $e) {
            Log::warning('Cotação do dólar (exchangerate-api) indisponível: '.$e->getMessage());

            return null;
        }
    }
}
