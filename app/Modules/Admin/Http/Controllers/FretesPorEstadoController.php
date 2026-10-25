<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\IbgeEstado;
use App\Models\IbgeMunicipio;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\FreteRegiao;
use App\Modules\Checkout\Services\CorreiosTabelaPorEstado;
use App\Modules\Checkout\Services\MelhorEnvioTabelaPorEstado;
use App\Modules\Checkout\Support\CompletarEnderecoPeloCep;
use App\Modules\Checkout\Support\DistritosDoMunicipio;
use App\Modules\Checkout\Support\RegrasDeFrete;
use App\Services\MelhorEnvio\MelhorEnvioAuthService;
use App\Support\SiteAtual;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Logística → Fretes por estado (pedido do Lira em 2026-10-12): PAC e SEDEX
 * dos Correios para as 27 capitais, com o preço do contrato da KazaKora, para
 * um pacote escolhido (produto cadastrado ou medidas digitadas). Só consulta:
 * não cria postagem nem muda frete do site.
 *
 * Duas abas (2026-10-12): Correios (contrato próprio) e Melhor Envio (todas
 * as transportadoras da conta conectada), para comparar e decidir o frete
 * grátis. O pacote é o mesmo nas duas.
 *
 * Frete por cidade (2026-10-24): coluna Cidades abre as cidades do estado
 * (tabela ibge_municipios) e cada uma pode ter uma regra (frete_regioes com
 * um local de município) que o FreteEmbutido aplica na loja. Por enquanto só
 * KazaKora; FerraKora e Beauty Kora vêm depois (campo site).
 *
 * Frete por distrito (2026-10-25): cada cidade abre os distritos dela (IBGE +
 * CEP descoberto no ViaCEP) e cada distrito pode ter regra própria, com CEP
 * ou faixa de CEP. A regra do distrito ganha da regra da cidade.
 */
class FretesPorEstadoController extends Controller
{
    /** Pacote inicial da tela: uma caixa pequena típica da loja. */
    private const PACOTE_PADRAO = ['peso_g' => 500, 'comprimento' => 20, 'largura' => 15, 'altura' => 10, 'valor' => 50];

    public function index(MelhorEnvioAuthService $melhorEnvio): Response
    {
        $produtos = Product::query()
            ->where('is_active', true)
            ->whereHas('fiscalData', fn ($q) => $q->where('peso_bruto', '>', 0)
                ->where('altura_cm', '>', 0)->where('largura_cm', '>', 0)->where('profundidade_cm', '>', 0))
            ->with('fiscalData:id,product_id,peso_bruto,altura_cm,largura_cm,profundidade_cm')
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'price'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'nome' => $p->name,
                'sku' => $p->sku,
                'pacote' => self::pacoteDoProduto($p),
            ])
            ->values();

        return Inertia::render('Admin/FretesPorEstado/Index', [
            'produtos' => $produtos,
            'pacotePadrao' => self::PACOTE_PADRAO,
            'melhorEnvio' => [
                'conectado' => (bool) $melhorEnvio->currentToken(),
                'ambiente' => MelhorEnvioTabelaPorEstado::ambiente(),
            ],
            // Cidades com regra própria ou com algum distrito com regra.
            'cidadesComRegra' => self::regrasDoSite(SiteAtual::KAZAKORA)
                ->join('ibge_municipios', 'ibge_municipios.id', '=', 'frete_regiao_locais.municipio_id')
                ->groupBy('ibge_municipios.uf')
                ->selectRaw('ibge_municipios.uf as uf, count(distinct frete_regiao_locais.municipio_id) as n')
                ->pluck('n', 'uf')
                ->map(fn ($n) => (int) $n),
        ]);
    }

    /** Cidades do estado, em ordem alfabética, com a regra de frete de cada uma. */
    public function cidades(string $uf): JsonResponse
    {
        $estado = IbgeEstado::query()->where('sigla', strtoupper($uf))->first();
        abort_unless($estado, 404);

        $regras = self::regrasDeCidade(SiteAtual::KAZAKORA)
            ->whereIn('frete_regiao_locais.municipio_id', IbgeMunicipio::query()->where('estado_id', $estado->id)->select('id'))
            ->get(['frete_regiao_locais.municipio_id', 'frete_regioes.*'])
            ->keyBy('municipio_id');

        $distritos = self::regrasDeDistrito(SiteAtual::KAZAKORA)
            ->whereIn('frete_regiao_locais.municipio_id', IbgeMunicipio::query()->where('estado_id', $estado->id)->select('id'))
            ->groupBy('frete_regiao_locais.municipio_id')
            ->selectRaw('frete_regiao_locais.municipio_id, count(*) as n')
            ->pluck('n', 'municipio_id');

        return response()->json([
            'uf' => $estado->sigla,
            'estado' => $estado->nome,
            'cidades' => IbgeMunicipio::query()->where('estado_id', $estado->id)->orderBy('nome_normalizado')->get(['id', 'nome'])
                ->map(fn (IbgeMunicipio $m) => [
                    'id' => $m->id,
                    'nome' => $m->nome,
                    'regra' => self::regraParaTela($regras[$m->id] ?? null),
                    'distritos_com_regra' => (int) ($distritos[$m->id] ?? 0),
                ])
                ->values(),
        ]);
    }

    /**
     * Distritos da cidade (IBGE + CEPs do ViaCEP) com a regra de cada um. Os
     * distritos com regra que não estão na lista (digitados à mão) entram também.
     */
    public function distritos(int $municipio, DistritosDoMunicipio $busca): JsonResponse
    {
        $cidade = IbgeMunicipio::query()->findOrFail($municipio);
        $achados = $busca->buscar($cidade);

        $regras = self::regrasDeDistrito(SiteAtual::KAZAKORA)
            ->where('frete_regiao_locais.municipio_id', $cidade->id)
            ->get(['frete_regiao_locais.distrito', 'frete_regiao_locais.distrito_id', 'frete_regiao_locais.cep_inicio', 'frete_regiao_locais.cep_fim', 'frete_regioes.*'])
            ->keyBy(fn ($r) => IbgeMunicipio::normalizar($r->distrito));

        $lista = collect($achados['distritos'])->map(function ($d) use ($regras) {
            $regra = $regras->pull(IbgeMunicipio::normalizar($d['nome']));

            return ['id' => $d['id'], 'nome' => $d['nome'], 'cep_sugerido' => $d['cep'], 'regra' => self::regraDeDistritoParaTela($regra)];
        });
        foreach ($regras as $regra) {
            $lista->push(['id' => $regra->distrito_id ? (int) $regra->distrito_id : null, 'nome' => $regra->distrito, 'cep_sugerido' => null, 'regra' => self::regraDeDistritoParaTela($regra)]);
        }

        $regraCidade = self::regrasDeCidade(SiteAtual::KAZAKORA)
            ->where('frete_regiao_locais.municipio_id', $cidade->id)
            ->first(['frete_regioes.*']);

        return response()->json([
            'cidade' => ['id' => $cidade->id, 'nome' => $cidade->nome, 'uf' => $cidade->uf, 'regra' => self::regraParaTela($regraCidade)],
            'cep_geral' => $achados['cep_geral'],
            'distritos' => $lista->sortBy(fn ($d) => IbgeMunicipio::normalizar($d['nome']))->values(),
        ]);
    }

    /**
     * Cria ou troca a regra de um distrito da cidade. O CEP (ou a faixa) é
     * conferido no ViaCEP: CEP de outra cidade é recusado.
     */
    public function salvarDistrito(Request $request, int $municipio, CompletarEnderecoPeloCep $viaCep): JsonResponse
    {
        $cidade = IbgeMunicipio::query()->findOrFail($municipio);
        $request->merge([
            'cep_inicio' => preg_replace('/\D/', '', (string) $request->input('cep_inicio')) ?: null,
            'cep_fim' => preg_replace('/\D/', '', (string) $request->input('cep_fim')) ?: null,
        ]);

        $validador = Validator::make($request->all(), [
            'distrito' => ['required', 'string', 'min:2', 'max:120'],
            'distrito_id' => ['nullable', 'integer'],
            'cep_inicio' => ['nullable', 'digits:8'],
            'cep_fim' => ['nullable', 'digits:8'],
            'tipo_preco' => ['required', Rule::in(FreteRegiao::TIPOS)],
            'valor' => ['required', 'numeric', 'min:0', 'max:1000'],
            'prazo_dias' => ['nullable', 'integer', 'min:1', 'max:60'],
            'ativo' => ['boolean'],
        ], [
            'distrito.required' => 'Diga o nome do distrito.',
            'cep_inicio.digits' => 'O CEP tem 8 números.',
            'cep_fim.digits' => 'O CEP final tem 8 números.',
            'tipo_preco.in' => 'Escolha o tipo da regra.',
            'valor.max' => 'O valor pode ir até R$ 1.000.',
        ]);
        if ($validador->fails()) {
            return response()->json(['message' => 'Regra inválida.', 'errors' => $validador->errors()], 422);
        }
        $dados = $validador->validated();
        $nome = trim($dados['distrito']);
        $cepInicio = $dados['cep_inicio'] ?? null;
        $cepFim = $dados['cep_fim'] ?? null;
        if ($cepFim && ! $cepInicio) {
            return response()->json(['message' => 'Regra inválida.', 'errors' => ['cep_inicio' => ['Diga o CEP inicial da faixa.']]], 422);
        }
        if ($cepFim && $cepFim < $cepInicio) {
            return response()->json(['message' => 'Regra inválida.', 'errors' => ['cep_fim' => ['O CEP final tem que ser maior que o inicial.']]], 422);
        }

        foreach (array_filter([$cepInicio, $cepFim]) as $cep) {
            $doCep = $viaCep->buscar($cep);
            if ($doCep && (int) ($doCep['ibge'] ?? 0) !== $cidade->id) {
                $formatado = substr($cep, 0, 5).'-'.substr($cep, 5);

                return response()->json(['message' => 'Regra inválida.', 'errors' => ['cep_inicio' => [
                    "O CEP {$formatado} é de ".trim(($doCep['localidade'] ?? '?').' - '.($doCep['uf'] ?? '')).", não de {$cidade->nome}.",
                ]]], 422);
            }
        }

        $regra = DB::transaction(function () use ($cidade, $dados, $nome, $cepInicio, $cepFim) {
            $existente = self::regrasDeDistrito(SiteAtual::KAZAKORA)
                ->where('frete_regiao_locais.municipio_id', $cidade->id)
                ->get(['frete_regiao_locais.distrito', 'frete_regioes.id'])
                ->first(fn ($r) => IbgeMunicipio::normalizar($r->distrito) === IbgeMunicipio::normalizar($nome));
            $regra = $existente ? FreteRegiao::query()->find($existente->id) : new FreteRegiao(['site' => SiteAtual::KAZAKORA]);

            $regra->fill([
                'nome' => "{$nome} ({$cidade->nome} - {$cidade->uf})",
                'tipo_preco' => $dados['tipo_preco'],
                'valor' => round((float) $dados['valor'], 2),
                'prazo_dias' => $dados['prazo_dias'] ?? null,
                'ativo' => (bool) ($dados['ativo'] ?? true),
            ])->save();

            $regra->locais()->delete();
            $regra->locais()->create([
                'municipio_id' => $cidade->id,
                'distrito_id' => $dados['distrito_id'] ?? null,
                'distrito' => $nome,
                'cep_inicio' => $cepInicio,
                'cep_fim' => $cepInicio ? ($cepFim ?: $cepInicio) : null,
            ]);

            return $regra;
        });
        RegrasDeFrete::esquecer();

        $salva = self::regrasDeDistrito(SiteAtual::KAZAKORA)
            ->where('frete_regioes.id', $regra->id)
            ->first(['frete_regiao_locais.distrito', 'frete_regiao_locais.cep_inicio', 'frete_regiao_locais.cep_fim', 'frete_regioes.*']);

        return response()->json(['regra' => self::regraDeDistritoParaTela($salva)]);
    }

    /** Tira a regra do distrito: ele volta para a regra da cidade (ou os Correios). */
    public function removerDistrito(int $municipio, int $regra): JsonResponse
    {
        $ids = self::regrasDeDistrito(SiteAtual::KAZAKORA)
            ->where('frete_regiao_locais.municipio_id', $municipio)
            ->where('frete_regioes.id', $regra)
            ->pluck('frete_regioes.id');
        abort_if($ids->isEmpty(), 404);
        FreteRegiao::query()->whereIn('id', $ids)->delete();
        RegrasDeFrete::esquecer();

        return response()->json(['regra' => null]);
    }

    /** Cria ou troca a regra de frete de uma cidade. */
    public function salvarCidade(Request $request, int $municipio): JsonResponse
    {
        $cidade = IbgeMunicipio::query()->findOrFail($municipio);

        $validador = Validator::make($request->all(), [
            'tipo_preco' => ['required', Rule::in(FreteRegiao::TIPOS)],
            'valor' => ['required', 'numeric', 'min:0', 'max:1000'],
            'prazo_dias' => ['nullable', 'integer', 'min:1', 'max:60'],
            'ativo' => ['boolean'],
        ], [
            'tipo_preco.in' => 'Escolha o tipo da regra.',
            'valor.max' => 'O valor pode ir até R$ 1.000.',
        ]);
        if ($validador->fails()) {
            return response()->json(['message' => 'Regra inválida.', 'errors' => $validador->errors()], 422);
        }
        $dados = $validador->validated();

        $regra = DB::transaction(function () use ($cidade, $dados) {
            $regra = self::regrasDeCidade(SiteAtual::KAZAKORA)
                ->where('frete_regiao_locais.municipio_id', $cidade->id)
                ->first(['frete_regioes.*']);
            $regra = $regra ? FreteRegiao::query()->find($regra->id) : new FreteRegiao(['site' => SiteAtual::KAZAKORA]);

            $regra->fill([
                'nome' => "{$cidade->nome} - {$cidade->uf}",
                'tipo_preco' => $dados['tipo_preco'],
                'valor' => round((float) $dados['valor'], 2),
                'prazo_dias' => $dados['prazo_dias'] ?? null,
                'ativo' => (bool) ($dados['ativo'] ?? true),
            ])->save();

            if (! $regra->locais()->exists()) {
                $regra->locais()->create(['municipio_id' => $cidade->id]);
            }

            return $regra;
        });
        RegrasDeFrete::esquecer();

        return response()->json(['regra' => self::regraParaTela($regra)]);
    }

    /** Tira a regra da cidade: ela volta para o frete normal dos Correios. */
    public function removerCidade(int $municipio): JsonResponse
    {
        $ids = self::regrasDeCidade(SiteAtual::KAZAKORA)
            ->where('frete_regiao_locais.municipio_id', $municipio)
            ->pluck('frete_regioes.id');
        FreteRegiao::query()->whereIn('id', $ids)->delete();
        RegrasDeFrete::esquecer();

        return response()->json(['regra' => null]);
    }

    /**
     * Regras de uma cidade só (um local, de município) do site: as que esta
     * tela cria. Regras com vários locais ou por UF/CEP ficam de fora.
     */
    private static function regrasDeCidade(string $site): Builder
    {
        return self::regrasDoSite($site)->whereNull('frete_regiao_locais.distrito');
    }

    /** Regras de um distrito só (um local, município + distrito) do site. */
    private static function regrasDeDistrito(string $site): Builder
    {
        return self::regrasDoSite($site)->whereNotNull('frete_regiao_locais.distrito');
    }

    /** Regras de um local só, de município (com ou sem distrito), do site. */
    private static function regrasDoSite(string $site): Builder
    {
        return DB::table('frete_regiao_locais')
            ->join('frete_regioes', 'frete_regioes.id', '=', 'frete_regiao_locais.frete_regiao_id')
            ->where('frete_regioes.site', $site)
            ->whereNotNull('frete_regiao_locais.municipio_id')
            ->whereNotExists(fn ($q) => $q->from('frete_regiao_locais as outro')
                ->whereColumn('outro.frete_regiao_id', 'frete_regiao_locais.frete_regiao_id')
                ->whereColumn('outro.id', '!=', 'frete_regiao_locais.id'));
    }

    private static function regraDeDistritoParaTela(?object $regra): ?array
    {
        return $regra ? [
            ...self::regraParaTela($regra),
            'cep_inicio' => $regra->cep_inicio,
            'cep_fim' => $regra->cep_fim !== $regra->cep_inicio ? $regra->cep_fim : null,
        ] : null;
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

    public function cotar(Request $request, CorreiosTabelaPorEstado $tabela): JsonResponse
    {
        $pacote = $this->pacote($request);

        return $pacote instanceof JsonResponse ? $pacote : response()->json($tabela->cotar($pacote));
    }

    public function cotarMelhorEnvio(Request $request, MelhorEnvioTabelaPorEstado $tabela): JsonResponse
    {
        $pacote = $this->pacote($request);

        return $pacote instanceof JsonResponse ? $pacote : response()->json($tabela->cotar($pacote));
    }

    /** Pacote validado, ou a resposta 422 para a tela. */
    private function pacote(Request $request): array|JsonResponse
    {
        // Limites do PAC/SEDEX para "Pacote": até 30 kg, 16x11x2 cm no mínimo
        // e 100 cm por lado no máximo. Validator manual: fora de api/* o app
        // redireciona no erro de validação (bootstrap/app.php), e estas rotas
        // respondem JSON para a tela.
        $validador = Validator::make($request->all(), [
            'peso_g' => ['required', 'integer', 'min:1', 'max:30000'],
            'comprimento' => ['required', 'numeric', 'min:16', 'max:100'],
            'largura' => ['required', 'numeric', 'min:11', 'max:100'],
            'altura' => ['required', 'numeric', 'min:2', 'max:100'],
            'valor' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ], [
            'peso_g.max' => 'O peso máximo do PAC e do SEDEX é 30 kg (30.000 g).',
            'comprimento.min' => 'O comprimento mínimo dos Correios é 16 cm.',
            'largura.min' => 'A largura mínima dos Correios é 11 cm.',
            'altura.min' => 'A altura mínima dos Correios é 2 cm.',
            'comprimento.max' => 'Cada lado pode ter no máximo 100 cm.',
            'largura.max' => 'Cada lado pode ter no máximo 100 cm.',
            'altura.max' => 'Cada lado pode ter no máximo 100 cm.',
        ]);

        if ($validador->fails()) {
            return response()->json(['message' => 'Medidas inválidas.', 'errors' => $validador->errors()], 422);
        }
        $dados = $validador->validated();

        return [
            'peso_g' => (int) $dados['peso_g'],
            'comprimento' => (float) $dados['comprimento'],
            'largura' => (float) $dados['largura'],
            'altura' => (float) $dados['altura'],
            'valor' => (float) ($dados['valor'] ?? 0),
        ];
    }

    /** Mesma regra do checkout (CorreiosFreightQuoteService): mínimos de 16x11x2 cm. */
    private static function pacoteDoProduto(Product $p): array
    {
        $f = $p->fiscalData;

        return [
            'peso_g' => max(1, (int) round((float) $f->peso_bruto * 1000)),
            'comprimento' => max(16.0, (float) $f->profundidade_cm),
            'largura' => max(11.0, (float) $f->largura_cm),
            'altura' => max(2.0, (float) $f->altura_cm),
            // Valor declarado (seguro do Melhor Envio) = preço do produto.
            'valor' => round((float) $p->price, 2),
        ];
    }
}
