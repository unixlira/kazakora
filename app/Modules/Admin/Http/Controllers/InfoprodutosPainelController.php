<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\Publicidade\CampanhasMetaPorLoja;
use App\Modules\Admin\Support\Publicidade\CriativosMeta;
use App\Modules\Infoprodutos\Jobs\GerarPrintDaPagina;
use App\Modules\Infoprodutos\Models\InfoprodutoEvento;
use App\Modules\Infoprodutos\Models\InfoprodutoVenda;
use App\Modules\Infoprodutos\Support\Comportamento;
use App\Modules\Infoprodutos\Support\FunilInfoprodutos;
use App\Modules\Infoprodutos\Support\PrintDaPagina;
use App\Modules\Notifications\Push\AppsDeVendas;
use App\Modules\Notifications\Push\MetricasDaLoja;
use App\Services\Meta\MetaAdsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Publicidade › Infoprodutos, Criativos e Comportamento (pedido do Lira em
 * 2026-11-19). Só leitura.
 *
 * - Infoprodutos: funil por produto (FunilInfoprodutos) com o gasto da conta
 *   Meta GreenKora por anúncio, e o resumo da KazaKora e da FerraKora pela
 *   mesma conta da tela principal dos apps de vendas (MetricasDaLoja: pedidos,
 *   faturamento, lucro do ContributionMargin, visitas, Meta). Nada de lucro
 *   recalculado aqui.
 * - Criativos: anúncio a anúncio das 3 contas (CriativosMeta).
 * - Comportamento: mapa de cliques, rolagem, sequências e visitas (Comportamento).
 */
class InfoprodutosPainelController extends Controller
{
    public function index(Request $request): Response
    {
        FunilInfoprodutos::$comTestes = $request->boolean('testes');
        $periodo = FunilInfoprodutos::periodo($request->query('periodo'));
        $produtos = (array) config('infoprodutos.produtos');
        $produto = array_key_exists((string) $request->query('produto'), $produtos) ? (string) $request->query('produto') : FunilInfoprodutos::TODOS;

        return Inertia::render('Admin/Infoprodutos/Index', [
            'periodo' => $periodo,
            'periodos' => FunilInfoprodutos::PERIODOS,
            'produto' => $produto,
            'produtos' => [FunilInfoprodutos::TODOS => 'Todos os infoprodutos'] + $produtos,
            'metaConfigurada' => filled(config('infoprodutos.meta.access_token')),
            'funil' => Inertia::defer(fn () => $this->funil($produto, $periodo)),
            'lojas' => Inertia::defer(fn () => $this->lojas($periodo), 'lojas'),
        ]);
    }

    /** @return array<string, mixed> */
    private function funil(string $produto, string $periodo): array
    {
        [$de, $ate] = FunilInfoprodutos::intervalo($periodo);
        [$gasto, $moeda, $erroMeta] = $this->gastoGreenKora($de, $ate);

        return app(FunilInfoprodutos::class)->montar($produto, $de, $ate, $gasto, $moeda) + [
            'moeda_meta' => $moeda,
            'erro_meta' => $erroMeta,
            'gasto_total' => round(array_sum(array_map(fn ($g) => $g['gasto'], $gasto)), 2),
            // Primeiro degrau do funil visual: cliques no link dos anúncios (conta inteira).
            'cliques_anuncio' => $produto === FunilInfoprodutos::TODOS ? array_sum(array_map(fn ($g) => $g['cliques'] ?? 0, $gasto)) : null,
            'de' => $de->toIso8601String(),
            'ate' => $ate->toIso8601String(),
        ];
    }

    /**
     * Gasto por anúncio da conta GreenKora no período, pela chave que os
     * links usam no utm_content (id do anúncio, se algum utm_content for um
     * id; senão o nome do anúncio).
     *
     * @return array{0: array<string, array{gasto: float, nome: string}>, 1: ?string, 2: ?string}
     */
    private function gastoGreenKora(Carbon $de, Carbon $ate): array
    {
        if (blank(config('infoprodutos.meta.access_token'))) {
            return [[], null, null];
        }
        $meta = app(CriativosMeta::class)->servico(CriativosMeta::GREENKORA);
        try {
            [$linhas, $moeda] = Cache::remember('infoprodutos:gasto-gk:'.$de->toDateString().':'.$ate->toDateString(), now()->addMinutes(10),
                fn () => [$meta->desempenhoDiarioPorAnuncio($de, $ate), $meta->moedaDaConta()]);
        } catch (MetaAdsException $e) {
            return [[], null, $e->getMessage()];
        }

        $utms = InfoprodutoEvento::query()->where('tipo', 'pageview')->whereNotNull('utm_content')
            ->where('created_at', '>=', $de)->distinct()->pluck('utm_content')
            ->merge(InfoprodutoVenda::query()->whereNotNull('utm_content')->where('created_at', '>=', $de)->distinct()->pluck('utm_content'))
            ->flip();

        $gasto = [];
        foreach ($linhas as $l) {
            $chave = isset($utms[$l['anuncio_meta_id']]) ? $l['anuncio_meta_id'] : $l['anuncio_nome'];
            $gasto[$chave]['gasto'] = round(($gasto[$chave]['gasto'] ?? 0) + (float) $l['gasto'], 2);
            $gasto[$chave]['nome'] = $l['anuncio_nome'];
            $gasto[$chave]['cliques'] = ($gasto[$chave]['cliques'] ?? 0) + (int) $l['cliques_link'];
        }

        return [$gasto, $moeda, null];
    }

    /** Resumo das lojas: o mesmo da tela principal dos apps de vendas. */
    private function lojas(string $periodo): array
    {
        $saida = [];
        foreach ([AppsDeVendas::KAZAKORA, AppsDeVendas::FERRAKORA] as $app) {
            try {
                $m = app(MetricasDaLoja::class)->montar($app, $periodo);
                $saida[] = ['app' => $app, 'loja' => $m['loja'], 'erro' => null] + array_intersect_key($m, array_flip(
                    ['faturamento', 'pedidos', 'ticket_medio', 'lucro', 'visitas', 'conversao', 'meta']));
            } catch (Throwable $e) {
                report($e);
                $saida[] = ['app' => $app, 'loja' => $app, 'erro' => 'Não foi possível montar o resumo agora.'];
            }
        }

        return $saida;
    }

    public function criativos(Request $request, CriativosMeta $criativos): Response
    {
        $contas = $criativos->contas();
        $conta = array_key_exists((string) $request->query('conta'), $contas) ? (string) $request->query('conta') : (array_key_first($contas) ?? CampanhasMetaPorLoja::KAZAKORA);
        $periodo = CampanhasMetaPorLoja::periodo($request->query('periodo'));
        $campanha = $request->filled('campanha') && preg_match('/^\d{1,30}$/', (string) $request->query('campanha')) ? (string) $request->query('campanha') : null;

        return Inertia::render('Admin/Publicidade/Criativos', [
            'contas' => $contas,
            'conta' => $conta,
            'periodo' => $periodo,
            'periodos' => CampanhasMetaPorLoja::PERIODOS,
            'campanha' => $campanha,
            'selos' => CriativosMeta::SELOS,
            'dados' => Inertia::defer(fn () => $criativos->montar($conta, $periodo, $campanha)),
        ]);
    }

    public function comportamento(Request $request, Comportamento $comportamento): Response
    {
        Comportamento::$comTestes = $request->boolean('testes');
        $periodo = FunilInfoprodutos::periodo($request->query('periodo'));
        [$de, $ate] = FunilInfoprodutos::intervalo($periodo);
        $aparelho = $request->query('aparelho') === Comportamento::COMPUTADOR ? Comportamento::COMPUTADOR : Comportamento::CELULAR;
        $paginas = $comportamento->paginas($de, $ate);
        $loja = in_array($request->query('loja'), ['kazakora', 'ferrakora', 'infoprodutos'], true) ? (string) $request->query('loja') : null;

        $escolhida = collect($paginas)->first(fn ($p) => $p['produto'] === $request->query('produto') && $p['pagina'] === ($request->query('pagina') ?: $p['pagina']))
            ?? collect($paginas)->first(fn ($p) => $loja === null || $p['loja'] === $loja);

        return Inertia::render('Admin/Infoprodutos/Comportamento', [
            'periodo' => $periodo,
            'periodos' => FunilInfoprodutos::PERIODOS,
            'aparelho' => $aparelho,
            'loja' => $loja ?? ($escolhida['loja'] ?? 'kazakora'),
            'paginas' => $paginas,
            'pagina' => $escolhida,
            'print' => $escolhida ? $this->infoDoPrint($escolhida['produto'], $escolhida['pagina'], $aparelho) : null,
            'dados' => Inertia::defer(fn () => $escolhida
                ? $comportamento->montar($escolhida['produto'], $escolhida['pagina'], $aparelho, $de, $ate)
                : null),
        ]);
    }

    public function visita(Request $request, Comportamento $comportamento): JsonResponse
    {
        $dados = $request->validate([
            'sessao' => ['required', 'regex:/^[A-Za-z0-9]{16,32}$/'],
            'produto' => ['required', 'string', 'max:130'],
        ]);

        return response()->json(['passos' => $comportamento->visita($dados['sessao'], $dados['produto'])]);
    }

    public function print(Request $request): BinaryFileResponse
    {
        $produto = (string) $request->query('produto');
        $pagina = (string) $request->query('pagina');
        $aparelho = $request->query('aparelho') === Comportamento::COMPUTADOR ? Comportamento::COMPUTADOR : Comportamento::CELULAR;
        $arquivo = PrintDaPagina::arquivo($produto, $pagina, $aparelho).'.webp';
        abort_unless(is_file($arquivo), 404);

        return response()->file($arquivo, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=300']);
    }

    /**
     * Situação do print da página escolhida. Sem print, pede um na fila
     * (GerarPrintDaPagina) e a tela consulta de novo até ele chegar.
     */
    public function printInfo(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'produto' => ['required', 'string', 'max:130', 'regex:/^[a-z0-9\/_-]+$/'],
            'pagina' => ['required', 'string', 'max:20', 'regex:/^[a-z0-9-]+$/'],
            'aparelho' => ['nullable', 'in:celular,computador'],
        ]);
        $aparelho = ($dados['aparelho'] ?? null) === Comportamento::COMPUTADOR ? Comportamento::COMPUTADOR : Comportamento::CELULAR;

        if ($info = $this->infoDoPrint($dados['produto'], $dados['pagina'], $aparelho)) {
            return response()->json(['situacao' => 'pronto', 'print' => $info]);
        }
        if (PrintDaPagina::url($dados['produto'], $dados['pagina']) === null) {
            return response()->json(['situacao' => 'sem_endereco', 'mensagem' => 'Esta página não tem endereço conhecido para tirar o print.']);
        }
        $chaveErro = GerarPrintDaPagina::chaveDoErro($dados['produto'], $dados['pagina'], $aparelho);
        if (($erro = Cache::get($chaveErro)) && ! $request->boolean('de_novo')) {
            return response()->json(['situacao' => 'erro', 'mensagem' => "Não deu para tirar o print: {$erro}"]);
        }
        Cache::forget($chaveErro);
        GerarPrintDaPagina::dispatch($dados['produto'], $dados['pagina'], $aparelho);

        return response()->json(['situacao' => 'gerando']);
    }

    /** @return array<string, mixed>|null */
    private function infoDoPrint(string $produto, string $pagina, string $aparelho): ?array
    {
        $base = PrintDaPagina::arquivo($produto, $pagina, $aparelho);
        if (! is_file($base.'.json') || ! is_file($base.'.webp')) {
            return null;
        }
        $info = json_decode((string) file_get_contents($base.'.json'), true) ?: [];

        return [
            'url' => route('admin.publicidade.comportamento.print', ['produto' => $produto, 'pagina' => $pagina, 'aparelho' => $aparelho, 'v' => md5((string) ($info['em'] ?? ''))], false),
            'largura' => (int) ($info['largura'] ?? PrintDaPagina::LARGURAS[$aparelho]),
            'altura' => (int) ($info['altura'] ?? 0),
            'secoes' => $info['secoes'] ?? [],
            'em' => $info['em'] ?? null,
            'pagina_url' => $info['url'] ?? null,
        ];
    }
}
