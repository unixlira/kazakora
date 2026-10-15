<?php

namespace App\Services\Ibge;

use App\Models\IbgeEstado;
use App\Models\IbgeMunicipio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mantém as tabelas ibge_estados / ibge_municipios (fonte da NF-e para o
 * código do município, ver IbgeMunicipioResolver).
 *
 * Regra de ouro: NUNCA apaga nada. Se a API falhar, a tabela fica como
 * está; município que some da API continua na tabela (nota antiga pode
 * precisar dele).
 */
class IbgeLocalidadesSync
{
    public const ARQUIVO = 'data/ibge-localidades.json';

    private const IBGE = 'https://servicodados.ibge.gov.br/api/v1/localidades';

    private const BRASILAPI = 'https://brasilapi.com.br/api/ibge';

    /**
     * Carrega o arquivo versionado no repositório (upsert).
     *
     * @return array{estados: int, municipios: int}
     */
    public function carregarArquivo(): array
    {
        $dados = json_decode((string) file_get_contents(database_path(self::ARQUIVO)), true, flags: JSON_THROW_ON_ERROR);

        $estados = array_map(fn ($e) => ['id' => $e[0], 'sigla' => $e[1], 'nome' => $e[2]], $dados['estados']);
        $municipios = array_map(fn ($m) => ['id' => $m[0], 'nome' => $m[1], 'estado_id' => $m[2]], $dados['municipios']);

        return $this->gravar($estados, $municipios, atualizarNomes: true);
    }

    /**
     * Atualiza pela API: IBGE primeiro, BrasilAPI se o IBGE falhar.
     *
     * @return array{fonte: string, estados: int, municipios: int}
     */
    public function sincronizarDaApi(): array
    {
        $estados = $this->estadosIbge() ?? $this->estadosBrasilApi();

        if ($estados === null) {
            throw new RuntimeException('IBGE e BrasilAPI fora do ar (lista de estados). Tabela mantida como estava.');
        }

        $municipios = $this->municipiosIbge();
        if ($municipios !== null) {
            return ['fonte' => 'IBGE', ...$this->gravar($estados, $municipios, atualizarNomes: true)];
        }

        // BrasilAPI devolve o nome em MAIÚSCULAS: só acrescenta município
        // que não existe, sem estragar o nome dos que já estão certos.
        $municipios = $this->municipiosBrasilApi($estados);
        if ($municipios === []) {
            throw new RuntimeException('IBGE e BrasilAPI fora do ar (municípios). Tabela mantida como estava.');
        }

        return ['fonte' => 'BrasilAPI', ...$this->gravar($estados, $municipios, atualizarNomes: false)];
    }

    /**
     * @param  array<int, array{id: int, sigla: string, nome: string}>  $estados
     * @param  array<int, array{id: int, nome: string, estado_id: int}>  $municipios
     * @return array{estados: int, municipios: int}
     */
    private function gravar(array $estados, array $municipios, bool $atualizarNomes): array
    {
        $agora = now();
        $siglas = collect($estados)->pluck('sigla', 'id')->all();

        // Validação: código de 7 dígitos que começa com o código da UF.
        $municipios = array_values(array_filter($municipios, fn ($m) => isset($siglas[$m['estado_id']])
            && preg_match('/^\d{7}$/', (string) $m['id'])
            && str_starts_with((string) $m['id'], (string) $m['estado_id'])
            && trim($m['nome']) !== ''));

        DB::transaction(function () use ($estados, $municipios, $siglas, $agora, $atualizarNomes) {
            IbgeEstado::query()->upsert(
                array_map(fn ($e) => [...$e, 'created_at' => $agora, 'updated_at' => $agora], $estados),
                ['id'],
                ['sigla', 'nome', 'updated_at'],
            );

            $linhas = array_map(fn ($m) => [
                'id' => (int) $m['id'],
                'nome' => trim($m['nome']),
                'nome_normalizado' => IbgeMunicipio::normalizar($m['nome']),
                'estado_id' => (int) $m['estado_id'],
                'uf' => $siglas[$m['estado_id']],
                'created_at' => $agora,
                'updated_at' => $agora,
            ], $municipios);

            foreach (array_chunk($linhas, 500) as $lote) {
                if ($atualizarNomes) {
                    IbgeMunicipio::query()->upsert($lote, ['id'], ['nome', 'nome_normalizado', 'estado_id', 'uf', 'updated_at']);
                } else {
                    IbgeMunicipio::query()->insertOrIgnore($lote);
                }
            }
        });

        return ['estados' => IbgeEstado::query()->count(), 'municipios' => IbgeMunicipio::query()->count()];
    }

    /** @return array<int, array{id: int, sigla: string, nome: string}>|null */
    private function estadosIbge(): ?array
    {
        return $this->estados(self::IBGE.'/estados');
    }

    /** @return array<int, array{id: int, sigla: string, nome: string}>|null */
    private function estadosBrasilApi(): ?array
    {
        return $this->estados(self::BRASILAPI.'/uf/v1');
    }

    /** @return array<int, array{id: int, sigla: string, nome: string}>|null */
    private function estados(string $url): ?array
    {
        $json = $this->get($url);
        if ($json === null) {
            return null;
        }

        $estados = collect($json)
            ->map(fn ($e) => ['id' => (int) ($e['id'] ?? 0), 'sigla' => strtoupper((string) ($e['sigla'] ?? '')), 'nome' => (string) ($e['nome'] ?? '')])
            ->filter(fn ($e) => $e['id'] >= 11 && $e['id'] <= 53 && strlen($e['sigla']) === 2 && $e['nome'] !== '')
            ->values()
            ->all();

        return count($estados) === 27 ? $estados : null;
    }

    /** @return array<int, array{id: int, nome: string, estado_id: int}>|null */
    private function municipiosIbge(): ?array
    {
        $json = $this->get(self::IBGE.'/municipios?view=nivelado', 60);
        if ($json === null) {
            return null;
        }

        $municipios = collect($json)
            ->map(fn ($m) => ['id' => (int) ($m['municipio-id'] ?? 0), 'nome' => (string) ($m['municipio-nome'] ?? ''), 'estado_id' => (int) ($m['UF-id'] ?? 0)])
            ->all();

        // Lista incompleta = resposta estranha; melhor não usar.
        return count($municipios) >= 5500 ? $municipios : null;
    }

    /**
     * @param  array<int, array{id: int, sigla: string, nome: string}>  $estados
     * @return array<int, array{id: int, nome: string, estado_id: int}>
     */
    private function municipiosBrasilApi(array $estados): array
    {
        $municipios = [];

        foreach ($estados as $estado) {
            foreach ($this->get(self::BRASILAPI."/municipios/v1/{$estado['sigla']}") ?? [] as $m) {
                $municipios[] = [
                    'id' => (int) ($m['codigo_ibge'] ?? 0),
                    'nome' => Str::title(mb_strtolower((string) ($m['nome'] ?? ''))),
                    'estado_id' => $estado['id'],
                ];
            }
        }

        return $municipios;
    }

    private function get(string $url, int $timeout = 20): ?array
    {
        try {
            $response = Http::timeout($timeout)->get($url);
        } catch (\Throwable $e) {
            Log::warning('ibge.sincronizar.fonte_falhou', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() || ! is_array($response->json())) {
            Log::warning('ibge.sincronizar.fonte_falhou', ['url' => $url, 'status' => $response->status()]);

            return null;
        }

        return $response->json();
    }
}
