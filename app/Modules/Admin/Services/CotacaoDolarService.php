<?php

namespace App\Modules\Admin\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dólar comercial (compra) para a Calculadora de importação. Fonte: AwesomeAPI
 * (gratuita, sem chave). Guarda 15 minutos para não bater na API a cada tela
 * aberta. Se a API falhar, devolve null e a tela pede o valor à mão.
 */
class CotacaoDolarService
{
    public const FONTE = 'https://economia.awesomeapi.com.br/json/last/USD-BRL';

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
            Log::warning('Cotação do dólar indisponível: '.$e->getMessage());

            return null;
        }
    }
}
