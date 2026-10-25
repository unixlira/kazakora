<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\IbgeMunicipio;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\FreteCategoriaPacote;
use App\Modules\Checkout\Jobs\PreencherTabelaFreteJob;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Checkout\Support\TabelaFreteLocalidades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Logística → Frete por localidade (pedido do Lira em 2026-10-25): a tabela
 * própria de frete (TabelaFreteLocalidades). Mostra o andamento do
 * preenchimento em segundo plano, as 4 categorias de tamanho com a caixa de
 * referência, a busca por cidade/distrito/CEP com o PAC e o SEDEX salvos de
 * cada categoria e a classificação dos produtos.
 */
class FretePorLocalidadeController extends Controller
{
    public function index(): Response
    {
        $categorias = $this->categorias();

        $produtos = Product::query()
            ->whereNull('parent_product_id')
            ->with('fiscalData:id,product_id,peso_bruto,altura_cm,largura_cm,profundidade_cm')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'is_active', 'categoria_frete'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'nome' => $p->name,
                'sku' => $p->sku,
                'ativo' => (bool) $p->is_active,
                'categoria' => $p->categoria_frete,
                'medidas' => self::medidas($p),
                'sugestao' => self::sugestao($p),
            ])
            ->values();

        return Inertia::render('Admin/FretePorLocalidade/Index', [
            'categorias' => $categorias,
            'produtos' => $produtos,
            'resumo' => $this->resumo(),
        ]);
    }

    public function status(): JsonResponse
    {
        return response()->json($this->resumo());
    }

    /** Começa o preenchimento: tudo (localidades + cotação) ou só a cotação pendente. */
    public function atualizar(Request $request): JsonResponse
    {
        PreencherTabelaFreteJob::iniciar($request->boolean('so_cotar') ? [] : null);

        return response()->json($this->resumo());
    }

    /** Muda a caixa de referência: as cotações dela ficam vencidas e a cotação recomeça. */
    public function salvarCategoria(Request $request, string $chave): JsonResponse
    {
        abort_unless(DB::table('frete_categorias_pacote')->where('chave', $chave)->exists(), 404);

        // Validator manual: fora de api/* o app redireciona no erro de validação.
        $validador = Validator::make($request->all(), [
            'peso_g' => ['required', 'integer', 'min:1', 'max:200000'],
            'comprimento_cm' => ['required', 'numeric', 'min:1', 'max:2000'],
            'largura_cm' => ['required', 'numeric', 'min:1', 'max:2000'],
            'altura_cm' => ['required', 'numeric', 'min:1', 'max:2000'],
            'exemplo' => ['nullable', 'string', 'max:120'],
        ]);
        if ($validador->fails()) {
            return response()->json(['errors' => $validador->errors()], 422);
        }

        $dados = $validador->validated();
        DB::table('frete_categorias_pacote')->where('chave', $chave)->update([
            'ref_peso_g' => $dados['peso_g'],
            'ref_comprimento_cm' => $dados['comprimento_cm'],
            'ref_largura_cm' => $dados['largura_cm'],
            'ref_altura_cm' => $dados['altura_cm'],
            'exemplo' => $dados['exemplo'] ?? null,
            'versao' => DB::raw('versao + 1'),
            'updated_at' => now(),
        ]);
        FreteCategoriaPacote::esquecer();
        PreencherTabelaFreteJob::iniciar([]);

        return response()->json(['categorias' => $this->categorias(), 'resumo' => $this->resumo()]);
    }

    public function classificar(Request $request, Product $product): JsonResponse
    {
        $validador = Validator::make($request->all(), [
            'categoria' => ['nullable', 'string', Rule::exists('frete_categorias_pacote', 'chave')],
        ]);
        if ($validador->fails()) {
            return response()->json(['errors' => $validador->errors()], 422);
        }

        $product->forceFill(['categoria_frete' => $request->input('categoria') ?: null])->save();
        FreteEmbutido::esquecerCategorias();

        return response()->json(['id' => $product->id, 'categoria' => $product->categoria_frete, 'categorias' => $this->categorias()]);
    }

    /**
     * Busca por CEP (a localidade dele) ou por nome (cidade ou distrito). O
     * município vem junto com os distritos e povoados dele.
     */
    public function buscar(Request $request, TabelaFreteLocalidades $tabela): JsonResponse
    {
        $termo = trim((string) $request->query('q'));
        $cep = preg_replace('/\D/', '', $termo);
        $uf = strtoupper((string) $request->query('uf'));

        if (strlen($cep) === 8) {
            $achada = $tabela->localidadeDoCep($cep);
            $ids = $achada ? [$achada->id] : [];
        } elseif (mb_strlen($termo) >= 3) {
            $ids = DB::table('frete_localidades')
                ->where('nome_normalizado', 'like', '%'.IbgeMunicipio::normalizar($termo).'%')
                ->when(strlen($uf) === 2, fn ($q) => $q->where('uf', $uf))
                ->orderByRaw("case tipo when 'M' then 0 when 'D' then 1 else 2 end")
                ->orderBy('nome')
                ->limit(15)
                ->pluck('id')
                ->all();
        } else {
            return response()->json(['message' => 'Digite um CEP ou pelo menos 3 letras.'], 422);
        }

        $localidades = DB::table('frete_localidades')->whereIn('id', $ids)->get();

        // Município encontrado: traz os distritos e povoados dele; distrito: traz o município.
        $superiores = $localidades->pluck('superior_numero')->filter();
        $municipios = $localidades->where('tipo', 'M')->pluck('numero_correios');
        $todas = DB::table('frete_localidades')
            ->whereIn('id', $ids)
            ->orWhereIn('numero_correios', $superiores)
            ->orWhereIn('superior_numero', $municipios)
            ->orderBy('uf')
            ->orderByRaw('coalesce(municipio_nome, nome)')
            ->orderByRaw("case tipo when 'M' then 0 when 'D' then 1 else 2 end")
            ->orderBy('nome')
            ->limit(200)
            ->get();

        $cotacoes = DB::table('frete_tabela')
            ->whereIn('cep_destino', $todas->pluck('cep_cotacao')->filter()->unique())
            ->where('cep_origem', $tabela->origem())
            ->get()
            ->groupBy('cep_destino');

        return response()->json([
            'achadas' => $ids,
            'localidades' => $todas->map(fn ($l) => [
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
            ])->values(),
        ]);
    }

    private function categorias(): array
    {
        $produtos = DB::table('products')->whereNull('parent_product_id')->whereNotNull('categoria_frete')
            ->groupBy('categoria_frete')->selectRaw('categoria_frete, count(*) as n')->pluck('n', 'categoria_frete');

        return DB::table('frete_categorias_pacote')->orderBy('ordem')->get()->map(fn ($c) => [
            'chave' => $c->chave,
            'nome' => $c->nome,
            'peso_g' => $c->ref_peso_g !== null ? (int) $c->ref_peso_g : null,
            'comprimento_cm' => $c->ref_comprimento_cm !== null ? (float) $c->ref_comprimento_cm : null,
            'largura_cm' => $c->ref_largura_cm !== null ? (float) $c->ref_largura_cm : null,
            'altura_cm' => $c->ref_altura_cm !== null ? (float) $c->ref_altura_cm : null,
            'exemplo' => $c->exemplo,
            'limite' => ['peso_max_g' => $c->peso_max_g, 'lado_max_cm' => $c->lado_max_cm],
            'fora_do_limite' => $c->ref_peso_g !== null ? TabelaFreteLocalidades::foraDoLimite($c) : 'Sem caixa de referência: não é cotada',
            'produtos' => (int) ($produtos[$c->chave] ?? 0),
        ])->all();
    }

    private function resumo(): array
    {
        $tabela = app(TabelaFreteLocalidades::class);
        $tipos = DB::table('frete_localidades')->groupBy('tipo')->selectRaw('tipo, count(*) as n')->pluck('n', 'tipo');

        return [
            'localidades' => ['M' => (int) ($tipos['M'] ?? 0), 'D' => (int) ($tipos['D'] ?? 0), 'P' => (int) ($tipos['P'] ?? 0)],
            ...$tabela->contagem(),
            'com_erro' => DB::table('frete_tabela')->whereNull('pac_preco')->whereNull('sedex_preco')->distinct()->count('cep_destino'),
            'ultima_cotacao' => DB::table('frete_tabela')->max('cotado_em'),
            'origem' => $tabela->origem(),
            'job' => PreencherTabelaFreteJob::status(),
        ];
    }

    private static function medidas(Product $p): ?array
    {
        $f = $p->fiscalData;
        if (! $f || ! $f->peso_bruto) {
            return null;
        }

        return [
            'peso_g' => (int) round((float) $f->peso_bruto * 1000),
            'lados' => [(float) $f->profundidade_cm, (float) $f->largura_cm, (float) $f->altura_cm],
        ];
    }

    /** Categoria pelo peso tarifável e pelo maior lado (a mesma das regras por cidade). */
    private static function sugestao(Product $p): ?string
    {
        if (! $p->fiscalData?->peso_bruto) {
            return null;
        }
        $frete = app(FreteEmbutido::class);

        return FreteCategoriaPacote::classificar($frete->pesoTarifavel($p), $frete->maiorLado($p));
    }
}
