<?php

namespace App\Modules\Checkout\Support;

use App\Models\IbgeMunicipio;
use App\Modules\Checkout\Models\FreteRegiao;
use Illuminate\Support\Facades\Cache;

/**
 * Regras de frete por lugar (pedido 2026-10-24, tabelas frete_regioes e
 * frete_regiao_locais). Ordem de busca: distrito ou faixa de CEP → município
 * → UF; no mesmo nível, a de maior prioridade. Sem regra, fica a cotação
 * normal dos Correios.
 *
 * Distrito (2026-10-25): vale pelo CEP (ou faixa) cadastrado ou, sem CEP que
 * case, quando o ViaCEP devolve o nome do distrito em "bairro" para um CEP do
 * mesmo município (cidades pequenas: CEP geral para a sede e um CEP por
 * distrito).
 *
 * O município sai do CEP pelo ViaCEP (código IBGE, cache de 30 dias em
 * CompletarEnderecoPeloCep). Só consulta o ViaCEP se existir alguma regra
 * por município ou distrito. Qualquer falha (tabela ausente, ViaCEP fora) = sem regra:
 * o checkout nunca para por causa disso.
 */
class RegrasDeFrete
{
    private const CACHE = 'frete-regioes:ativas';

    /** Limpa o cache depois de salvar ou remover uma regra. */
    public static function esquecer(): void
    {
        Cache::forget(self::CACHE);
    }

    /**
     * Regras ativas que valem para o destino, da mais específica para a menos.
     *
     * @return list<array{id: int, nome: string, tipo: string, valor: float, prazo_dias: ?int, peso_min_g: ?int, peso_max_g: ?int}>
     */
    public function paraDestino(string $site, ?string $cep, ?string $uf): array
    {
        $regras = array_values(array_filter($this->ativas(), fn ($r) => $r['site'] === $site));
        if ($regras === []) {
            return [];
        }

        $municipio = null;
        $bairro = null;
        if ($cep && strlen($cep) === 8 && collect($regras)->contains(fn ($r) => $r['municipios'] !== [] || $r['distritos'] !== [] || ($r['ufs'] !== [] && ! $uf))) {
            [$municipio, $ufDoCep, $bairro] = $this->municipioDoCep($cep);
            $uf = $uf ?: $ufDoCep;
        }

        $candidatas = [];
        foreach ($regras as $r) {
            $nivel = match (true) {
                $cep !== null && collect($r['ceps'])->contains(fn ($f) => $cep >= $f[0] && $cep <= $f[1]) => 3,
                self::noDistrito($r['distritos'], $cep, $municipio, $bairro) => 3,
                $municipio !== null && in_array($municipio, $r['municipios'], true) => 2,
                $uf !== null && in_array($uf, $r['ufs'], true) => 1,
                default => 0,
            };
            if ($nivel > 0) {
                $candidatas[] = [$nivel, $r];
            }
        }

        usort($candidatas, fn ($a, $b) => [$b[0], $b[1]['prioridade']] <=> [$a[0], $a[1]['prioridade']]);

        return array_map(fn ($c) => array_intersect_key($c[1], array_flip(['id', 'nome', 'tipo', 'valor', 'prazo_dias', 'peso_min_g', 'peso_max_g'])), $candidatas);
    }

    /**
     * O destino está num dos distritos: CEP dentro da faixa do distrito ou,
     * no mesmo município, o bairro do ViaCEP com o nome do distrito.
     *
     * @param  list<array{municipio: int, nome: string, cep_inicio: ?string, cep_fim: ?string}>  $distritos
     */
    private static function noDistrito(array $distritos, ?string $cep, ?int $municipio, ?string $bairro): bool
    {
        foreach ($distritos as $d) {
            if ($cep !== null && $d['cep_inicio'] && $cep >= $d['cep_inicio'] && $cep <= ($d['cep_fim'] ?: $d['cep_inicio'])) {
                return true;
            }
            if ($municipio === $d['municipio'] && $bairro !== null && $bairro === $d['nome']) {
                return true;
            }
        }

        return false;
    }

    /** Primeira regra da lista que aceita o peso (g). */
    public static function paraPeso(array $regras, int $peso): ?array
    {
        foreach ($regras as $r) {
            if (($r['peso_min_g'] === null || $peso >= $r['peso_min_g']) && ($r['peso_max_g'] === null || $peso <= $r['peso_max_g'])) {
                return $r;
            }
        }

        return null;
    }

    /** Frete depois da regra, a partir da cotação dos Correios. */
    public static function aplicar(array $regra, float $correios): float
    {
        $valor = (float) $regra['valor'];

        return round(max(0, match ($regra['tipo']) {
            FreteRegiao::FIXO => $valor,
            FreteRegiao::ACRESCIMO => $correios + $valor,
            FreteRegiao::DESCONTO => $correios - $valor,
            FreteRegiao::TETO => min($correios, $valor),
            default => $correios,
        }), 2);
    }

    /** @return list<array<string, mixed>> */
    private function ativas(): array
    {
        return rescue(fn () => Cache::rememberForever(self::CACHE, fn () => FreteRegiao::query()
            ->where('ativo', true)
            ->with('locais')
            ->get()
            ->map(fn (FreteRegiao $r) => [
                'id' => $r->id,
                'site' => $r->site,
                'nome' => $r->nome,
                'prioridade' => $r->prioridade,
                'tipo' => $r->tipo_preco,
                'valor' => $r->valor,
                'prazo_dias' => $r->prazo_dias,
                'peso_min_g' => $r->peso_min_g,
                'peso_max_g' => $r->peso_max_g,
                'ufs' => $r->locais->pluck('uf')->filter()->values()->all(),
                'municipios' => $r->locais->whereNull('distrito')->pluck('municipio_id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
                'distritos' => $r->locais->whereNotNull('distrito')->filter(fn ($l) => $l->municipio_id)->map(fn ($l) => [
                    'municipio' => (int) $l->municipio_id,
                    'nome' => IbgeMunicipio::normalizar($l->distrito),
                    'cep_inicio' => $l->cep_inicio,
                    'cep_fim' => $l->cep_fim,
                ])->values()->all(),
                'ceps' => $r->locais->whereNull('distrito')->filter(fn ($l) => $l->cep_inicio && $l->cep_fim)->map(fn ($l) => [$l->cep_inicio, $l->cep_fim])->values()->all(),
            ])
            ->all()), [], false);
    }

    /** @return array{0: ?int, 1: ?string, 2: ?string} código IBGE do município, UF e bairro (normalizado) do CEP */
    private function municipioDoCep(string $cep): array
    {
        $dados = rescue(fn () => app(CompletarEnderecoPeloCep::class)->buscar($cep), null, false);
        if (! $dados) {
            return [null, null, null];
        }

        $uf = strtoupper(trim((string) ($dados['uf'] ?? ''))) ?: null;
        $bairro = IbgeMunicipio::normalizar((string) ($dados['bairro'] ?? '')) ?: null;
        $ibge = preg_replace('/\D/', '', (string) ($dados['ibge'] ?? ''));
        if (strlen($ibge) === 7) {
            return [(int) $ibge, $uf, $bairro];
        }

        // ViaCEP sem o código: casa o nome da cidade na tabela do IBGE.
        $cidade = trim((string) ($dados['localidade'] ?? ''));
        $id = $uf && $cidade !== ''
            ? IbgeMunicipio::query()->where('uf', $uf)->where('nome_normalizado', IbgeMunicipio::normalizar($cidade))->value('id')
            : null;

        return [$id ? (int) $id : null, $uf, $bairro];
    }
}
