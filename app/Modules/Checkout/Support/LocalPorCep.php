<?php

namespace App\Modules\Checkout\Support;

use App\Models\IbgeMunicipio;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Município (código IBGE) e UF de um CEP, para o frete por cidade/região.
 *
 * O ViaCEP devolve o código IBGE do município junto com o endereço (mesmo
 * código de ibge_municipios); a UF sai da tabela pelo código, que é a fonte
 * confiável. Sem resposta do ViaCEP, tenta a BrasilAPI (cidade + UF, sem
 * código) e casa o nome em ibge_municipios. Guarda 30 dias por CEP; falha
 * não fica presa no cache.
 */
class LocalPorCep
{
    /** @var array<string, ?array> nesta requisição */
    private array $locais = [];

    /**
     * @return array{municipio_id: int, uf: string, cidade: string}|null
     */
    public function consultar(?string $cep): ?array
    {
        $cep = preg_replace('/\D/', '', (string) $cep) ?? '';
        if (strlen($cep) !== 8) {
            return null;
        }

        if (array_key_exists($cep, $this->locais)) {
            return $this->locais[$cep];
        }

        $chave = "frete-regioes:local:{$cep}";
        $local = Cache::get($chave);
        if ($local === null) {
            $local = $this->peloViaCep($cep) ?? $this->pelaBrasilApi($cep);
            if ($local) {
                Cache::put($chave, $local, now()->addDays(30));
            }
        }

        return $this->locais[$cep] = $local ?: null;
    }

    private function peloViaCep(string $cep): ?array
    {
        try {
            $resposta = Http::timeout(5)->get("https://viacep.com.br/ws/{$cep}/json/");
        } catch (Throwable) {
            return null;
        }

        $codigo = (int) preg_replace('/\D/', '', (string) $resposta->json('ibge', ''));
        if (! $resposta->successful() || strlen((string) $codigo) !== 7) {
            return null;
        }

        $municipio = IbgeMunicipio::query()->find($codigo, ['id', 'nome', 'uf']);
        if ($municipio) {
            return ['municipio_id' => (int) $municipio->id, 'uf' => $municipio->uf, 'cidade' => $municipio->nome];
        }

        $uf = strtoupper((string) $resposta->json('uf', ''));

        return strlen($uf) === 2
            ? ['municipio_id' => $codigo, 'uf' => $uf, 'cidade' => (string) $resposta->json('localidade', '')]
            : null;
    }

    private function pelaBrasilApi(string $cep): ?array
    {
        try {
            $resposta = Http::timeout(5)->get("https://brasilapi.com.br/api/cep/v1/{$cep}");
        } catch (Throwable) {
            return null;
        }

        $uf = strtoupper((string) $resposta->json('state', ''));
        $cidade = (string) $resposta->json('city', '');
        if (! $resposta->successful() || strlen($uf) !== 2 || $cidade === '') {
            return null;
        }

        $municipio = IbgeMunicipio::query()
            ->where('uf', $uf)->where('nome_normalizado', IbgeMunicipio::normalizar($cidade))
            ->first(['id', 'nome', 'uf']);

        return $municipio ? ['municipio_id' => (int) $municipio->id, 'uf' => $municipio->uf, 'cidade' => $municipio->nome] : null;
    }
}
