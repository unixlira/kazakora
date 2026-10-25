<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\IbgeEstado;
use App\Models\IbgeMunicipio;
use App\Modules\Checkout\Models\FreteCategoriaPacote;
use App\Modules\Checkout\Models\FreteRegiao;
use App\Modules\Checkout\Support\RegrasDeFrete;
use App\Modules\Checkout\Support\TabelaFreteLocalidades;
use App\Support\SiteAtual;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fretes por cidade (pedido do Lira em 2026-10-24): lista as cidades do
 * estado com a regra de frete de cada categoria de pacote (Pequena, Média,
 * Grande, Extra Grande) e abre a página da cidade para criar, trocar ou tirar
 * as regras. A página da cidade traz também os distritos (regra própria por
 * distrito, FretesPorEstadoController::distritos, só KazaKora por enquanto).
 *
 * Uma regra = FreteRegiao com um local só (o município) e categoria null
 * (todas as categorias) ou a chave de uma categoria. Vale no frete embutido
 * do site escolhido (KazaKora, FerraKora ou Beauty Kora).
 *
 * Desde 2026-10-25 sem item próprio no menu: fica dentro de Logística →
 * Fretes por estado (o olho do estado abre as cidades) e a página da cidade
 * mostra em cima o PAC e o SEDEX de cada categoria, da tabela de Frete por
 * localidade, para a cidade e os distritos dela.
 */
class FretesPorCidadeController extends Controller
{
    public function index(Request $request): Response
    {
        $site = self::site($request);
        $uf = strtoupper((string) $request->query('uf', 'SP'));
        $estado = IbgeEstado::query()->where('sigla', $uf)->first()
            ?? IbgeEstado::query()->orderBy('nome')->first();

        $regras = $estado ? self::regrasDaCidade($site)
            ->whereIn('frete_regiao_locais.municipio_id', IbgeMunicipio::query()->where('estado_id', $estado->id)->select('id'))
            ->get(['frete_regiao_locais.municipio_id', 'frete_regioes.*'])
            ->groupBy('municipio_id') : collect();

        $distritos = $estado ? self::regrasDeUmLocal($site)
            ->whereNotNull('frete_regiao_locais.distrito')
            ->whereIn('frete_regiao_locais.municipio_id', IbgeMunicipio::query()->where('estado_id', $estado->id)->select('id'))
            ->groupBy('frete_regiao_locais.municipio_id')
            ->selectRaw('frete_regiao_locais.municipio_id, count(*) as n')
            ->pluck('n', 'municipio_id') : collect();

        // Cidades com regra própria ou com algum distrito com regra.
        $porUf = self::regrasDeUmLocal($site)
            ->join('ibge_municipios', 'ibge_municipios.id', '=', 'frete_regiao_locais.municipio_id')
            ->groupBy('ibge_municipios.uf')
            ->selectRaw('ibge_municipios.uf as uf, count(distinct frete_regiao_locais.municipio_id) as n')
            ->pluck('n', 'uf');

        return Inertia::render('Admin/FretesPorCidade/Index', [
            'site' => $site,
            'sites' => SiteAtual::SITES,
            'uf' => $estado?->sigla,
            'estado' => $estado?->nome,
            'estados' => IbgeEstado::query()->orderBy('nome')->get(['sigla', 'nome'])
                ->map(fn (IbgeEstado $e) => ['uf' => $e->sigla, 'nome' => $e->nome, 'com_regra' => (int) ($porUf[$e->sigla] ?? 0)])
                ->values(),
            'categorias' => self::categoriasComProdutos(),
            'cidades' => $estado ? IbgeMunicipio::query()->where('estado_id', $estado->id)->orderBy('nome_normalizado')->get(['id', 'nome'])
                ->map(fn (IbgeMunicipio $m) => [
                    'id' => $m->id,
                    'nome' => $m->nome,
                    'regras' => self::regrasPorCategoria($regras[$m->id] ?? collect()),
                    'distritos_com_regra' => (int) ($distritos[$m->id] ?? 0),
                ])
                ->values() : [],
        ]);
    }

    public function show(Request $request, int $municipio, TabelaFreteLocalidades $tabela): Response
    {
        $cidade = IbgeMunicipio::query()->findOrFail($municipio);
        $site = self::site($request);

        $regras = self::regrasDaCidade($site)
            ->where('frete_regiao_locais.municipio_id', $cidade->id)
            ->get(['frete_regiao_locais.municipio_id', 'frete_regioes.*']);

        return Inertia::render('Admin/FretesPorCidade/Cidade', [
            'site' => $site,
            'sites' => SiteAtual::SITES,
            'cidade' => [
                'id' => $cidade->id,
                'nome' => $cidade->nome,
                'uf' => $cidade->uf,
                'estado' => IbgeEstado::query()->whereKey($cidade->estado_id)->value('nome') ?? $cidade->uf,
            ],
            'categorias' => self::categoriasComProdutos(),
            'regras' => self::regrasPorCategoria($regras),
            // A cidade e, embaixo, os distritos e povoados dela.
            'localidades' => $tabela->paraTela(DB::table('frete_localidades')
                ->where('municipio_id', $cidade->id)
                ->orderByRaw("case tipo when 'M' then 0 when 'D' then 1 else 2 end")
                ->orderBy('nome')
                ->limit(200)
                ->get()),
        ]);
    }

    /** Cria ou troca a regra da cidade para uma categoria (ou para todas). */
    public function salvarRegra(Request $request, int $municipio): RedirectResponse
    {
        $cidade = IbgeMunicipio::query()->findOrFail($municipio);
        $dados = $request->validate([
            'site' => ['required', Rule::in(array_keys(SiteAtual::SITES))],
            'categoria' => ['nullable', Rule::in(array_column(FreteCategoriaPacote::todas(), 'chave'))],
            'tipo_preco' => ['required', Rule::in(FreteRegiao::TIPOS)],
            'valor' => ['required', 'numeric', 'min:0', 'max:1000'],
            'prazo_dias' => ['nullable', 'integer', 'min:1', 'max:60'],
            'ativo' => ['boolean'],
        ], [
            'tipo_preco.in' => 'Escolha o tipo da regra.',
            'valor.max' => 'O valor pode ir até R$ 1.000.',
            'prazo_dias.max' => 'O prazo pode ir até 60 dias úteis.',
        ]);
        $categoria = $dados['categoria'] ?? null;

        DB::transaction(function () use ($cidade, $dados, $categoria) {
            $id = self::regrasDaCidade($dados['site'])
                ->where('frete_regiao_locais.municipio_id', $cidade->id)
                ->when($categoria, fn ($q) => $q->where('frete_regioes.categoria', $categoria), fn ($q) => $q->whereNull('frete_regioes.categoria'))
                ->value('frete_regioes.id');
            $regra = $id ? FreteRegiao::query()->find($id) : new FreteRegiao(['site' => $dados['site'], 'categoria' => $categoria]);

            $nomeCategoria = collect(FreteCategoriaPacote::todas())->firstWhere('chave', $categoria)['nome'] ?? null;
            $regra->fill([
                'nome' => "{$cidade->nome} - {$cidade->uf}".($nomeCategoria ? " ({$nomeCategoria})" : ''),
                'tipo_preco' => $dados['tipo_preco'],
                'valor' => round((float) $dados['valor'], 2),
                'prazo_dias' => $dados['prazo_dias'] ?? null,
                'ativo' => (bool) ($dados['ativo'] ?? true),
            ])->save();

            if (! $regra->locais()->exists()) {
                $regra->locais()->create(['municipio_id' => $cidade->id]);
            }
        });
        RegrasDeFrete::esquecer();

        return back()->with('success', "Regra de {$cidade->nome} salva.");
    }

    /** Tira a regra da cidade para a categoria: volta para a regra geral ou para os Correios. */
    public function removerRegra(Request $request, int $municipio): RedirectResponse
    {
        $cidade = IbgeMunicipio::query()->findOrFail($municipio);
        $dados = $request->validate([
            'site' => ['required', Rule::in(array_keys(SiteAtual::SITES))],
            'categoria' => ['nullable', 'string', 'max:20'],
        ]);
        $categoria = $dados['categoria'] ?? null;

        $ids = self::regrasDaCidade($dados['site'])
            ->where('frete_regiao_locais.municipio_id', $cidade->id)
            ->when($categoria, fn ($q) => $q->where('frete_regioes.categoria', $categoria), fn ($q) => $q->whereNull('frete_regioes.categoria'))
            ->pluck('frete_regioes.id');
        FreteRegiao::query()->whereIn('id', $ids)->delete();
        RegrasDeFrete::esquecer();

        return back()->with('success', "Regra de {$cidade->nome} retirada.");
    }

    /** Limites das categorias de pacote (a última fica sem limite). */
    public function salvarCategorias(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'categorias' => ['required', 'array'],
            'categorias.*.chave' => ['required', 'string', Rule::in(array_column(FreteCategoriaPacote::todas(), 'chave'))],
            'categorias.*.peso_max_g' => ['nullable', 'integer', 'min:1', 'max:30000'],
            'categorias.*.lado_max_cm' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $limites = collect($dados['categorias'])->keyBy('chave');
        $anterior = ['peso_max_g' => 0, 'lado_max_cm' => 0];
        $todas = FreteCategoriaPacote::query()->orderBy('ordem')->get();
        foreach ($todas as $i => $c) {
            $ultima = $i === $todas->count() - 1;
            $novo = $ultima ? ['peso_max_g' => null, 'lado_max_cm' => null] : [
                'peso_max_g' => $limites[$c->chave]['peso_max_g'] ?? $c->peso_max_g,
                'lado_max_cm' => $limites[$c->chave]['lado_max_cm'] ?? $c->lado_max_cm,
            ];
            if (! $ultima && ($novo['peso_max_g'] <= $anterior['peso_max_g'] || $novo['lado_max_cm'] <= $anterior['lado_max_cm'])) {
                return back()->withErrors(['categorias' => "Os limites da {$c->nome} precisam ser maiores que os da categoria anterior."]);
            }
            $anterior = $novo;
            $c->fill($novo);
        }

        DB::transaction(fn () => $todas->each->save());
        FreteCategoriaPacote::esquecer();

        return back()->with('success', 'Categorias de pacote salvas.');
    }

    private static function site(Request $request): string
    {
        $site = (string) $request->query('site', SiteAtual::KAZAKORA);

        return isset(SiteAtual::SITES[$site]) ? $site : SiteAtual::KAZAKORA;
    }

    /**
     * Regras de uma cidade só (um local, de município, sem distrito) do site:
     * as que esta tela cria. Regras com vários locais ou por UF/CEP ficam de fora.
     */
    private static function regrasDaCidade(string $site): Builder
    {
        return self::regrasDeUmLocal($site)->whereNull('frete_regiao_locais.distrito');
    }

    /** Regras de um local só, de município (cidade ou distrito), do site. */
    private static function regrasDeUmLocal(string $site): Builder
    {
        return DB::table('frete_regiao_locais')
            ->join('frete_regioes', 'frete_regioes.id', '=', 'frete_regiao_locais.frete_regiao_id')
            ->where('frete_regioes.site', $site)
            ->whereNotNull('frete_regiao_locais.municipio_id')
            ->whereNotExists(fn ($q) => $q->from('frete_regiao_locais as outro')
                ->whereColumn('outro.frete_regiao_id', 'frete_regiao_locais.frete_regiao_id')
                ->whereColumn('outro.id', '!=', 'frete_regiao_locais.id'));
    }

    /** @return array<string, ?array> 'todas' e uma entrada por categoria */
    private static function regrasPorCategoria(Collection $regras): array
    {
        $porCategoria = $regras->keyBy(fn ($r) => $r->categoria ?? 'todas');
        $saida = ['todas' => self::regraParaTela($porCategoria['todas'] ?? null)];
        foreach (FreteCategoriaPacote::todas() as $c) {
            $saida[$c['chave']] = self::regraParaTela($porCategoria[$c['chave']] ?? null);
        }

        return $saida;
    }

    private static function regraParaTela(?object $regra): ?array
    {
        return $regra ? [
            'id' => (int) $regra->id,
            'tipo_preco' => $regra->tipo_preco,
            'valor' => round((float) $regra->valor, 2),
            'prazo_dias' => $regra->prazo_dias !== null ? (int) $regra->prazo_dias : null,
            'ativo' => (bool) $regra->ativo,
        ] : null;
    }

    /**
     * Categorias com quantos produtos ativos caem em cada uma e alguns
     * exemplos (o maior primeiro), para conferir os limites.
     */
    private static function categoriasComProdutos(): array
    {
        $produtos = DB::table('products')
            ->join('product_fiscal_data', 'product_fiscal_data.product_id', '=', 'products.id')
            ->where('products.is_active', true)
            ->where('product_fiscal_data.peso_bruto', '>', 0)
            ->get(['products.name', 'product_fiscal_data.peso_bruto', 'product_fiscal_data.altura_cm', 'product_fiscal_data.largura_cm', 'product_fiscal_data.profundidade_cm'])
            ->map(function ($p) {
                $real = (int) round((float) $p->peso_bruto * 1000);
                $cubico = (int) round((float) $p->altura_cm * (float) $p->largura_cm * (float) $p->profundidade_cm / 6);
                $peso = $cubico > 5000 ? max($real, $cubico) : $real;
                $lado = (float) max((float) $p->altura_cm, (float) $p->largura_cm, (float) $p->profundidade_cm);

                return ['nome' => $p->name, 'peso' => $peso, 'categoria' => FreteCategoriaPacote::classificar($peso, $lado)];
            })
            ->groupBy('categoria');

        return array_map(fn (array $c) => [
            ...$c,
            'produtos' => isset($produtos[$c['chave']]) ? $produtos[$c['chave']]->count() : 0,
            'exemplos' => isset($produtos[$c['chave']]) ? $produtos[$c['chave']]->sortByDesc('peso')->take(3)->pluck('nome')->values()->all() : [],
        ], FreteCategoriaPacote::todas());
    }
}
