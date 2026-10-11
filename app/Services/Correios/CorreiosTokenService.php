<?php

namespace App\Services\Correios;

use App\Services\Correios\Exceptions\CorreiosException;
use App\Services\Correios\Exceptions\CorreiosNotConfiguredException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Credencial dos Correios pra chamar as APIs restritas (Preço/Prazo,
 * Pré-postagem). Dois formatos coexistem, descobertos testando contra a
 * API real de produção em 2026-08-19 (não documentação secundária):
 *
 * 1) **Chave de acesso escopada** (painel novo do CWS "Gestão de acesso a
 *    API", gerada por vínculo — contrato ou cartão de postagem, cada uma
 *    autorizada só pras APIs marcadas naquele vínculo). Essa chave **já É
 *    o Bearer final** — usada direto no header `Authorization` das APIs de
 *    negócio (Preço/Prazo/Pré-postagem), **sem** passar pelo endpoint
 *    `/token/v1/autentica*` (confirmado: `Http::withBasicAuth` com essa
 *    chave contra `/token/v1/autentica[/contrato|/cartaopostagem]` sempre
 *    devolve 401 puro; `Http::withToken` direto na API de negócio funciona
 *    — 200 real em `/preco/v1/nacional`). `CORREIOS_CODIGO_ACESSO_CONTRATO`
 *    e `CORREIOS_CODIGO_ACESSO_CARTAO_POSTAGEM` guardam essas chaves.
 *
 * 2) **Código de acesso genérico** (o "código de acesso" clássico do Meu
 *    Correios, `CORREIOS_CODIGO_ACESSO`) — esse sim passa pela troca
 *    Basic Auth -> Bearer em `/token/v1/autentica`, só que gera um token
 *    de usuário sem vínculo, que as APIs restritas rejeitam (GTW-012).
 *    Mantido como fallback de config antiga / dev-local / testes, onde as
 *    chaves escopadas não estão preenchidas.
 *
 * @see https://www.correios.com.br/atendimento/developers/manuais/manual-uso-da-api-token
 */
class CorreiosTokenService
{
    private const CACHE_KEY_GENERICO = 'correios.access_token.generico';

    public function isConfigured(): bool
    {
        if (blank(config('services.correios.numero_usuario'))) {
            return false;
        }

        return filled(config('services.correios.codigo_acesso'))
            || filled(config('services.correios.codigo_acesso_contrato'))
            || filled(config('services.correios.codigo_acesso_cartao_postagem'));
    }

    /**
     * Token pra cotar frete real (Preço/Prazo) — chave escopada ao contrato.
     */
    public function tokenForPrecoPrazo(): string
    {
        $chave = config('services.correios.codigo_acesso_contrato');

        return filled($chave) ? $chave : $this->tokenGenerico();
    }

    /**
     * Token pra criar pré-postagem (QR Code) — chave escopada ao cartão de
     * postagem.
     */
    public function tokenForPrePostagem(): string
    {
        $chave = config('services.correios.codigo_acesso_cartao_postagem');

        return filled($chave) ? $chave : $this->tokenGenerico();
    }

    /**
     * Fallback sem vínculo (troca Basic Auth -> Bearer de verdade em
     * `/v1/autentica`) — usado só quando nenhuma chave escopada está
     * preenchida (dev local, testes). Não passa pelas APIs restritas de
     * verdade, ver docblock da classe.
     */
    private function tokenGenerico(): string
    {
        $codigoAcesso = config('services.correios.codigo_acesso');

        if (blank(config('services.correios.numero_usuario')) || blank($codigoAcesso)) {
            throw new CorreiosNotConfiguredException(
                'Credenciais dos Correios não configuradas — defina CORREIOS_NUMERO_USUARIO e CORREIOS_CODIGO_ACESSO no .env.'
            );
        }

        return Cache::remember(self::CACHE_KEY_GENERICO, now()->addHours(23), fn () => $this->fetchToken($codigoAcesso));
    }

    private function fetchToken(string $codigoAcesso): string
    {
        $response = Http::withBasicAuth((string) config('services.correios.numero_usuario'), $codigoAcesso)
            ->acceptJson()
            ->timeout(20)
            ->post(rtrim((string) config('services.correios.token_base_url'), '/').'/v1/autentica', []);

        if ($response->failed()) {
            Log::channel('correios')->error('correios.token_failed', [
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
                Cache::put(self::CACHE_KEY_GENERICO, $token, $expiresAt);
            }
        }

        return $token;
    }

    public function forgetCachedToken(): void
    {
        Cache::forget(self::CACHE_KEY_GENERICO);
    }
}
