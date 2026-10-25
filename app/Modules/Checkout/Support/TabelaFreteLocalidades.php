<?php

namespace App\Modules\Checkout\Support;

use App\Models\IbgeMunicipio;
use App\Modules\Fiscal\Models\Company;
use App\Services\Correios\CorreiosTokenService;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Tabela de frete própria por localidade (pedido do Lira em 2026-10-25): o
 * site calcula o frete pelo que está salvo no banco, sem depender da API dos
 * Correios na hora da compra.
 *
 * 1. Localidades: API de CEP dos Correios (/cep/v1/localidades, mesma
 *    credencial do Preço/Prazo), por UF. Traz municípios (M), distritos (D)
 *    e povoados (P) com o CEP geral e as faixas de CEP do município. Ex.:
 *    Água Doce do Norte (ES) 29820-000, com os distritos Vila Nelita
 *    29822-000, Santa Luzia do Azul 29823-000, Santo Agostinho 29824-000 e
 *    Governador Lacerda de Aguiar 29826-000. Cidade com CEP por rua não tem
 *    CEP geral: a cotação usa o início da faixa (a API aceita, conferido em
 *    25/10). Distrito sem CEP próprio usa o do município.
 * 2. Cotação: para cada CEP de cotação, PAC e SEDEX (preço do contrato e
 *    prazo) das 4 categorias de tamanho (caixa de referência em
 *    frete_categorias_pacote). Categoria que
 *    passa do limite dos Correios não vai para a API: fica com o erro.
 *    Localidades com o mesmo CEP dividem a cotação.
 * 3. Consulta: CEP do cliente → localidade (CEP exato do distrito ou faixa
 *    do município) → linha da categoria do produto.
 *
 * Quem roda: PreencherTabelaFreteJob (fila default), comando
 * frete:tabela-localidades e o agendamento mensal.
 */
class TabelaFreteLocalidades
{
    public const PAC = '03298';

    public const SEDEX = '03220';

    public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    /** Cotação mais velha que isso volta para a fila (o agendamento é mensal). */
    public const VALIDADE_DIAS = 25;

    /** Limites do PAC/SEDEX para pacote. */
    private const PESO_MAX_G = 30000;

    private const LADO_MAX_CM = 100;

    private const SOMA_MAX_CM = 200;

    /** A API de Preço/Prazo aceita no máximo 5 itens por lote (PRZ-109). */
    private const LOTE = 5;

    /** Pedidos ao mesmo tempo, para não estourar o limite da API. */
    private const SIMULTANEOS = 8;

    private const VERSAO = 'frete-tabela:versao';

    public function __construct(private readonly CorreiosTokenService $tokens)
    {
    }

    // ------------------------------------------------------------------
    // 1. Localidades
    // ------------------------------------------------------------------

    /**
     * Baixa as localidades de uma UF e grava (atualiza as que já existem).
     *
     * @return int quantas localidades vieram
     */
    public function importarUf(string $uf): int
    {
        $uf = strtoupper($uf);
        $token = $this->token();
        $url = rtrim((string) config('services.correios.cep_base_url'), '/').'/v1/localidades';

        $itens = [];
        $pagina = 0;
        do {
            $resposta = Http::withToken($token)->acceptJson()->timeout(60)
                ->retry(2, 3000, throw: false)
                ->get($url, ['uf' => $uf, 'page' => $pagina, 'size' => 1000]);

            if ($resposta->failed()) {
                throw new RuntimeException("API de CEP dos Correios ({$uf}): HTTP {$resposta->status()}");
            }

            $itens = array_merge($itens, array_filter((array) $resposta->json('itens'), 'is_array'));
            $pagina++;
        } while ($pagina < (int) $resposta->json('page.totalPages', 1));

        if ($itens === []) {
            return 0;
        }

        $porNumero = [];
        foreach ($itens as $item) {
            $porNumero[(int) $item['numero']] = $item;
        }

        $agora = now();
        $linhas = [];
        $faixas = [];
        foreach ($porNumero as $numero => $item) {
            $municipio = $this->municipioDe($item, $porNumero);
            $cep = self::cep($item['cep'] ?? null);
            $cepMunicipio = $municipio ? $this->cepDoMunicipio($municipio) : null;

            $linhas[] = [
                'numero_correios' => $numero,
                'uf' => $uf,
                'nome' => (string) $item['localidade'],
                'nome_normalizado' => IbgeMunicipio::normalizar((string) $item['localidade']),
                'tipo' => in_array($item['tipo'] ?? '', ['M', 'D', 'P'], true) ? $item['tipo'] : 'D',
                'municipio_id' => isset($municipio['codigoIbge']) ? (int) $municipio['codigoIbge'] : null,
                'superior_numero' => isset($item['numeroLocalidadeSuperior']) ? (int) $item['numeroLocalidadeSuperior'] : null,
                'municipio_nome' => $municipio['localidade'] ?? null,
                'cep' => $cep,
                'cep_cotacao' => $cep ?? $cepMunicipio,
                'cep_herdado' => $cep === null && $cepMunicipio !== null,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];

            foreach ((array) ($item['faixas'] ?? []) as $f) {
                $inicio = self::cep($f['cepInicial'] ?? null);
                $fim = self::cep($f['cepFinal'] ?? null);
                if ($inicio && $fim) {
                    $faixas[$numero][$inicio.$fim] = ['cep_inicial' => $inicio, 'cep_final' => $fim];
                }
            }
        }

        DB::transaction(function () use ($uf, $linhas, $faixas) {
            foreach (array_chunk($linhas, 500) as $lote) {
                DB::table('frete_localidades')->upsert($lote, ['numero_correios'], [
                    'uf', 'nome', 'nome_normalizado', 'tipo', 'municipio_id', 'superior_numero', 'municipio_nome',
                    'cep', 'cep_cotacao', 'cep_herdado', 'updated_at',
                ]);
            }

            $ids = DB::table('frete_localidades')->where('uf', $uf)->pluck('id', 'numero_correios');
            DB::table('frete_localidade_faixas')->whereIn('frete_localidade_id', $ids->values())->delete();

            $novas = [];
            foreach ($faixas as $numero => $lista) {
                foreach ($lista as $f) {
                    if (isset($ids[$numero])) {
                        $novas[] = ['frete_localidade_id' => $ids[$numero], ...$f];
                    }
                }
            }
            foreach (array_chunk($novas, 500) as $lote) {
                DB::table('frete_localidade_faixas')->insert($lote);
            }
        });

        $this->novaVersao();

        return count($linhas);
    }

    /** Município da localidade: ela mesma (M) ou a superior, subindo até achar um. */
    private function municipioDe(array $item, array $porNumero): ?array
    {
        for ($i = 0; $i < 5 && $item; $i++) {
            if (($item['tipo'] ?? null) === 'M') {
                return $item;
            }
            $item = $porNumero[(int) ($item['numeroLocalidadeSuperior'] ?? 0)] ?? null;
        }

        return null;
    }

    /** CEP geral do município ou, na cidade com CEP por rua, o início da faixa total. */
    private function cepDoMunicipio(array $municipio): ?string
    {
        if ($cep = self::cep($municipio['cep'] ?? null)) {
            return $cep;
        }

        $faixas = collect((array) ($municipio['faixas'] ?? []));
        $faixa = $faixas->firstWhere('tipoFaixa', 'T') ?? $faixas->first();

        return self::cep($faixa['cepInicial'] ?? null);
    }

    // ------------------------------------------------------------------
    // 2. Cotação
    // ------------------------------------------------------------------

    /** CEPs de cotação sem as 4 categorias em dia (origem atual, dentro da validade, com a caixa atual da categoria). */
    public function cepsPendentes(int $limite): array
    {
        $origem = $this->origem();
        $categorias = DB::table('frete_categorias_pacote')->whereNotNull('ref_peso_g')->count();
        if (! $origem || $categorias === 0) {
            return [];
        }

        $emDia = DB::table('frete_tabela as t')
            ->join('frete_categorias_pacote as c', 'c.chave', '=', 't.categoria')
            ->whereColumn('t.cep_destino', 'frete_localidades.cep_cotacao')
            ->where('t.cep_origem', $origem)
            ->where('t.cotado_em', '>=', now()->subDays(self::VALIDADE_DIAS))
            ->whereColumn('t.categoria_versao', 'c.versao')
            ->selectRaw('count(*)');

        return DB::table('frete_localidades')
            ->whereNotNull('cep_cotacao')
            ->whereRaw('('.$emDia->toSql().') < ?', [...$emDia->getBindings(), $categorias])
            ->distinct()
            ->orderBy('cep_cotacao')
            ->limit($limite)
            ->pluck('cep_cotacao')
            ->all();
    }

    /** Quantos CEPs de cotação existem e quantos estão em dia. */
    public function contagem(): array
    {
        $total = DB::table('frete_localidades')->whereNotNull('cep_cotacao')->distinct()->count('cep_cotacao');

        return ['ceps' => $total, 'pendentes' => count($this->cepsPendentes(1000000))];
    }

    /**
     * Cota PAC e SEDEX das categorias para os CEPs e grava em frete_tabela.
     * Item com erro dos Correios (CEP não atendido, medida fora do limite)
     * fica gravado com o erro; falha da API (HTTP) não grava nada e o CEP
     * continua pendente.
     *
     * @param  list<string>  $ceps
     * @return int quantos CEPs ficaram gravados
     */
    public function cotar(array $ceps): int
    {
        $origem = $this->origem();
        if (! $origem || $ceps === []) {
            return 0;
        }

        $token = $this->token();
        $categorias = DB::table('frete_categorias_pacote')->whereNotNull('ref_peso_g')->orderBy('ordem')->get();

        $precos = [];
        $prazos = [];
        $foraDoLimite = [];
        foreach ($ceps as $cep) {
            foreach ([self::PAC, self::SEDEX] as $servico) {
                $prazos[] = ['coProduto' => $servico, 'nuRequisicao' => "{$cep}-{$servico}", 'cepOrigem' => $origem, 'cepDestino' => $cep];
            }
            foreach ($categorias as $c) {
                if ($motivo = self::foraDoLimite($c)) {
                    $foraDoLimite[$c->chave] = $motivo;

                    continue;
                }
                foreach ([self::PAC, self::SEDEX] as $servico) {
                    $precos[] = [
                        'coProduto' => $servico,
                        'nuRequisicao' => "{$cep}-{$c->chave}-{$servico}",
                        'cepOrigem' => $origem,
                        'cepDestino' => $cep,
                        'psObjeto' => (string) $c->ref_peso_g,
                        'tpObjeto' => '2',
                        // Caixa mínima dos Correios: 16 x 11 x 2 cm.
                        'comprimento' => (string) max(16, (float) $c->ref_comprimento_cm),
                        'largura' => (string) max(11, (float) $c->ref_largura_cm),
                        'altura' => (string) max(2, (float) $c->ref_altura_cm),
                    ];
                }
            }
        }

        $urlPreco = rtrim((string) config('services.correios.preco_base_url'), '/').'/v1/nacional';
        $urlPrazo = rtrim((string) config('services.correios.prazo_base_url'), '/').'/v1/nacional';

        $pedidos = [];
        foreach (array_chunk($precos, self::LOTE) as $lote) {
            $pedidos[] = [$urlPreco, ['idLote' => (string) Str::uuid(), 'parametrosProduto' => $lote]];
        }
        foreach (array_chunk($prazos, self::LOTE) as $lote) {
            $pedidos[] = [$urlPrazo, ['idLote' => (string) Str::uuid(), 'parametrosPrazo' => $lote]];
        }

        // nuRequisicao => [preco?, prazo?, erro?]; requisições sem resposta = CEP fica pendente.
        $dados = [];
        $semResposta = [];
        foreach (array_chunk($pedidos, self::SIMULTANEOS) as $grupo) {
            $respostas = Http::pool(fn (Pool $pool) => array_map(
                fn ($p) => $pool->withToken($token)->acceptJson()->timeout(20)->post($p[0], $p[1]),
                $grupo,
            ));

            foreach ($respostas as $i => $resposta) {
                $itens = $grupo[$i][1]['parametrosProduto'] ?? $grupo[$i][1]['parametrosPrazo'];
                if (! $resposta instanceof Response || $resposta->failed()) {
                    foreach ($itens as $it) {
                        $semResposta[$it['cepDestino']] = true;
                    }
                    Log::channel('correios')->warning('frete_tabela.lote_falhou', [
                        'status' => $resposta instanceof Response ? $resposta->status() : null,
                        'erro' => $resposta instanceof Throwable ? $resposta->getMessage() : null,
                    ]);

                    continue;
                }

                foreach ((array) $resposta->json() as $item) {
                    if (! is_array($item) || ! isset($item['nuRequisicao'])) {
                        continue;
                    }
                    $k = (string) $item['nuRequisicao'];
                    if (isset($item['pcFinal']) && empty($item['txErro'])) {
                        // pcFinal vem como string com vírgula decimal (ex.: "23,45").
                        $dados[$k]['preco'] = (float) str_replace(',', '.', (string) $item['pcFinal']);
                    }
                    if (isset($item['prazoEntrega'])) {
                        $dados[$k]['prazo'] = (int) $item['prazoEntrega'];
                    }
                    if (! empty($item['txErro'])) {
                        $dados[$k]['erro'] = Str::limit((string) $item['txErro'], 120, '');
                    }
                }
            }
        }

        $agora = now();
        $linhas = [];
        $gravados = 0;
        foreach ($ceps as $cep) {
            if (isset($semResposta[$cep])) {
                continue;
            }
            $gravados++;
            $prazoPac = $dados["{$cep}-".self::PAC]['prazo'] ?? null;
            $prazoSedex = $dados["{$cep}-".self::SEDEX]['prazo'] ?? null;

            foreach ($categorias as $c) {
                $pac = $dados["{$cep}-{$c->chave}-".self::PAC] ?? [];
                $sedex = $dados["{$cep}-{$c->chave}-".self::SEDEX] ?? [];
                $erros = array_unique(array_filter([
                    $foraDoLimite[$c->chave] ?? null,
                    isset($pac['erro']) ? "PAC: {$pac['erro']}" : null,
                    isset($sedex['erro']) ? "SEDEX: {$sedex['erro']}" : null,
                ]));

                $linhas[] = [
                    'cep_destino' => $cep,
                    'categoria' => $c->chave,
                    'categoria_versao' => (int) $c->versao,
                    'cep_origem' => $origem,
                    'pac_preco' => $pac['preco'] ?? null,
                    'pac_prazo' => isset($pac['preco']) ? $prazoPac : null,
                    'sedex_preco' => $sedex['preco'] ?? null,
                    'sedex_prazo' => isset($sedex['preco']) ? $prazoSedex : null,
                    'erro' => $erros ? Str::limit(implode(' | ', $erros), 250, '') : null,
                    'cotado_em' => $agora,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];
            }
        }

        foreach (array_chunk($linhas, 500) as $lote) {
            DB::table('frete_tabela')->upsert($lote, ['cep_destino', 'categoria'], [
                'categoria_versao', 'cep_origem', 'pac_preco', 'pac_prazo', 'sedex_preco', 'sedex_prazo', 'erro', 'cotado_em', 'updated_at',
            ]);
        }

        if ($semResposta !== [] && $gravados === 0) {
            throw new RuntimeException('A API de Preço/Prazo dos Correios não respondeu.');
        }

        $this->novaVersao();

        return $gravados;
    }

    /** Motivo de a caixa não ir para os Correios (null = dentro do limite). */
    public static function foraDoLimite(object $categoria): ?string
    {
        $lados = [(float) $categoria->ref_comprimento_cm, (float) $categoria->ref_largura_cm, (float) $categoria->ref_altura_cm];

        return match (true) {
            (int) $categoria->ref_peso_g > self::PESO_MAX_G => 'Correios não atende: passa de 30 kg',
            max($lados) > self::LADO_MAX_CM => 'Correios não atende: lado acima de 100 cm',
            array_sum($lados) > self::SOMA_MAX_CM => 'Correios não atende: soma dos lados acima de 200 cm',
            default => null,
        };
    }

    // ------------------------------------------------------------------
    // 3. Consulta
    // ------------------------------------------------------------------

    /**
     * Localidade do CEP e frete salvo das categorias (null = CEP fora da
     * tabela ou ainda sem cotação). Em cache até a próxima gravação.
     *
     * @return array{localidade: array<string, mixed>, categorias: array<string, array{pac: ?float, sedex: ?float, prazo_pac: ?int, prazo_sedex: ?int, erro: ?string, cotado_em: ?string}>}|null
     */
    public function paraCep(?string $cep): ?array
    {
        $cep = self::cep($cep);
        if (! $cep) {
            return null;
        }

        $versao = (int) Cache::get(self::VERSAO, 0);

        return rescue(fn () => Cache::remember("frete-tabela:{$versao}:{$cep}", now()->addHours(6), function () use ($cep) {
            $localidade = $this->localidadeDoCep($cep);
            if (! $localidade || ! $localidade->cep_cotacao) {
                return null;
            }

            $categorias = DB::table('frete_tabela')
                ->where('cep_destino', $localidade->cep_cotacao)
                ->where('cep_origem', $this->origem())
                ->get()
                ->mapWithKeys(fn ($t) => [$t->categoria => [
                    'pac' => $t->pac_preco !== null ? (float) $t->pac_preco : null,
                    'sedex' => $t->sedex_preco !== null ? (float) $t->sedex_preco : null,
                    'prazo_pac' => $t->pac_prazo !== null ? (int) $t->pac_prazo : null,
                    'prazo_sedex' => $t->sedex_prazo !== null ? (int) $t->sedex_prazo : null,
                    'erro' => $t->erro,
                    'cotado_em' => $t->cotado_em,
                ]])
                ->all();

            return $categorias === [] ? null : ['localidade' => (array) $localidade, 'categorias' => $categorias];
        }), null, false);
    }

    /** Frete salvo de uma categoria para o CEP (null = sem cotação utilizável). */
    public function linha(?string $cep, ?string $categoria): ?array
    {
        if (! $categoria) {
            return null;
        }

        $linha = $this->paraCep($cep)['categorias'][$categoria] ?? null;

        return $linha && ($linha['pac'] !== null || $linha['sedex'] !== null) ? $linha : null;
    }

    /**
     * Localidades (linhas de frete_localidades) com o PAC e o SEDEX salvos de
     * cada categoria, no formato das tabelas do admin (Frete por localidade e
     * página da cidade em Fretes por estado).
     */
    public function paraTela(Collection $localidades): array
    {
        $cotacoes = DB::table('frete_tabela')
            ->whereIn('cep_destino', $localidades->pluck('cep_cotacao')->filter()->unique())
            ->where('cep_origem', $this->origem())
            ->get()
            ->groupBy('cep_destino');

        return $localidades->map(fn ($l) => [
            'id' => $l->id,
            'uf' => $l->uf,
            'nome' => $l->nome,
            'tipo' => $l->tipo,
            'municipio' => $l->municipio_nome,
            'cep' => $l->cep,
            'cep_cotacao' => $l->cep_cotacao,
            'cep_herdado' => (bool) $l->cep_herdado,
            'regras' => collect($cotacoes[$l->cep_cotacao] ?? [])->mapWithKeys(fn ($t) => [$t->categoria => [
                'pac' => $t->pac_preco !== null ? (float) $t->pac_preco : null,
                'pac_prazo' => $t->pac_prazo,
                'sedex' => $t->sedex_preco !== null ? (float) $t->sedex_preco : null,
                'sedex_prazo' => $t->sedex_prazo,
                'erro' => $t->erro,
                'cotado_em' => $t->cotado_em,
            ]]),
        ])->values()->all();
    }

    /** CEP exato (distrito ou CEP geral) e, sem ele, a menor faixa de município que contém o CEP. */
    public function localidadeDoCep(string $cep): ?object
    {
        $colunas = ['l.id', 'l.uf', 'l.nome', 'l.tipo', 'l.municipio_id', 'l.municipio_nome', 'l.cep', 'l.cep_cotacao', 'l.cep_herdado'];

        return DB::table('frete_localidades as l')
            ->where('l.cep', $cep)
            ->orderByRaw("case l.tipo when 'M' then 0 when 'D' then 1 else 2 end")
            ->first($colunas)
            ?? DB::table('frete_localidades as l')
                ->join('frete_localidade_faixas as f', 'f.frete_localidade_id', '=', 'l.id')
                ->where('f.cep_inicial', '<=', $cep)
                ->where('f.cep_final', '>=', $cep)
                ->orderByRaw('(f.cep_final - f.cep_inicial)')
                ->first($colunas);
    }

    // ------------------------------------------------------------------

    public function origem(): ?string
    {
        $cep = self::cep(Company::query()->value('zip'));

        return $cep ?: null;
    }

    private function token(): string
    {
        if (! $this->tokens->isConfigured()) {
            throw new RuntimeException('A integração com os Correios não está configurada.');
        }

        return $this->tokens->tokenForPrecoPrazo();
    }

    /** Invalida o cache das consultas (paraCep) depois de gravar. */
    private function novaVersao(): void
    {
        Cache::forever(self::VERSAO, (int) Cache::get(self::VERSAO, 0) + 1);
    }

    private static function cep(mixed $valor): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $valor) ?? '';

        return strlen($digitos) === 8 ? $digitos : null;
    }
}
