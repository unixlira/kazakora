<?php

namespace App\Services\Correios;

use App\Services\Correios\Exceptions\CorreiosException;
use App\Services\Correios\Exceptions\CorreiosNotConfiguredException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Token dos Correios: Basic Auth (numero_usuario/codigo_acesso, gerado em
 * cws.correios.com.br > Gestão de acesso a APIs — NÃO é a senha do Meu
 * Correios) trocado por um Bearer válido por até 24h.
 * @see https://www.correios.com.br/atendimento/developers/manuais/manual-uso-da-api-token
 *
 * Três variantes de token existem (usuário / contrato / cartão de
 * postagem) — usamos a mais específica disponível na config, porque a
 * pré-postagem real provavelmente exige vínculo de contrato; caímos pro
 * token de usuário simples enquanto CORREIOS_CONTRATO/CARTAO_POSTAGEM
 * ainda não foram preenchidos (aguardando a liberação da Correios).
 */
class CorreiosTokenService
{
    private const CACHE_KEY = 'correios.access_token';

    public function isConfigured(): bool
    {
        return filled(config('services.correios.numero_usuario')) && filled(config('services.correios.codigo_acesso'));
    }

    public function token(): string
    {
        if (! $this->isConfigured()) {
            throw new CorreiosNotConfiguredException(
                'Credenciais dos Correios não configuradas — defina CORREIOS_NUMERO_USUARIO e CORREIOS_CODIGO_ACESSO no .env.'
            );
        }

        return Cache::remember(self::CACHE_KEY, now()->addHours(23), fn () => $this->fetchToken());
    }

    private function fetchToken(): string
    {
        [$endpoint, $body] = $this->resolveEndpointAndBody();

        $response = Http::withBasicAuth(
            (string) config('services.correios.numero_usuario'),
            (string) config('services.correios.codigo_acesso'),
        )
            ->acceptJson()
            ->timeout(20)
            ->post(rtrim((string) config('services.correios.token_base_url'), '/').$endpoint, $body);

        if ($response->failed()) {
            Log::channel('correios')->error('correios.token_failed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new CorreiosException(
                $response->json('msgs.0') ?? $response->json('message') ?? "Não foi possível gerar o token dos Correios (HTTP {$response->status()}).",
                $response->status(),
            );
        }

        $token = $response->json('token');

        if (blank($token)) {
            throw new CorreiosException('A API dos Correios respondeu sem um token válido.');
        }

        // Ajusta o TTL do Cache::remember acima pro real prazo devolvido,
        // se vier — evita usar um token já expirado por causa de um clock
        // skew entre o TTL fixo de 23h e o `expiraEm` real.
        if ($expiraEm = $response->json('expiraEm')) {
            $expiresAt = Carbon::parse($expiraEm)->subMinutes(5);

            if ($expiresAt->isFuture()) {
                Cache::put(self::CACHE_KEY, $token, $expiresAt);
            }
        }

        return $token;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function resolveEndpointAndBody(): array
    {
        $cartaoPostagem = config('services.correios.cartao_postagem');
        $contrato = config('services.correios.contrato');
        $dr = config('services.correios.dr');

        if (filled($cartaoPostagem)) {
            return ['/v1/autentica/cartaopostagem', ['numero' => $cartaoPostagem, 'contrato' => $contrato, 'dr' => $dr]];
        }

        if (filled($contrato)) {
            return ['/v1/autentica/contrato', ['numero' => $contrato, 'dr' => $dr]];
        }

        // Token de usuário — sem vínculo de contrato. Funciona pra
        // autenticar, mas pode não bastar pra criar pré-postagem de verdade
        // até CORREIOS_CONTRATO/CARTAO_POSTAGEM serem preenchidos.
        return ['/v1/autentica', []];
    }

    public function forgetCachedToken(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
