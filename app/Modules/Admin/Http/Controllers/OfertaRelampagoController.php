<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BeautyKora\Models\BeautyKoraProduto;
use App\Modules\Catalog\Models\OfertaRelampago;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CalculadoraOferta;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Support\SiteAtual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Marketing › Ofertas Relâmpago (pedido 2026-10-14): desconto % num produto,
 * de um horário a outro, por empresa (KazaKora, FerraKora, Beauty Kora). A
 * empresa fica na URL (?site=). Só entra produto ligado à empresa (pai ativo
 * na KazaKora; ferrakora_produtos / beautykora_produtos ativos nos outros).
 * Prévia com o lucro estimado da CalculadoraOferta (a mesma da Oferta do
 * Dia): prejuízo só avisa, não bloqueia. Mesmo produto e site com horários
 * sobrepostos é recusado.
 */
class OfertaRelampagoController extends Controller
{
    public function index(Request $request): Response
    {
        $site = $this->site($request);
        $agora = now();

        $ofertas = OfertaRelampago::query()
            ->where('site', $site)
            ->with('product.images')
            ->orderByRaw('CASE WHEN fim_em > ? THEN 0 ELSE 1 END', [$agora])
            ->orderBy('inicio_em')
            ->limit(300)
            ->get()
            ->map(fn (OfertaRelampago $oferta) => [
                'id' => $oferta->id,
                'product_id' => $oferta->product_id,
                'produto' => $oferta->product ? $this->produto($oferta->product, $oferta->desconto_pct) : null,
                'desconto_pct' => $oferta->desconto_pct,
                'inicio_em' => $oferta->inicio_em->format('Y-m-d\TH:i'),
                'fim_em' => $oferta->fim_em->format('Y-m-d\TH:i'),
                'ativo' => $oferta->ativo,
                'situacao' => $oferta->situacao($agora),
            ])
            ->values();

        return Inertia::render('Admin/OfertasRelampago/Index', [
            'site' => $site,
            'sites' => collect(SiteAtual::SITES)->map(fn ($nome, $id) => ['id' => $id, 'nome' => $nome])->values(),
            'ofertas' => $ofertas,
            'agora' => $agora->format('Y-m-d\TH:i'),
        ]);
    }

    /** Busca de produto para o formulário: só os ligados à empresa. */
    public function produtos(Request $request): JsonResponse
    {
        $site = $this->site($request);
        $q = trim((string) $request->query('q', ''));

        $produtos = $this->vinculados($site)
            ->when($q !== '', function (Builder $builder) use ($q) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $builder->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like));
            })
            ->with('images')
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Product $produto) => $this->produto($produto, null))
            ->values();

        return response()->json(['produtos' => $produtos]);
    }

    /** Prévia: preço atual, preço na oferta e lucro estimado por unidade. */
    public function previa(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'product_id' => ['required', 'integer'],
            'desconto_pct' => ['required', 'numeric', 'min:0', 'max:90'],
        ]);

        $produto = Product::query()->with('images')->findOrFail($dados['product_id']);

        return response()->json(['produto' => $this->produto($produto, (float) $dados['desconto_pct'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $site = $this->site($request);
        $dados = $this->validar($request, $site);

        OfertaRelampago::query()->create([...$dados, 'site' => $site, 'ativo' => true, 'user_id' => $request->user()?->id]);

        return redirect()->route('admin.ofertas-relampago.listar', ['site' => $site])->with('success', 'Oferta Relâmpago criada.');
    }

    public function update(Request $request, OfertaRelampago $oferta): RedirectResponse
    {
        $dados = $this->validar($request, $oferta->site, $oferta->id);
        $oferta->update($dados);

        return redirect()->route('admin.ofertas-relampago.listar', ['site' => $oferta->site])->with('success', 'Oferta Relâmpago atualizada.');
    }

    /** Pausar / retomar. */
    public function alternar(OfertaRelampago $oferta): RedirectResponse
    {
        $oferta->update(['ativo' => ! $oferta->ativo]);

        return back()->with('success', $oferta->ativo ? 'Oferta retomada.' : 'Oferta pausada.');
    }

    public function destroy(OfertaRelampago $oferta): RedirectResponse
    {
        $oferta->delete();

        return back()->with('success', 'Oferta excluída.');
    }

    private function site(Request $request): string
    {
        $site = (string) $request->input('site', SiteAtual::KAZAKORA);

        return isset(SiteAtual::SITES[$site]) ? $site : SiteAtual::KAZAKORA;
    }

    /** Pais que podem entrar em oferta na empresa. */
    private function vinculados(string $site): Builder
    {
        $query = Product::query()->whereNull('parent_product_id')->where('is_active', true);

        return match ($site) {
            SiteAtual::FERRAKORA => $query->whereIn('id', FerraKoraProduto::query()->where('ativo', true)->select('product_id')),
            SiteAtual::BEAUTYKORA => $query->whereIn('id', BeautyKoraProduto::query()->where('ativo', true)->select('product_id')),
            default => $query,
        };
    }

    /** @return array{product_id: int, desconto_pct: float, inicio_em: Carbon, fim_em: Carbon} */
    private function validar(Request $request, string $site, ?int $ignorar = null): array
    {
        $dados = $request->validate([
            'product_id' => ['required', 'integer'],
            'desconto_pct' => ['required', 'numeric', 'min:1', 'max:90'],
            'inicio_em' => ['required', 'date'],
            'fim_em' => ['required', 'date', 'after:inicio_em'],
        ], [
            'product_id.required' => 'Escolha o produto.',
            'desconto_pct.min' => 'O desconto vai de 1% a 90%.',
            'desconto_pct.max' => 'O desconto vai de 1% a 90%.',
            'fim_em.after' => 'O fim tem que ser depois do início.',
        ]);

        // Variação escolhida por engano vale o pai (a oferta é do grupo).
        $produto = Product::query()->find($dados['product_id']);
        $paiId = $produto ? ($produto->parent_product_id ?? $produto->id) : 0;

        if (! $this->vinculados($site)->whereKey($paiId)->exists()) {
            throw ValidationException::withMessages(['product_id' => 'Este produto não está ativo na '.SiteAtual::SITES[$site].'.']);
        }

        $inicio = Carbon::parse($dados['inicio_em'])->seconds(0);
        $fim = Carbon::parse($dados['fim_em'])->seconds(0);

        if (OfertaRelampago::query()->sobrepostas($site, $paiId, $inicio, $fim, $ignorar)->exists()) {
            throw ValidationException::withMessages(['inicio_em' => 'Já existe uma Oferta Relâmpago deste produto na '.SiteAtual::SITES[$site].' nesse horário.']);
        }

        return ['product_id' => $paiId, 'desconto_pct' => round((float) $dados['desconto_pct'], 2), 'inicio_em' => $inicio, 'fim_em' => $fim];
    }

    /**
     * Produto para a tela: preço do anúncio sem frete e, com desconto, o
     * preço na oferta e o lucro estimado por unidade (pior caso entre cartão
     * e Pix, CalculadoraOferta). Sem custo cadastrado, lucro null.
     */
    private function produto(Product $produto, ?float $desconto): array
    {
        $atual = $produto->precoSemOferta();
        $naOferta = $desconto !== null ? max(0, round($atual * (1 - $desconto / 100), 2)) : null;
        $temCusto = (float) $produto->cost_price > 0;

        return [
            'id' => $produto->id,
            'nome' => $produto->name,
            'sku' => $produto->sku,
            'foto' => $produto->images->first()?->url,
            'preco_atual' => $atual,
            'preco_oferta' => $naOferta,
            'lucro_atual' => $temCusto ? CalculadoraOferta::lucroPiorCaso($produto, $atual) : null,
            'lucro_oferta' => $temCusto && $naOferta !== null ? CalculadoraOferta::lucroPiorCaso($produto, $naOferta) : null,
        ];
    }
}
