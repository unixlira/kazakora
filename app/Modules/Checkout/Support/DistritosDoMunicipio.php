<?php

namespace App\Modules\Checkout\Support;

use App\Models\IbgeMunicipio;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Distritos de um município para a tela de frete por distrito (pedido
 * 2026-10-25), com o CEP de cada um quando dá para descobrir.
 *
 * Nomes: API de localidades do IBGE (sem o distrito-sede, que é a própria
 * cidade). CEPs: cidade pequena tem um CEP geral (ex.: Afonso Cláudio
 * 29600-000) e os distritos usam os vizinhos (29603-000 Serra Pelada,
 * 29604-000 Pontões...). O CEP geral sai da busca por endereço do ViaCEP e os
 * 9 vizinhos (dígito 5 de 0 a 9, final 000) são consultados juntos; o ViaCEP
 * devolve o distrito em "bairro". Cidade com CEP por rua não tem CEP geral:
 * aí o CEP do distrito é digitado na tela. Tudo em cache por 30 dias; falha
 * do IBGE ou do ViaCEP = lista vazia, nunca erro.
 */
class DistritosDoMunicipio
{
    /**
     * @return array{cep_geral: ?string, distritos: list<array{id: ?int, nome: string, cep: ?string}>}
     */
    public function buscar(IbgeMunicipio $municipio): array
    {
        $chave = "frete-distritos.{$municipio->id}";
        if ($salvo = Cache::get($chave)) {
            return $salvo;
        }

        $ibge = $this->doIbge($municipio);
        [$cepGeral, $ceps] = $this->cepsVizinhos($municipio);

        // Junta pelo nome: distrito do IBGE ganha o CEP; CEP com bairro que
        // não está no IBGE (povoado com CEP próprio) entra como distrito também.
        $distritos = [];
        foreach ($ibge as $d) {
            $distritos[IbgeMunicipio::normalizar($d['nome'])] = ['id' => $d['id'], 'nome' => $d['nome'], 'cep' => null];
        }
        foreach ($ceps as $cep => $bairro) {
            $cep = (string) $cep; // chave de array do PHP vira int
            $nome = IbgeMunicipio::normalizar($bairro);
            if (isset($distritos[$nome])) {
                $distritos[$nome]['cep'] ??= $cep;
            } else {
                $distritos[$nome] = ['id' => null, 'nome' => $bairro, 'cep' => $cep];
            }
        }
        uasort($distritos, fn ($a, $b) => strcmp(IbgeMunicipio::normalizar($a['nome']), IbgeMunicipio::normalizar($b['nome'])));

        $resultado = ['cep_geral' => $cepGeral, 'distritos' => array_values($distritos)];
        // Só guarda o que veio completo: IBGE fora não pode deixar a lista vazia por 30 dias.
        if ($ibge !== [] || $ceps !== []) {
            Cache::put($chave, $resultado, now()->addDays(30));
        }

        return $resultado;
    }

    /** @return list<array{id: int, nome: string}> */
    private function doIbge(IbgeMunicipio $municipio): array
    {
        try {
            $resposta = Http::timeout(8)->get("https://servicodados.ibge.gov.br/api/v1/localidades/municipios/{$municipio->id}/distritos");
        } catch (\Throwable) {
            return [];
        }
        if (! $resposta->successful() || ! is_array($resposta->json())) {
            return [];
        }

        $sede = IbgeMunicipio::normalizar($municipio->nome);

        return collect($resposta->json())
            ->filter(fn ($d) => isset($d['id'], $d['nome']) && IbgeMunicipio::normalizar((string) $d['nome']) !== $sede)
            ->map(fn ($d) => ['id' => (int) $d['id'], 'nome' => (string) $d['nome']])
            ->values()
            ->all();
    }

    /** @return array{0: ?string, 1: array<string, string>} CEP geral e [CEP => distrito] dos vizinhos */
    private function cepsVizinhos(IbgeMunicipio $municipio): array
    {
        $cepGeral = $this->cepGeral($municipio);
        if (! $cepGeral) {
            return [null, []];
        }

        $vizinhos = collect(range(0, 9))
            ->map(fn ($d) => substr($cepGeral, 0, 4).$d.'000')
            ->reject(fn ($cep) => $cep === $cepGeral)
            ->values()
            ->all();

        try {
            $respostas = Http::pool(fn (Pool $pool) => array_map(
                fn ($cep) => $pool->as($cep)->timeout(5)->get("https://viacep.com.br/ws/{$cep}/json/"),
                $vizinhos,
            ));
        } catch (\Throwable) {
            return [$cepGeral, []];
        }

        $ceps = [];
        foreach ($vizinhos as $cep) {
            $r = $respostas[$cep] ?? null;
            if (! $r instanceof Response || ! $r->successful() || ($r->json('erro') ?? false)) {
                continue;
            }
            $dados = $r->json();
            // Mesmo cache do checkout: o CEP já fica conhecido na hora da compra.
            Cache::put("viacep.{$cep}", $dados, now()->addDays(30));

            $bairro = trim((string) ($dados['bairro'] ?? ''));
            if ($bairro !== '' && (int) ($dados['ibge'] ?? 0) === (int) $municipio->id) {
                $ceps[$cep] = $bairro;
            }
        }

        return [$cepGeral, $ceps];
    }

    /** CEP geral da cidade (sem rua), ou null se a cidade tem CEP por rua. */
    private function cepGeral(IbgeMunicipio $municipio): ?string
    {
        try {
            $resposta = Http::timeout(5)->get('https://viacep.com.br/ws/'.$municipio->uf.'/'.rawurlencode($municipio->nome).'/centro/json/');
        } catch (\Throwable) {
            return null;
        }
        $lista = $resposta->successful() ? $resposta->json() : null;
        if (! is_array($lista) || count($lista) !== 1) {
            return null;
        }

        $item = $lista[0];
        $cep = preg_replace('/\D/', '', (string) ($item['cep'] ?? ''));

        return strlen($cep) === 8 && trim((string) ($item['logradouro'] ?? '')) === '' && (int) ($item['ibge'] ?? 0) === (int) $municipio->id
            ? $cep
            : null;
    }
}
