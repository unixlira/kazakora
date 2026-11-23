<?php

namespace App\Modules\Infoprodutos\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\Infoprodutos\Models\InfoprodutoEvento;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Páginas que a tela Comportamento pode mostrar (2026-11-20): para os prints
 * já estarem prontos antes de o Lira abrir a página.
 *
 * - KazaKora: todo produto pai ativo da loja (kz/<slug>);
 * - FerraKora: todo produto publicado no site (fk/<slug do site>);
 * - Infoprodutos: a landing de cada produto da config e as ofertas que já
 *   tiveram visita;
 * - as outras páginas das lojas (kzp/fkp: início, checkout, entrar...) NÃO
 *   entram desde 2026-11-23: o mapa de cliques só faz sentido no produto.
 *
 * assinatura() muda quando a página muda: nas lojas pelo updated_at do
 * produto (e das variações); nas landings pelo hash do HTML (as páginas da
 * loja mudam o HTML a cada acesso, então o hash não serve para elas).
 */
class PaginasMedidas
{
    /** @var Collection<string, FerraKoraProduto>|null slug do site => registro */
    private ?Collection $ferrakora = null;

    /** @return list<array{produto: string, pagina: string}> */
    public function todas(): array
    {
        $kz = Product::query()->naKazaKora()->whereNull('parent_product_id')->orderBy('id')->pluck('slug')
            ->filter()->map(fn ($slug) => ['produto' => 'kz/'.$slug, 'pagina' => 'produto']);
        $fk = $this->ferrakora()->keys()->map(fn ($slug) => ['produto' => 'fk/'.$slug, 'pagina' => 'produto']);
        $landings = collect(array_keys((array) config('infoprodutos.produtos')))->map(fn ($p) => ['produto' => $p, 'pagina' => 'landing']);
        $vistas = InfoprodutoEvento::query()->where('tipo', 'pageview')->dePessoas()
            ->where('created_at', '>=', now()->subDays(30))
            ->where('produto', 'not like', 'kz/%')->where('produto', 'not like', 'fk/%')
            ->distinct()->get(['produto', 'pagina'])->map(fn ($e) => ['produto' => $e->produto, 'pagina' => $e->pagina]);

        return $landings->concat($vistas)->concat($kz)->concat($fk)
            ->filter(fn ($p) => PaginasDoSite::paginaDeProduto($p['produto']) && PrintDaPagina::url($p['produto'], $p['pagina']) !== null)
            ->unique(fn ($p) => $p['produto'].'|'.$p['pagina'])->values()->all();
    }

    /** null = a página não existe mais (produto saiu da loja, landing fora do ar). */
    public function assinatura(string $produto, string $pagina): ?string
    {
        // Página da loja que não é produto: o HTML muda a cada acesso; refaz toda semana.
        if (PaginasDoSite::paginaDeLoja($produto)) {
            return PaginasDoSite::url($produto) !== null ? md5('pagina|'.$produto.'|'.now()->format('o-W')) : null;
        }
        if (str_starts_with($produto, 'kz/')) {
            $p = Product::query()->naKazaKora()->whereNull('parent_product_id')->where('slug', substr($produto, 3))->first();

            return $p ? md5('kz|'.$p->updated_at.'|'.Product::query()->where('parent_product_id', $p->id)->max('updated_at')) : null;
        }
        if (str_starts_with($produto, 'fk/')) {
            $r = $this->ferrakora()->get(substr($produto, 3));
            if ($r === null) {
                return null;
            }

            return md5('fk|'.$r->updated_at.'|'.$r->product?->updated_at.'|'.Product::query()->where('parent_product_id', $r->product_id)->max('updated_at'));
        }
        $url = PrintDaPagina::url($produto, $pagina);

        return $url === null ? null : PrintDaPagina::hashDoHtml($url);
    }

    /** Nome de cada produto publicado na FerraKora: slug do site => título. */
    public function nomesFerraKora(): array
    {
        return $this->ferrakora()->map(fn ($r) => (string) ($r->titulo ?: $r->product?->name))->filter()->all();
    }

    /** @return Collection<string, FerraKoraProduto> */
    private function ferrakora(): Collection
    {
        if ($this->ferrakora !== null) {
            return $this->ferrakora;
        }
        try {
            $catalogo = FerraKoraProduto::catalogo();
            $registros = FerraKoraProduto::query()->where('ativo', true)
                ->whereHas('product', fn ($q) => $q->where('is_active', true))->with('product')->get();

            return $this->ferrakora = $registros->mapWithKeys(fn ($r) => [(string) $catalogo->slugPublicado($r) => $r])->filter(fn ($r, $slug) => $slug !== '');
        } catch (Throwable $e) {
            report($e);

            return $this->ferrakora = collect();
        }
    }
}
