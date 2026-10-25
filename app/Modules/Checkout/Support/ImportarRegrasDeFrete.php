<?php

namespace App\Modules\Checkout\Support;

use App\Models\IbgeMunicipio;
use App\Modules\Checkout\Models\FreteRegiao;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as RespostaHttp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Regras de frete em massa por planilha (pedido do Lira em 2026-10-25), na
 * tela Fretes por estado. Cada linha é uma cidade ou um distrito com o valor
 * e o prazo do frete: Afonso Cláudio (ES) com uma regra e Serra Pelada,
 * Pontões, Piracema com outras.
 *
 * Colunas (a ordem não importa, o nome sim, sem acento ou com):
 *   uf, cidade (ou ibge), distrito, cep (ou cep_inicio e cep_fim),
 *   tipo (gratis, fixo, acrescimo, desconto, teto), valor, prazo, ativo.
 * Linha sem distrito = regra da cidade (o CEP só confere a cidade). Linha com
 * distrito = regra do distrito, pelo CEP ou faixa de CEP dele e pelo nome
 * que o ViaCEP devolve em "bairro" (ver RegrasDeFrete).
 *
 * Duas etapas: analisar() só lê e diz o que vai acontecer em cada linha
 * (criar, atualizar, igual ou erro); aplicar() grava as linhas sem erro. A
 * planilha nunca apaga regra: o que não está nela fica como está.
 */
class ImportarRegrasDeFrete
{
    public const MAX_LINHAS = 3000;

    /** ViaCEP consultado por importação (o resto fica sem conferir). */
    private const MAX_CEPS_CONSULTADOS = 600;

    /** Nome da coluna (normalizado) => campo. */
    private const COLUNAS = [
        'uf' => 'uf', 'estado' => 'uf', 'sigla' => 'uf',
        'cidade' => 'cidade', 'municipio' => 'cidade', 'nome da cidade' => 'cidade',
        'ibge' => 'ibge', 'codigo ibge' => 'ibge', 'cod ibge' => 'ibge', 'codigo do municipio' => 'ibge',
        'distrito' => 'distrito', 'localidade' => 'distrito', 'povoado' => 'distrito',
        'cep' => 'cep', 'ceps' => 'cep', 'faixa de cep' => 'cep',
        'cep inicio' => 'cep_inicio', 'cep inicial' => 'cep_inicio', 'cep de' => 'cep_inicio',
        'cep fim' => 'cep_fim', 'cep final' => 'cep_fim', 'cep ate' => 'cep_fim',
        'tipo' => 'tipo', 'tipo preco' => 'tipo', 'regra' => 'tipo',
        'valor' => 'valor', 'frete' => 'valor', 'preco' => 'valor', 'valor frete' => 'valor', 'valor do frete' => 'valor',
        'prazo' => 'prazo', 'prazo dias' => 'prazo', 'dias' => 'prazo', 'prazo dias uteis' => 'prazo',
        'ativo' => 'ativo', 'ativa' => 'ativo',
    ];

    public function __construct(private CompletarEnderecoPeloCep $viaCep) {}

    /**
     * O que cada linha da planilha faria, sem gravar nada.
     *
     * @param  list<list<string>>  $linhas  primeira linha = cabeçalho
     * @return array{itens: list<array<string, mixed>>, resumo: array<string, int>, erro: ?string}
     */
    public function analisar(array $linhas, string $site): array
    {
        $vazio = ['itens' => [], 'resumo' => self::resumo([])];
        if (count($linhas) < 2) {
            return [...$vazio, 'erro' => 'A planilha precisa do cabeçalho e de pelo menos uma linha.'];
        }
        if (count($linhas) - 1 > self::MAX_LINHAS) {
            return [...$vazio, 'erro' => 'A planilha pode ter até '.self::MAX_LINHAS.' linhas por vez.'];
        }

        $mapa = self::mapearColunas(array_shift($linhas));
        if (! isset($mapa['valor']) && ! isset($mapa['tipo'])) {
            return [...$vazio, 'erro' => 'Faltou a coluna "valor" (ou "tipo" com "gratis").'];
        }
        if (! isset($mapa['cidade']) && ! isset($mapa['ibge']) && ! isset($mapa['cep']) && ! isset($mapa['cep_inicio'])) {
            return [...$vazio, 'erro' => 'Faltou dizer o lugar: coluna "cidade" (com "uf"), "ibge" ou "cep".'];
        }

        $itens = [];
        foreach ($linhas as $i => $valores) {
            $campo = fn (string $c) => isset($mapa[$c]) ? trim((string) ($valores[$mapa[$c]] ?? '')) : '';
            $itens[] = $this->lerLinha($i + 2, $campo);
        }

        $this->consultarCeps($itens);
        $this->resolverLugares($itens);
        $this->marcarRepetidas($itens);
        $this->compararComExistentes($itens, $site);

        return ['itens' => array_map(self::paraTela(...), $itens), 'resumo' => self::resumo($itens), 'erro' => null];
    }

    /**
     * Grava as linhas sem erro (cria ou troca a regra da cidade/distrito).
     *
     * @return array{itens: list<array<string, mixed>>, resumo: array<string, int>, erro: ?string, gravadas: int}
     */
    public function aplicar(array $linhas, string $site): array
    {
        $analise = $this->analisar($linhas, $site);
        if ($analise['erro']) {
            return [...$analise, 'gravadas' => 0];
        }

        $paraGravar = array_filter($analise['itens'], fn ($i) => in_array($i['acao'], ['criar', 'atualizar'], true));
        DB::transaction(function () use ($paraGravar, $site) {
            foreach ($paraGravar as $item) {
                $this->gravar($item, $site);
            }
        });
        RegrasDeFrete::esquecer();

        return [...$analise, 'gravadas' => count($paraGravar)];
    }

    /** Planilha de exemplo para baixar na tela (CSV com ; para abrir direto no Excel). */
    public static function modeloCsv(): string
    {
        $linhas = [
            ['uf', 'cidade', 'distrito', 'cep_inicio', 'cep_fim', 'tipo', 'valor', 'prazo'],
            ['ES', 'Afonso Cláudio', '', '29600-000', '', 'fixo', '19,90', '6'],
            ['ES', 'Afonso Cláudio', 'Serra Pelada', '29603-000', '', 'fixo', '24,90', '8'],
            ['ES', 'Afonso Cláudio', 'Pontões', '29604-000', '', 'acrescimo', '5,00', ''],
            ['SP', 'Mauá', '', '', '', 'gratis', '', ''],
        ];

        return "\xEF\xBB\xBF".implode("\r\n", array_map(fn ($l) => implode(';', $l), $linhas))."\r\n";
    }

    /** @param  callable(string): string  $campo */
    private function lerLinha(int $numero, callable $campo): array
    {
        $item = [
            'linha' => $numero, 'erros' => [], 'avisos' => [],
            'uf' => strtoupper($campo('uf')), 'cidade' => $campo('cidade'), 'ibge' => preg_replace('/\D/', '', $campo('ibge')),
            'distrito' => preg_replace('/\s+/', ' ', $campo('distrito')),
            'municipio_id' => null, 'cep_inicio' => null, 'cep_fim' => null,
            'tipo_preco' => null, 'valor' => null, 'prazo_dias' => null, 'ativo' => true,
            'acao' => null, 'antes' => null, 'regra_id' => null,
        ];

        // UF por extenso ("Espírito Santo") vira sigla pelo nome do estado.
        if (strlen($item['uf']) > 2) {
            $item['uf'] = (string) DB::table('ibge_estados')->whereRaw('lower(nome) = ?', [mb_strtolower($campo('uf'))])->value('sigla');
        }

        // CEP: uma coluna com um CEP ou a faixa ("29600-000 a 29609-999"), ou as duas colunas.
        $ceps = array_merge(self::ceps($campo('cep')), self::ceps($campo('cep_inicio')), self::ceps($campo('cep_fim')));
        if ($campo('cep').$campo('cep_inicio').$campo('cep_fim') !== '' && $ceps === []) {
            $item['erros'][] = 'CEP inválido (tem que ter 8 números).';
        } elseif ($ceps !== []) {
            $item['cep_inicio'] = $ceps[0];
            $item['cep_fim'] = $ceps[1] ?? $ceps[0];
            if ($item['cep_fim'] < $item['cep_inicio']) {
                $item['erros'][] = 'O CEP final é menor que o inicial.';
            }
        }

        $tipo = self::tipo($campo('tipo'));
        $valorTexto = $campo('valor');
        $valor = self::dinheiro($valorTexto);
        if ($tipo === 'gratis') {
            [$item['tipo_preco'], $item['valor']] = [FreteRegiao::FIXO, 0.0];
        } elseif ($tipo === false) {
            $item['erros'][] = "Tipo \"{$campo('tipo')}\" não existe (use gratis, fixo, acrescimo, desconto ou teto).";
        } elseif ($valorTexto === '' && $tipo === null) {
            $item['erros'][] = 'Falta o valor do frete.';
        } elseif ($valor === null && $valorTexto !== '') {
            $item['erros'][] = "Valor \"{$valorTexto}\" não é número.";
        } elseif ($valor !== null && ($valor < 0 || $valor > 1000)) {
            $item['erros'][] = 'O valor tem que ficar entre R$ 0 e R$ 1.000.';
        } else {
            $item['tipo_preco'] = $tipo ?? FreteRegiao::FIXO;
            $item['valor'] = round($valor ?? 0, 2);
        }

        $prazo = $campo('prazo');
        if ($prazo !== '') {
            $dias = filter_var(preg_replace('/\D/', '', $prazo), FILTER_VALIDATE_INT);
            if ($dias === false || $dias < 1 || $dias > 60) {
                $item['erros'][] = "Prazo \"{$prazo}\" inválido (de 1 a 60 dias úteis).";
            } else {
                $item['prazo_dias'] = $dias;
            }
        }

        $ativo = IbgeMunicipio::normalizar($campo('ativo'));
        if ($ativo !== '') {
            $item['ativo'] = ! in_array($ativo, ['nao', 'n', '0', 'false', 'inativo', 'inativa', 'desligado'], true);
        }

        if (mb_strlen($item['distrito']) > 120) {
            $item['erros'][] = 'Nome do distrito muito longo.';
        }

        return $item;
    }

    /** Aquece o cache do ViaCEP com os CEPs da planilha, 10 de cada vez. */
    private function consultarCeps(array $itens): void
    {
        $faltam = collect($itens)
            ->flatMap(fn ($i) => $i['erros'] === [] ? array_unique(array_filter([$i['cep_inicio'], $i['cep_fim']])) : [])
            ->unique()
            ->reject(fn ($cep) => Cache::has("viacep.{$cep}"))
            ->take(self::MAX_CEPS_CONSULTADOS)
            ->values();

        foreach ($faltam->chunk(10) as $lote) {
            try {
                $respostas = Http::pool(fn (Pool $pool) => $lote->map(fn ($cep) => $pool->as($cep)->timeout(5)->get("https://viacep.com.br/ws/{$cep}/json/"))->all());
            } catch (\Throwable) {
                continue;
            }
            foreach ($lote as $cep) {
                $r = $respostas[$cep] ?? null;
                if ($r instanceof RespostaHttp && $r->successful() && ! ($r->json('erro') ?? false)) {
                    Cache::put("viacep.{$cep}", $r->json(), now()->addDays(30));
                }
            }
        }
    }

    /** Município de cada linha (código do IBGE, cidade + UF ou CEP) e conferência do CEP. */
    private function resolverLugares(array &$itens): void
    {
        $municipios = IbgeMunicipio::query()
            ->where(function ($q) use ($itens) {
                $ibges = array_filter(array_map(fn ($i) => (int) $i['ibge'], $itens));
                $ufs = array_unique(array_filter(array_column($itens, 'uf')));
                $q->whereIn('id', $ibges ?: [0])->orWhereIn('uf', $ufs ?: ['--']);
            })
            ->get(['id', 'nome', 'nome_normalizado', 'uf']);
        $porId = $municipios->keyBy('id');
        $porNome = $municipios->keyBy(fn ($m) => $m->uf.'|'.$m->nome_normalizado);

        foreach ($itens as &$item) {
            $doCep = $item['cep_inicio'] ? $this->doCache($item['cep_inicio']) : null;
            if ($item['uf'] === '' && $item['cidade'] !== '' && $doCep !== null) {
                $item['uf'] = strtoupper((string) ($doCep['uf'] ?? ''));
            }

            $municipio = match (true) {
                $item['ibge'] !== '' => $porId[(int) $item['ibge']] ?? null,
                $item['cidade'] !== '' && $item['uf'] !== '' => $porNome[$item['uf'].'|'.IbgeMunicipio::normalizar($item['cidade'])] ?? null,
                $item['cidade'] === '' && $doCep !== null => IbgeMunicipio::query()->find((int) ($doCep['ibge'] ?? 0)),
                default => null,
            };

            if (! $municipio) {
                $item['erros'][] = match (true) {
                    $item['ibge'] !== '' => "Código do IBGE {$item['ibge']} não existe.",
                    $item['cidade'] !== '' && $item['uf'] === '' => 'Falta a UF da cidade.',
                    $item['cidade'] !== '' => "Cidade \"{$item['cidade']}\" não existe em {$item['uf']}.",
                    $item['cep_inicio'] !== null => 'Não achei a cidade desse CEP: escreva a cidade e a UF.',
                    default => 'Falta a cidade (com a UF), o código do IBGE ou o CEP.',
                };

                continue;
            }

            $item['municipio_id'] = (int) $municipio->id;
            $item['cidade'] = $municipio->nome;
            $item['uf'] = $municipio->uf;

            // O CEP tem que ser da cidade (CEP de outra cidade criaria regra no lugar errado).
            foreach (array_unique(array_filter([$item['cep_inicio'], $item['cep_fim']])) as $cep) {
                $dados = $this->doCache($cep);
                if ($dados === null) {
                    $item['avisos'][] = 'CEP '.self::cepTexto($cep).' não conferido no ViaCEP.';
                } elseif ((int) ($dados['ibge'] ?? 0) !== $item['municipio_id']) {
                    $item['erros'][] = 'O CEP '.self::cepTexto($cep).' é de '.trim(($dados['localidade'] ?? '?').' - '.($dados['uf'] ?? '')).", não de {$municipio->nome}.";
                } elseif ($item['distrito'] !== '' && $cep === $item['cep_inicio'] && ($bairro = trim((string) ($dados['bairro'] ?? ''))) !== ''
                    && IbgeMunicipio::normalizar($bairro) !== IbgeMunicipio::normalizar($item['distrito'])) {
                    $item['avisos'][] = 'Pelo ViaCEP o CEP '.self::cepTexto($cep)." é de \"{$bairro}\".";
                }
            }

            if ($item['distrito'] === '' && $item['cep_inicio'] !== null) {
                $item['avisos'][] = 'Sem distrito: a regra vale para a cidade inteira (o CEP só conferiu a cidade).';
                $item['cep_inicio'] = $item['cep_fim'] = null;
            }
        }
    }

    /** A mesma cidade (ou distrito) duas vezes: vale a primeira sem erro, a outra vira erro. */
    private function marcarRepetidas(array &$itens): void
    {
        $vistas = [];
        foreach ($itens as &$item) {
            if ($item['municipio_id'] === null || $item['erros'] !== []) {
                continue;
            }
            $chave = self::chave($item['municipio_id'], $item['distrito']);
            if (isset($vistas[$chave])) {
                $item['erros'][] = "Repete a linha {$vistas[$chave]}.";
            } else {
                $vistas[$chave] = $item['linha'];
            }
        }
    }

    /** Criar, atualizar ou igual, olhando as regras que já existem no site. */
    private function compararComExistentes(array &$itens, string $site): void
    {
        $ids = array_unique(array_filter(array_column($itens, 'municipio_id')));
        $existentes = $ids === [] ? collect() : self::regrasDeUmLocal($site)
            ->whereIn('frete_regiao_locais.municipio_id', $ids)
            ->get(['frete_regiao_locais.municipio_id', 'frete_regiao_locais.distrito', 'frete_regiao_locais.cep_inicio', 'frete_regiao_locais.cep_fim', 'frete_regioes.*'])
            ->keyBy(fn ($r) => self::chave((int) $r->municipio_id, (string) $r->distrito));

        foreach ($itens as &$item) {
            if ($item['erros'] !== []) {
                $item['acao'] = 'erro';

                continue;
            }
            $atual = $existentes[self::chave($item['municipio_id'], $item['distrito'])] ?? null;
            if (! $atual) {
                $item['acao'] = 'criar';

                continue;
            }

            $item['regra_id'] = (int) $atual->id;
            $item['antes'] = [
                'tipo_preco' => $atual->tipo_preco,
                'valor' => round((float) $atual->valor, 2),
                'prazo_dias' => $atual->prazo_dias !== null ? (int) $atual->prazo_dias : null,
                'ativo' => (bool) $atual->ativo,
                'cep_inicio' => $atual->cep_inicio,
                'cep_fim' => $atual->cep_fim,
            ];
            $igual = $item['antes']['tipo_preco'] === $item['tipo_preco']
                && abs($item['antes']['valor'] - $item['valor']) < 0.005
                && $item['antes']['prazo_dias'] === $item['prazo_dias']
                && $item['antes']['ativo'] === $item['ativo']
                && ($item['distrito'] === '' || ($item['antes']['cep_inicio'] === $item['cep_inicio'] && $item['antes']['cep_fim'] === $item['cep_fim']));
            $item['acao'] = $igual ? 'igual' : 'atualizar';
        }
    }

    private function gravar(array $item, string $site): void
    {
        $regra = $item['regra_id'] ? FreteRegiao::query()->find($item['regra_id']) : null;
        $regra ??= new FreteRegiao(['site' => $site]);
        $distrito = $item['distrito'];

        $regra->fill([
            'nome' => $distrito === '' ? "{$item['cidade']} - {$item['uf']}" : "{$distrito} ({$item['cidade']} - {$item['uf']})",
            'tipo_preco' => $item['tipo_preco'],
            'valor' => $item['valor'],
            'prazo_dias' => $item['prazo_dias'],
            'ativo' => $item['ativo'],
        ])->save();

        if ($distrito === '') {
            if (! $regra->locais()->exists()) {
                $regra->locais()->create(['municipio_id' => $item['municipio_id']]);
            }

            return;
        }

        // Mesmo formato da tela de distritos: um local com município, nome e CEP (ou faixa).
        $regra->locais()->delete();
        $regra->locais()->create([
            'municipio_id' => $item['municipio_id'],
            'distrito' => $distrito,
            'cep_inicio' => $item['cep_inicio'],
            'cep_fim' => $item['cep_fim'],
        ]);
    }

    /**
     * Regras de um local só, de município (cidade ou distrito): as da tela.
     * A planilha mexe só na regra de todos os pacotes, não nas por categoria.
     */
    private static function regrasDeUmLocal(string $site): Builder
    {
        return DB::table('frete_regiao_locais')
            ->join('frete_regioes', 'frete_regioes.id', '=', 'frete_regiao_locais.frete_regiao_id')
            ->where('frete_regioes.site', $site)
            ->whereNull('frete_regioes.categoria')
            ->whereNotNull('frete_regiao_locais.municipio_id')
            ->whereNotExists(fn ($q) => $q->from('frete_regiao_locais as outro')
                ->whereColumn('outro.frete_regiao_id', 'frete_regiao_locais.frete_regiao_id')
                ->whereColumn('outro.id', '!=', 'frete_regiao_locais.id'));
    }

    /** @return array<string, int> nome normalizado da coluna => índice */
    private static function mapearColunas(array $cabecalho): array
    {
        $mapa = [];
        foreach ($cabecalho as $indice => $nome) {
            $chave = trim(preg_replace('/[^a-z0-9]+/', ' ', IbgeMunicipio::normalizar((string) $nome)));
            $campo = self::COLUNAS[$chave] ?? null;
            if ($campo && ! isset($mapa[$campo])) {
                $mapa[$campo] = $indice;
            }
        }

        return $mapa;
    }

    /** @return list<string> CEPs de 8 números do texto (planilha às vezes come o zero da frente) */
    private static function ceps(string $texto): array
    {
        if ($texto === '') {
            return [];
        }
        if (preg_match('/^\d{7}$/', $texto)) {
            return ['0'.$texto];
        }
        preg_match_all('/(?<!\d)(\d{2})\.?(\d{3})-?(\d{3})(?!\d)/', $texto, $m, PREG_SET_ORDER);

        return array_slice(array_map(fn ($c) => $c[1].$c[2].$c[3], $m), 0, 2);
    }

    /** @return string|null|false tipo da regra, 'gratis', null (vazio) ou false (não existe) */
    private static function tipo(string $texto): string|null|false
    {
        $t = trim(preg_replace('/[^a-z+\-]+/', ' ', IbgeMunicipio::normalizar($texto)));

        return match (true) {
            $t === '' => null,
            in_array($t, ['gratis', 'frete gratis', 'gratuito', 'free'], true) => 'gratis',
            in_array($t, ['fixo', 'valor fixo', 'frete fixo'], true) => FreteRegiao::FIXO,
            in_array($t, ['acrescimo', 'correios + valor', 'correios +', '+', 'mais'], true) => FreteRegiao::ACRESCIMO,
            in_array($t, ['desconto', 'correios - valor', 'correios -', '-', 'menos'], true) => FreteRegiao::DESCONTO,
            in_array($t, ['teto', 'maximo', 'correios ate', 'correios ate no maximo'], true) => FreteRegiao::TETO,
            default => false,
        };
    }

    /** "R$ 1.234,56", "12,5" ou "12.5" (célula numérica do Excel) em número. */
    private static function dinheiro(string $texto): ?float
    {
        $t = preg_replace('/[^\d,.\-]/', '', $texto);
        if ($t === '' || $t === '-') {
            return null;
        }
        if (str_contains($t, ',')) {
            $t = str_replace(',', '.', str_replace('.', '', $t));
        } elseif (substr_count($t, '.') > 1) {
            $t = str_replace('.', '', $t);
        }

        return is_numeric($t) ? (float) $t : null;
    }

    private function doCache(string $cep): ?array
    {
        $dados = Cache::get("viacep.{$cep}");

        return is_array($dados) ? $dados : null;
    }

    private static function chave(int $municipio, string $distrito): string
    {
        return $municipio.'|'.IbgeMunicipio::normalizar($distrito);
    }

    private static function cepTexto(?string $cep): string
    {
        return $cep ? substr($cep, 0, 5).'-'.substr($cep, 5) : '';
    }

    private static function paraTela(array $i): array
    {
        return [
            'linha' => $i['linha'],
            'uf' => $i['uf'],
            'cidade' => $i['cidade'],
            'municipio_id' => $i['municipio_id'],
            'distrito' => $i['distrito'],
            'cep_inicio' => $i['cep_inicio'],
            'cep_fim' => $i['cep_fim'],
            'tipo_preco' => $i['tipo_preco'],
            'valor' => $i['valor'],
            'prazo_dias' => $i['prazo_dias'],
            'ativo' => $i['ativo'],
            'acao' => $i['acao'],
            'antes' => $i['antes'],
            'regra_id' => $i['regra_id'],
            'erros' => $i['erros'],
            'avisos' => $i['avisos'],
        ];
    }

    /** @return array{total: int, criar: int, atualizar: int, igual: int, erro: int} */
    private static function resumo(array $itens): array
    {
        $acoes = array_count_values(array_map(fn ($i) => (string) $i['acao'], $itens));

        return [
            'total' => count($itens),
            'criar' => $acoes['criar'] ?? 0,
            'atualizar' => $acoes['atualizar'] ?? 0,
            'igual' => $acoes['igual'] ?? 0,
            'erro' => $acoes['erro'] ?? 0,
        ];
    }
}
