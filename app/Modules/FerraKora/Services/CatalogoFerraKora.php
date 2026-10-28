<?php

namespace App\Modules\FerraKora\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAdContent;
use App\Modules\Catalog\Models\Review;
use App\Modules\Catalog\Services\CatalogoOutroSite;
use App\Modules\Checkout\Support\FreteEmbutido;
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
            // Pix + cupom automático (BEMVINDO), que o checkout aplica sozinho (2026-10-24).
            'desconto_pix' => DescontoPix::percentualNaVitrine($pai),
            // Nota e total das avaliações do KazaKora (pai + variações), as
            // mesmas da página do produto na loja (pedido 2026-10-28). O card
            // do FerraKora já lê average_rating.
            'reviewsAnalytics' => $this->resumoAvaliacoes($pai),
        ];
    }

    /** @return array{average_rating: float|null, count: int} */
    public function resumoAvaliacoes(Product $pai): array
    {
        $resumo = $this->avaliacoesDoGrupo($pai)->toBase()->selectRaw('count(*) as total, avg(rating) as media')->first();
        $total = (int) ($resumo->total ?? 0);

        return ['average_rating' => $total > 0 ? round((float) $resumo->media, 2) : null, 'count' => $total];
    }

    /**
     * Avaliações do produto no site (pedido 2026-10-28: "colocar todas as
     * avaliações que a gente tem na base do KazaKora nos produtos da
     * FerraKora"). As mesmas da página do produto no KazaKora: pai +
     * variações, com foto/vídeo primeiro e, em cada grupo, da mais recente
     * para a mais antiga. Nome como a loja mostra (oculto, sorteado nas
     * importadas); a resposta ao cliente e o canal de origem não saem.
     *
     * @return array<string, mixed>
     */
    public function avaliacoes(Product $pai, int $pagina, int $porPagina): array
    {
        $resumo = $this->resumoAvaliacoes($pai);
        $notas = $this->avaliacoesDoGrupo($pai)->toBase()->selectRaw('rating, count(*) as total')->groupBy('rating')->pluck('total', 'rating');

        $lista = $this->avaliacoesDoGrupo($pai)
            ->with(['user:id,name', 'images'])
            ->withCount('images')
            ->orderByRaw('images_count > 0 desc')
            ->latest()
            ->orderByDesc('id')
            ->forPage($pagina, $porPagina)
            ->get()
            ->map(fn (Review $avaliacao) => [
                'id' => $avaliacao->id,
                'nome' => $avaliacao->reviewer_display_name === 'Cliente KazaKora' ? 'Cliente FerraKora' : $avaliacao->reviewer_display_name,
                'uf' => $avaliacao->uf ?: null,
                'nota' => (int) $avaliacao->rating,
                'texto' => (string) $avaliacao->comment,
                'data' => $avaliacao->created_at?->toIso8601String(),
                'midias' => $avaliacao->images->map(fn ($midia) => [
                    'url' => $midia->image_url,
                    'tipo' => preg_match('/\.(mp4|webm|mov)(\?|$)/i', (string) $midia->image_url) ? 'video' : 'imagem',
                ])->values()->all(),
            ])
            ->all();

        return [
            ...$resumo,
            'por_nota' => collect([5, 4, 3, 2, 1])->mapWithKeys(fn (int $nota) => [$nota => (int) ($notas[$nota] ?? 0)])->all(),
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'ultima_pagina' => max(1, (int) ceil($resumo['count'] / $porPagina)),
            'avaliacoes' => $lista,
        ];
    }

    /** @return Builder<Review> */
    private function avaliacoesDoGrupo(Product $pai): Builder
    {
        return Review::query()->whereIn('product_id', Product::query()->where('id', $pai->id)->orWhere('parent_product_id', $pai->id)->select('id'));
    }

    /** Pai publicado no site pelo id da API ("kz-<id>"). null = não está no site. */
    public function paiPublicado(string $id): ?Product
    {
        if (! Str::startsWith($id, self::PREFIXO_PRODUTO) || ! ctype_digit(Str::after($id, self::PREFIXO_PRODUTO))) {
            return null;
        }

        return Product::query()
            ->where('is_active', true)
            ->whereIn('id', FerraKoraProduto::query()->where('ativo', true)->select('product_id'))
            ->find((int) Str::after($id, self::PREFIXO_PRODUTO));
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
