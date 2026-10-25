<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Checkout\Models\FreteRegiao;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Frete por cidade/região (pedido do Lira em 2026-10-24): regras em
 * frete_regioes/frete_regiao_locais que mexem na cotação dos Correios do
 * FreteEmbutido para um CEP de destino.
 *
 * Busca, da mais específica para a mais geral: faixa de CEP → município do
 * IBGE → UF. No mesmo nível ganha a maior `prioridade` (e, entre faixas de
 * CEP, a mais estreita). Só a regra vencedora vale, e só para o peso e o
 * serviço dela. Sem regra o frete fica igual ao dos Correios: a tabela
 * nasce vazia e nada muda até o Lira cadastrar.
 *
 * O município só é consultado (LocalPorCep, ViaCEP com cache de 30 dias)
 * quando existe regra ativa por município ou UF.
 */
class FreteRegioes
{
    private const CACHE = 'frete-regioes:regras';

    private const NIVEIS = ['cep' => 3, 'municipio' => 2, 'uf' => 1];

    /** @var list<array<string, mixed>>|null */
    private ?array $regras = null;

    /** @var array<string, list<array<string, mixed>>> regras que casam com o CEP (antes do filtro de peso) */
    private array $porCep = [];

    public function __construct(private readonly LocalPorCep $locais)
    {
    }

    /** Limpa o cache depois de criar, mudar ou apagar regra ou local. */
    public static function esquecer(): void
    {
        Cache::forget(self::CACHE);
        if (app()->resolved(self::class)) {
            app(self::class)->regras = null;
            app(self::class)->porCep = [];
        }
    }

    /**
     * Regra vencedora para o CEP, o peso tarifável e o serviço (pac/sedex:
     * casa regra desse serviço ou sem serviço; null: só regra sem serviço).
     *
     * @return array{id: int, nome: string, tipo_preco: string, valor: float, percentual: bool, prazo_dias: ?int, servico: ?string, nivel: string, local: string}|null
     */
    public function regra(?string $cep, int $pesoG, ?string $servico = null): ?array
    {
        foreach ($this->candidatas($cep) as $regra) {
            if ($regra['peso_min_g'] !== null && $pesoG < $regra['peso_min_g']) {
                continue;
            }
            if ($regra['peso_max_g'] !== null && $pesoG > $regra['peso_max_g']) {
                continue;
            }
            if ($regra['servico'] !== null && $regra['servico'] !== $servico) {
                continue;
            }

            return array_intersect_key($regra, array_flip(['id', 'nome', 'tipo_preco', 'valor', 'percentual', 'prazo_dias', 'servico', 'nivel', 'local']));
        }

        return null;
    }

    /**
     * Linha da tabela do FreteEmbutido (pac, sedex, prazo_pac, prazo_sedex)
     * com a regra do destino aplicada a cada serviço. `regras` diz qual regra
     * entrou em cada um (para depuração e para o comando frete:regiao).
     *
     * @param  array{pac: float, sedex: float, prazo_pac: ?int, prazo_sedex: ?int}  $linha
     * @return array{pac: float, sedex: float, prazo_pac: ?int, prazo_sedex: ?int, regras?: array<string, int>}
     */
    public function aplicar(array $linha, ?string $cep, int $pesoG): array
    {
        if (! config('frete_embutido.regioes_ativo', true) || $this->candidatas($cep) === []) {
            return $linha;
        }

        $usadas = [];
        foreach (['pac', 'sedex'] as $servico) {
            $regra = $this->regra($cep, $pesoG, $servico);
            if (! $regra) {
                continue;
            }
            $linha[$servico] = self::preco((float) $linha[$servico], $regra);
            if ($regra['prazo_dias'] !== null) {
                $linha["prazo_{$servico}"] = $regra['prazo_dias'];
            }
            $usadas[$servico] = $regra['id'];
        }

        return $usadas ? [...$linha, 'regras' => $usadas] : $linha;
    }

    /** Preço do serviço com a regra (nunca negativo). */
    public static function preco(float $correios, array $regra): float
    {
        $valor = (float) $regra['valor'];
        $delta = $regra['percentual'] ? $correios * $valor / 100 : $valor;

        $preco = match ($regra['tipo_preco']) {
            FreteRegiao::FIXO => $valor,
            FreteRegiao::ACRESCIMO => $correios + $delta,
            FreteRegiao::DESCONTO => $correios - $delta,
            FreteRegiao::TETO => min($correios, $valor),
            default => $correios,
        };

        return round(max(0.0, $preco), 2);
    }

    /**
     * Regras ativas que casam com o CEP, já na ordem de preferência.
     *
     * @return list<array<string, mixed>>
     */
    private function candidatas(?string $cep): array
    {
        $cep = preg_replace('/\D/', '', (string) $cep) ?? '';
        if (strlen($cep) !== 8) {
            return [];
        }

        if (array_key_exists($cep, $this->porCep)) {
            return $this->porCep[$cep];
        }

        $regras = $this->regras();
        if ($regras === []) {
            return $this->porCep[$cep] = [];
        }

        $precisaLocal = collect($regras)->contains(fn ($r) => $r['nivel'] !== 'cep');
        $local = $precisaLocal ? $this->locais->consultar($cep) : null;

        $casam = array_values(array_filter($regras, fn (array $r) => match ($r['nivel']) {
            'cep' => $cep >= $r['cep_inicio'] && $cep <= $r['cep_fim'],
            'municipio' => $local && $local['municipio_id'] === $r['municipio_id'],
            'uf' => $local && $local['uf'] === $r['uf'],
        }));

        usort($casam, fn (array $a, array $b) => [self::NIVEIS[$b['nivel']], $b['prioridade'], $a['largura'], $a['id']]
            <=> [self::NIVEIS[$a['nivel']], $a['prioridade'], $b['largura'], $b['id']]);

        return $this->porCep[$cep] = $casam;
    }

    /**
     * Uma entrada por (regra ativa x local), em cache por 10 min.
     *
     * @return list<array<string, mixed>>
     */
    private function regras(): array
    {
        if ($this->regras !== null) {
            return $this->regras;
        }

        try {
            return $this->regras = Cache::remember(self::CACHE, now()->addMinutes(10), fn () => $this->carregar());
        } catch (Throwable $e) {
            // Tabela ainda não migrada (deploy no meio): frete normal.
            if (Schema::hasTable('frete_regioes')) {
                report($e);
            }

            return $this->regras = [];
        }
    }

    private function carregar(): array
    {
        $entradas = [];
        foreach (FreteRegiao::query()->where('ativo', true)->with('locais')->get() as $regra) {
            $base = [
                'id' => (int) $regra->id,
                'nome' => (string) $regra->nome,
                'tipo_preco' => (string) $regra->tipo_preco,
                'valor' => (float) $regra->valor,
                'percentual' => (bool) $regra->percentual,
                'prazo_dias' => $regra->prazo_dias,
                'servico' => $regra->servico ?: null,
                'peso_min_g' => $regra->peso_min_g,
                'peso_max_g' => $regra->peso_max_g,
                'prioridade' => (int) $regra->prioridade,
            ];

            foreach ($regra->locais as $local) {
                if ($local->cep_inicio) {
                    $fim = $local->cep_fim ?: $local->cep_inicio;
                    $entradas[] = [...$base, 'nivel' => 'cep', 'cep_inicio' => $local->cep_inicio, 'cep_fim' => $fim,
                        'largura' => (int) $fim - (int) $local->cep_inicio, 'local' => "CEP {$local->cep_inicio}-{$fim}"];
                } elseif ($local->municipio_id) {
                    $entradas[] = [...$base, 'nivel' => 'municipio', 'municipio_id' => (int) $local->municipio_id,
                        'largura' => 0, 'local' => "município {$local->municipio_id}"];
                } elseif ($local->uf) {
                    $entradas[] = [...$base, 'nivel' => 'uf', 'uf' => strtoupper($local->uf),
                        'largura' => 0, 'local' => "UF {$local->uf}"];
                }
            }
        }

        return $entradas;
    }
}
