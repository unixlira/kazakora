<?php

namespace App\Modules\Checkout\Support;

use App\Models\IbgeMunicipio;
use App\Modules\Checkout\Models\FreteRegiao;
use Illuminate\Support\Facades\Cache;

/**
 * Regras de frete por lugar (pedido 2026-10-24, tabelas frete_regioes e
 * frete_regiao_locais). Ordem de busca: faixa de CEP → município → UF; no
 * mesmo nível, a de maior prioridade. Sem regra, fica a cotação normal dos
 * Correios.
 *
 * O município sai do CEP pelo ViaCEP (código IBGE, cache de 30 dias em
 * CompletarEnderecoPeloCep). Só consulta o ViaCEP se existir alguma regra
 * por município. Qualquer falha (tabela ausente, ViaCEP fora) = sem regra:
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
        if ($cep && strlen($cep) === 8 && collect($regras)->contains(fn ($r) => $r['municipios'] !== [] || ($r['ufs'] !== [] && ! $uf))) {
            [$municipio, $ufDoCep] = $this->municipioDoCep($cep);
            $uf = $uf ?: $ufDoCep;
        }

        $candidatas = [];
        foreach ($regras as $r) {
            $nivel = match (true) {
                $cep !== null && collect($r['ceps'])->contains(fn ($f) => $cep >= $f[0] && $cep <= $f[1]) => 3,
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
                'municipios' => $r->locais->pluck('municipio_id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
                'ceps' => $r->locais->filter(fn ($l) => $l->cep_inicio && $l->cep_fim)->map(fn ($l) => [$l->cep_inicio, $l->cep_fim])->values()->all(),
            ])
            ->all()), [], false);
    }

    /** @return array{0: ?int, 1: ?string} código IBGE do município e UF do CEP */
    private function municipioDoCep(string $cep): array
    {
        $dados = rescue(fn () => app(CompletarEnderecoPeloCep::class)->buscar($cep), null, false);
        if (! $dados) {
            return [null, null];
        }

        $uf = strtoupper(trim((string) ($dados['uf'] ?? ''))) ?: null;
        $ibge = preg_replace('/\D/', '', (string) ($dados['ibge'] ?? ''));
        if (strlen($ibge) === 7) {
            return [(int) $ibge, $uf];
        }

        // ViaCEP sem o código: casa o nome da cidade na tabela do IBGE.
        $cidade = trim((string) ($dados['localidade'] ?? ''));
        $id = $uf && $cidade !== ''
            ? IbgeMunicipio::query()->where('uf', $uf)->where('nome_normalizado', IbgeMunicipio::normalizar($cidade))->value('id')
            : null;

        return [$id ? (int) $id : null, $uf];
    }
}
