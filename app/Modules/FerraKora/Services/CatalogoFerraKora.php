<?php

namespace App\Modules\FerraKora\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CatalogoOutroSite;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Catalog\Models\ProductAdContent;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Support\DescontoPix;
use App\Support\SiteAtual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Catálogo do FerraKora (loja de ferramentas, pedido 2026-10-13), no mesmo
 * formato do Beauty Kora (CatalogoOutroSite) e mais dois campos:
 * "categoria" (para o menu do site) e "kazakora_url" (página do produto na
 * loja KazaKora, onde o cliente compra).
 *
 * Preço com o frete embutido da região do visitante (2026-10-13): o mesmo
 * final_price da página do produto no KazaKora, para o cliente não ver um
 * preço no FerraKora e outro ao clicar em Comprar. "frete_gratis" diz ao
 * site que o frete já está no preço; "desconto_pix" é o % que o checkout do
 * KazaKora tira no Pix.
 *
 * Conteúdo da página (2026-10-14): a página de um produto (produtoComConteudo)
 * leva também "conteudo", o mesmo do AnuncioConteudoService que a página do
 * KazaKora usa (benefícios + blocos com foto, comparativo, dúvidas), gerado
 * pelo Gemini a partir das fotos. A lista não leva, para não pesar.
 */
class CatalogoFerraKora extends CatalogoOutroSite
{
    public function site(): string
    {
        return SiteAtual::FERRAKORA;
    }

    protected function modelo(): string
    {
        return FerraKoraProduto::class;
    }

    protected function comFreteEmbutido(): bool
    {
        return true;
    }

    protected function camposDoItem(array $item): array
    {
        return ['categoria' => filled($item['categoria'] ?? null) ? trim((string) $item['categoria']) : null];
    }

    protected function camposExtras(Model $registro, Product $pai): array
    {
        return [
            'categoria' => $registro->categoria,
            'kazakora_url' => filled($pai->slug) ? route('produtos.ver', $pai->slug) : null,
            'frete_gratis' => app(FreteEmbutido::class)->ligado(),
            'desconto_pix' => DescontoPix::percentual(),
        ];
    }

    /** Produto do site com o conteúdo da página. null = não está no site. */
    public function produtoComConteudo(string $idOuSlug): ?array
    {
        $produto = $this->produto($idOuSlug);

        if ($produto === null) {
            return null;
        }

        $pai = Product::query()->with(['images', 'adContent'])->find((int) Str::after($produto['id'], self::PREFIXO_PRODUTO));

        return [...$produto, 'conteudo' => $pai ? $this->conteudoDaPagina($pai) : null];
    }

    /**
     * O conteúdo como a página do KazaKora usa, com a URL da foto de cada
     * bloco já resolvida (o índice é a ordem das fotos do produto, a mesma
     * de "images" na API). null = ainda não gerado (o comando
     * ferrakora:gerar-conteudo e o agendador geram).
     *
     * @return array<string, mixed>|null
     */
    public function conteudoDaPagina(Product $pai): ?array
    {
        /** @var ProductAdContent|null $conteudo */
        $conteudo = $pai->adContent;

        if ($conteudo === null || empty($conteudo->blocos)) {
            return null;
        }

        $fotos = $pai->images->values();

        return [
            'destaques' => $conteudo->destaques ?? [],
            'chamada' => $conteudo->chamada,
            'blocos' => collect($conteudo->blocos)->map(fn (array $bloco) => [
                ...$bloco,
                'imagem_url' => isset($bloco['imagem']) && $bloco['imagem'] !== null ? $fotos->get((int) $bloco['imagem'])?->url : null,
            ])->values()->all(),
            'comparativo' => $conteudo->comparativo,
            'beneficios' => $conteudo->beneficios ?? [],
            'duvidas' => $conteudo->duvidas ?? [],
            'fonte' => $conteudo->fonte,
            'gerado_em' => $conteudo->gerado_em?->toIso8601String(),
        ];
    }

    /** Pais publicados no site (ativos, com o produto ativo), na ordem do site. */
    public function paisPublicados(): Builder
    {
        return Product::query()
            ->where('is_active', true)
            ->whereIn('id', FerraKoraProduto::query()->where('ativo', true)->select('product_id'))
            ->with(['images', 'adContent'])
            ->orderBy(FerraKoraProduto::query()->select('ordem')->whereColumn('ferrakora_produtos.product_id', 'products.id')->limit(1))
            ->orderBy('id');
    }
}
