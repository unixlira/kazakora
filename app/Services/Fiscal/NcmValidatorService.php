<?php

namespace App\Services\Fiscal;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BUG REAL 2026-08-10 (nota do pedido #215 rejeitada pela SEFAZ — "778:
 * Informado NCM inexistente" — e só descoberta DEPOIS que o cliente já
 * tinha pago, travando o envio pra Shopee): a validação de NCM no
 * cadastro do produto (ProductFiscalController) só checava "é string,
 * até 8 caracteres" — nunca se o código existe de verdade. Um typo
 * (84248999 em vez do real 84248990) passou direto e só quebrou dias
 * depois, numa venda real.
 *
 * Consulta a tabela oficial de NCM da Receita Federal/MDIC (Portal
 * Único Siscomex, mesma fonte que a SEFAZ usa pra validar — API
 * pública, sem autenticação, ~15 mil códigos, atualizada oficialmente).
 * Cacheada 30 dias (mesmo padrão já usado em IbgeMunicipioResolver pra
 * fonte externa que muda pouco) — cache como array plano de códigos,
 * NUNCA uma Collection (gotcha real já documentado: Collection cacheada
 * e relida do driver `database`/Redis quebra com
 * __PHP_Incomplete_Class na 2ª leitura).
 */
class NcmValidatorService
{
    private const CACHE_KEY = 'fiscal.ncm.valid_codes';

    private const CACHE_TTL_DAYS = 30;

    private const SOURCE_URL = 'https://portalunico.siscomex.gov.br/classif/api/publico/nomenclatura/download/json?perfil=PUBLICO';

    /**
     * true se o NCM existe de verdade na tabela oficial. Se a fonte
     * externa não puder ser consultada (governo fora do ar, sem cache
     * ainda) e não sobrar nada em cache, NÃO bloqueia o cadastro por
     * causa disso — best-effort, mesma postura já usada nesse projeto
     * pra qualquer fonte externa instável (ex: IbgeMunicipioResolver).
     * Só o formato (8 dígitos) é exigido incondicionalmente.
     */
    public function isValid(string $ncm): bool
    {
        $normalized = preg_replace('/\D/', '', $ncm);

        if (strlen($normalized) !== 8) {
            return false;
        }

        $validCodes = $this->validCodes();

        if ($validCodes === null) {
            return true;
        }

        return in_array($normalized, $validCodes, true);
    }

    /**
     * @return array<int, string>|null null quando a fonte oficial não pôde
     *                                  ser consultada (não fica em cache
     *                                  nesse caso — tenta de novo na
     *                                  próxima chamada em vez de travar
     *                                  "sem validar" por 30 dias por causa
     *                                  de uma falha passageira).
     */
    private function validCodes(): ?array
    {
        return Cache::remember(self::CACHE_KEY, now()->addDays(self::CACHE_TTL_DAYS), function () {
            try {
                $response = Http::timeout(15)->get(self::SOURCE_URL);

                if ($response->failed()) {
                    Log::warning('fiscal.ncm.fetch_failed', ['status' => $response->status()]);

                    return null;
                }

                $codes = collect($response->json('Nomenclaturas', []))
                    ->pluck('Codigo')
                    ->map(fn ($code) => preg_replace('/\D/', '', (string) $code))
                    ->filter(fn ($code) => strlen($code) === 8)
                    ->values()
                    ->all();

                return $codes ?: null;
            } catch (Throwable $exception) {
                Log::warning('fiscal.ncm.fetch_failed', ['message' => $exception->getMessage()]);

                return null;
            }
        });
    }
}
