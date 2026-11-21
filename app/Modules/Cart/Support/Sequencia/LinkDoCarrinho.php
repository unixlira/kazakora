<?php

namespace App\Modules\Cart\Support\Sequencia;

use App\Modules\Catalog\Models\Product;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\WhatsApp\Support\WhatsAppNumeros;
use App\Support\SiteAtual;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Link de cada disparo da sequência de carrinho abandonado (pedido do Lira
 * 21/11/2026): o post do blog ligado ao produto, quando existir; até lá, a
 * página do produto no site certo (kazakora.com ou ferrakora.com).
 *
 * O blog (módulo Editorial, tabelas editorial_posts e editorial_post_product)
 * ainda está em outro ramo. Por isso a consulta vai direto às tabelas e só
 * quando elas existem: sem o blog no ar, cai sempre na página do produto.
 * O blog é da KazaKora; carrinho da FerraKora vai sempre para a página do
 * produto no site da FerraKora (não manda o cliente para a outra marca).
 *
 * O link volta em duas partes: a base do site (fixa no botão do template da
 * Meta, "https://kazakora.com/{{1}}") e o sufixo (o {{1}} do botão).
 */
class LinkDoCarrinho
{
    /**
     * @return array{url: string, base: string, sufixo: string, tipo: string}
     */
    public function resolver(string $site, ?Product $produto, int $numero, string $canal): array
    {
        $site = $site === SiteAtual::FERRAKORA ? SiteAtual::FERRAKORA : SiteAtual::KAZAKORA;
        $base = WhatsAppNumeros::site($site) ?: rtrim((string) config('app.url'), '/');
        $pai = $produto ? ($produto->parent_product_id ? (Product::withTrashed()->find($produto->parent_product_id) ?? $produto) : $produto) : null;

        [$caminho, $tipo] = $this->caminho($site, $pai, $produto);

        $utm = http_build_query([
            'utm_source' => $canal === 'whatsapp' ? 'whatsapp' : 'email',
            'utm_medium' => 'carrinho_abandonado',
            'utm_campaign' => 'd'.CalendarioSequencia::dia($numero),
        ]);
        $sufixo = ltrim($caminho, '/').(str_contains($caminho, '?') ? '&' : '?').$utm;

        return ['url' => $base.'/'.$sufixo, 'base' => $base, 'sufixo' => $sufixo, 'tipo' => $tipo];
    }

    /** @return array{0: string, 1: string} caminho no site e tipo (blog|produto|loja) */
    private function caminho(string $site, ?Product $pai, ?Product $produto): array
    {
        if (! $pai) {
            return ['/', 'loja'];
        }

        if ($site === SiteAtual::KAZAKORA && ($slug = $this->postDoBlog(array_filter([$pai->id, $produto?->id])))) {
            return ['/blog/'.$slug, 'blog'];
        }

        if ($site === SiteAtual::FERRAKORA) {
            $slug = $this->slugFerraKora(array_filter([$pai->id, $produto?->id]));

            return $slug ? ['/produto/'.$slug.'/', 'produto'] : ['/', 'loja'];
        }

        return $pai->slug ? ['/produtos/'.$pai->slug, 'produto'] : ['/', 'loja'];
    }

    /** Slug do post publicado mais recente ligado ao produto, se o blog existir. */
    public function postDoBlog(array $produtoIds): ?string
    {
        try {
            if (! Schema::hasTable('editorial_posts') || ! Schema::hasTable('editorial_post_product')) {
                return null;
            }

            $slug = DB::table('editorial_posts')
                ->join('editorial_post_product', 'editorial_post_product.editorial_post_id', '=', 'editorial_posts.id')
                ->whereIn('editorial_post_product.product_id', $produtoIds)
                ->where('editorial_posts.status', 'published')
                ->whereNotNull('editorial_posts.published_at')
                ->where('editorial_posts.published_at', '<=', now())
                ->orderByDesc('editorial_posts.published_at')
                ->value('editorial_posts.slug');

            return filled($slug) ? (string) $slug : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function slugFerraKora(array $produtoIds): ?string
    {
        try {
            $registro = FerraKoraProduto::query()->whereIn('product_id', $produtoIds)->where('ativo', true)->first();

            return $registro ? (FerraKoraProduto::catalogo()->slugPublicado($registro) ?: null) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
