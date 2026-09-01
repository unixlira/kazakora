<?php

namespace App\Services\NFe;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Resolve nome de cidade + UF para o código de município do IBGE (7 dígitos),
 * exigido em várias tags da NF-e (cMunFG, enderEmit/cMun, enderDest/cMun).
 * Não temos uma tabela própria de municípios — usa a API pública e gratuita
 * do IBGE (sem autenticação), com cache de 30 dias por UF pra não bater na
 * API toda hora.
 */
class IbgeMunicipioResolver
{
    public function resolve(string $cidade, string $uf): string
    {
        $municipios = $this->municipiosDoEstado($uf);

        $normalizado = $this->normalize($cidade);
        $encontrado = collect($municipios)->first(fn (array $m) => $this->normalize($m['nome']) === $normalizado);

        if (! $encontrado) {
            throw new RuntimeException("Não foi possível encontrar o código IBGE do município \"{$cidade}/{$uf}\".");
        }

        return (string) $encontrado['id'];
    }

    /**
     * BUG REAL 2026-09-01 (pedido #1158, Mercado Livre — emissão travada em
     * 'Não foi possível encontrar o código IBGE do município "Canaã/AL"'):
     * o comprador digita o BAIRRO no campo de cidade e o canal repassa do
     * jeito que veio ("Canaã" é bairro de Arapiraca/AL, CEP 57318-750) —
     * não existe município nenhum com esse nome, e a nota nunca saía. O
     * CEP, esse sim, é sempre confiável: o ViaCEP devolve o município real
     * e o próprio código do IBGE. Devolve null (não estoura) quando o CEP
     * não resolve ou é de outra UF — quem chama mantém o erro original,
     * que é mais informativo que "CEP inválido".
     *
     * @return array{code: string, city: string}|null
     */
    public function resolveByCep(string $cep, string $uf): ?array
    {
        $digits = preg_replace('/\D/', '', $cep);

        if (strlen($digits) !== 8) {
            return null;
        }

        return Cache::remember("ibge.cep.{$digits}", now()->addDays(30), function () use ($digits, $uf) {
            try {
                $response = Http::timeout(10)->get("https://viacep.com.br/ws/{$digits}/json/");
            } catch (\Throwable) {
                return null;
            }

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            $code = preg_replace('/\D/', '', (string) ($data['ibge'] ?? ''));
            $city = trim((string) ($data['localidade'] ?? ''));

            // UF diferente = CEP de outro estado; melhor não "consertar"
            // o endereço por conta própria nesse caso.
            if (strlen($code) !== 7 || $city === '' || strcasecmp((string) ($data['uf'] ?? ''), $uf) !== 0) {
                return null;
            }

            return ['code' => $code, 'city' => $city];
        });
    }

    /**
     * @return array<int, array{id: int, nome: string}>
     */
    private function municipiosDoEstado(string $uf): array
    {
        // Guarda um array puro (não um objeto Collection) — cache drivers
        // baseados em serialização (ex: Redis) podem devolver
        // __PHP_Incomplete_Class ao reler um objeto complexo já dentro do
        // mesmo processo; array plano evita esse problema por completo.
        return Cache::remember("ibge.municipios.{$uf}", now()->addDays(30), function () use ($uf) {
            $response = Http::timeout(10)->get("https://servicodados.ibge.gov.br/api/v1/localidades/estados/{$uf}/municipios");

            if (! $response->successful()) {
                throw new RuntimeException("Falha ao consultar a API do IBGE para o estado {$uf}.");
            }

            return collect($response->json())->map(fn ($m) => ['id' => $m['id'], 'nome' => $m['nome']])->all();
        });
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->trim()->toString();
    }
}
