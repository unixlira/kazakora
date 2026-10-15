<?php

namespace App\Services\NFe;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Resolve nome de cidade + UF para o código de município do IBGE (7 dígitos),
 * exigido em várias tags da NF-e (cMunFG, enderEmit/cMun, enderDest/cMun).
 * Fonte principal: a lista oficial do IBGE gravada no próprio repositório
 * (resources/data/ibge-municipios.json, 5.571 municípios, baixada em
 * 15/10/2026). A API pública do IBGE (e a BrasilAPI) só entram se a UF não
 * estiver no arquivo, com cache de 30 dias por UF.
 */
class IbgeMunicipioResolver
{
    /** @var array<string, array<int, array{0: int, 1: string}>>|null */
    private ?array $locais = null;

    /** Só os testes trocam o arquivo (para exercitar as fontes de rede). */
    public function __construct(private ?string $arquivoLocal = null) {}

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
        //
        // BUG REAL 2026-10-14 17:42 (pedidos #3137, #3140, #3141, #3142 sem
        // nota): o servidor do IBGE passou a mandar o certificado sem a
        // cadeia intermediária (Sectigo DV R36), o cURL recusa (erro 60) e,
        // como o emitente é de SP, TODA emissão parou quando o cache de 30
        // dias venceu. Agora: IBGE → BrasilAPI (mesma lista do IBGE, outro
        // servidor) → última lista boa guardada sem prazo. Só estoura se as
        // três falharem.
        //
        // BUG REAL 2026-10-15 15:30 (pedidos #3164 e #3165 sem nota por 2h):
        // o cache venceu de novo, o IBGE seguia com o certificado quebrado,
        // a BrasilAPI dava timeout (504) e a "última lista boa" nunca tinha
        // sido gravada, porque desde a correção nenhuma fonte respondeu.
        // Município quase nunca muda (o último criado foi em 2013): a lista
        // local resolve sem rede; a internet fica só para UF fora dela.
        $local = $this->municipiosLocais($uf);

        if ($local !== null) {
            return $local;
        }

        return Cache::remember("ibge.municipios.{$uf}", now()->addDays(30), function () use ($uf) {
            $fontes = [
                "https://servicodados.ibge.gov.br/api/v1/localidades/estados/{$uf}/municipios",
                "https://brasilapi.com.br/api/ibge/municipios/v1/{$uf}",
            ];

            foreach ($fontes as $url) {
                $municipios = $this->buscarMunicipios($url);

                if ($municipios !== null) {
                    Cache::forever("ibge.municipios.{$uf}.ultimo", $municipios);

                    return $municipios;
                }
            }

            $ultimo = Cache::get("ibge.municipios.{$uf}.ultimo");

            if (is_array($ultimo) && $ultimo !== []) {
                return $ultimo;
            }

            throw new RuntimeException("Falha ao consultar a API do IBGE para o estado {$uf}.");
        });
    }

    /**
     * @return array<int, array{id: int, nome: string}>|null
     */
    private function municipiosLocais(string $uf): ?array
    {
        if ($this->locais === null) {
            $conteudo = @file_get_contents($this->arquivoLocal ?? resource_path('data/ibge-municipios.json'));
            $this->locais = is_string($conteudo) ? (json_decode($conteudo, true) ?: []) : [];
        }

        $lista = $this->locais[strtoupper($uf)] ?? null;

        if (! is_array($lista) || $lista === []) {
            return null;
        }

        return array_map(fn (array $m) => ['id' => (int) $m[0], 'nome' => (string) $m[1]], $lista);
    }

    /**
     * IBGE devolve {id, nome}; BrasilAPI devolve {codigo_ibge, nome}.
     *
     * @return array<int, array{id: int, nome: string}>|null
     */
    private function buscarMunicipios(string $url): ?array
    {
        try {
            $response = Http::timeout(10)->get($url);
        } catch (\Throwable $e) {
            Log::warning('ibge.municipios.fonte_falhou', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() || ! is_array($response->json())) {
            Log::warning('ibge.municipios.fonte_falhou', ['url' => $url, 'status' => $response->status()]);

            return null;
        }

        $municipios = collect($response->json())
            ->map(fn ($m) => ['id' => (int) ($m['id'] ?? $m['codigo_ibge'] ?? 0), 'nome' => (string) ($m['nome'] ?? '')])
            ->filter(fn ($m) => strlen((string) $m['id']) === 7 && $m['nome'] !== '')
            ->values()
            ->all();

        return $municipios === [] ? null : $municipios;
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->trim()->toString();
    }
}
