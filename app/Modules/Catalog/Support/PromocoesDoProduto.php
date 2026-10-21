<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Promocao;
use App\Modules\Catalog\Models\Sorteio;
use Illuminate\Support\Facades\Cache;

/**
 * Quadros promocionais da página do produto (pedido 2026-10-21): o sorteio
 * (Promoção Airfryer) e os livros digitais (Promoção PDFs). Junta o cadastro
 * de Entregáveis com a chave, a imagem e os produtos de Vender › Promoções e
 * guarda em cache. Qualquer mudança em promoção, produto ligado, sorteio ou
 * livro troca a versão do cache, então a página nunca mostra quadro velho.
 */
class PromocoesDoProduto
{
    private const VERSAO = 'promocoes-produto:versao';

    private const TTL = 21600; // 6 h; a troca de versão é que invalida de verdade.

    /** Imagem do quadro dos livros quando nenhum livro tem a sua. */
    public const QUADRO_LIVROS_PADRAO = 'images/promocoes/livros-revista-cama.webp';

    /**
     * Imagens prontas (public/images/promocoes) que o admin oferece em cada
     * promoção, com o tamanho real para mostrar no seletor.
     *
     * @var array<string, list<array{caminho: string, nome: string, largura: int, altura: int}>>
     */
    public const PRONTAS = [
        Promocao::AIRFRYER => [
            ['caminho' => 'images/promocoes/airfryer.jpg', 'nome' => 'AirFryer', 'largura' => 380, 'altura' => 440],
        ],
        Promocao::PDFS => [
            ['caminho' => 'images/promocoes/livros-revista-cama.webp', 'nome' => 'Revista na cama', 'largura' => 1200, 'altura' => 600],
            ['caminho' => 'images/promocoes/livros-revista-cores.webp', 'nome' => 'Revista colorida', 'largura' => 1200, 'altura' => 600],
            ['caminho' => 'images/promocoes/livros-revista-sem-marca.webp', 'nome' => 'Revista sem marca', 'largura' => 1200, 'altura' => 600],
            ['caminho' => 'images/promocoes/livros-revista.webp', 'nome' => 'Revista', 'largura' => 1200, 'altura' => 600],
            ['caminho' => 'images/promocoes/livros-tablets.webp', 'nome' => 'Tablets', 'largura' => 1200, 'altura' => 600],
        ],
    ];

    /**
     * Tamanho em que a imagem fica certa no quadro (o do sorteio aparece com
     * 190 px de largura; o dos livros em 1200 × 600 com zoom).
     *
     * @var array<string, array{largura: int, altura: int, proporcao: string}>
     */
    public const TAMANHO_RECOMENDADO = [
        Promocao::AIRFRYER => ['largura' => 380, 'altura' => 440, 'proporcao' => 'vertical, 19:22'],
        Promocao::PDFS => ['largura' => 1200, 'altura' => 600, 'proporcao' => 'horizontal, 2:1'],
    ];

    public const FORMATOS = ['jpg', 'jpeg', 'png', 'webp'];

    public const MAX_KB = 5120;

    /**
     * Props da página do produto: sorteios, livrosDigitais e livrosQuadro.
     *
     * @return array{sorteios: list<array<string, mixed>>, livrosDigitais: list<array<string, mixed>>, livrosQuadro: ?string}
     */
    public static function paraPagina(Product $produto): array
    {
        $chave = 'promocoes-produto:'.self::versao().':'.$produto->id;

        return Cache::remember($chave, self::TTL, fn () => self::montar($produto));
    }

    public static function limparCache(): void
    {
        Cache::forever(self::VERSAO, self::versao() + 1);
    }

    private static function versao(): int
    {
        return (int) Cache::get(self::VERSAO, 1);
    }

    private static function montar(Product $produto): array
    {
        $paiId = $produto->parent_product_id ?? $produto->id;
        $promocoes = Promocao::query()
            ->whereIn('slug', [Promocao::AIRFRYER, Promocao::PDFS])
            ->with(['produtos' => fn ($q) => $q->where('product_id', $paiId)])
            ->get()
            ->keyBy('slug');

        // Sem a linha da promoção (não deveria acontecer) vale o de Entregáveis.
        $exibe = fn (?Promocao $promocao) => $promocao?->exibeNoProduto($promocao->produtos->first()) ?? true;
        $airfryer = $promocoes->get(Promocao::AIRFRYER);
        $pdfs = $promocoes->get(Promocao::PDFS);

        $sorteios = $exibe($airfryer) ? Sorteio::doProduto($produto) : collect();
        $livros = $exibe($pdfs) ? LivroDigital::doProduto($produto) : collect();

        return [
            'sorteios' => $sorteios->map(fn (Sorteio $sorteio) => [
                'id' => $sorteio->id,
                'titulo' => $sorteio->titulo,
                'paragrafo_1' => $sorteio->paragrafo_1,
                'paragrafo_2' => $sorteio->paragrafo_2,
                'imagem_url' => $airfryer?->imagem_url ?? $sorteio->imagem_url,
            ])->values()->all(),
            // O PDF não vai para a página, só título e capa.
            'livrosDigitais' => $livros->map(fn (LivroDigital $livro) => [
                'id' => $livro->id,
                'titulo' => $livro->titulo,
                'capa_url' => $livro->capa_url,
            ])->values()->all(),
            // Imagem dos tablets: a da promoção, senão a do primeiro livro que
            // tiver uma; null = a página usa a padrão (dor nas costas).
            'livrosQuadro' => $livros->isEmpty()
                ? null
                : ($pdfs?->imagem_url ?? $livros->first(fn (LivroDigital $livro) => $livro->imagem_quadro)?->imagem_quadro_url),
        ];
    }
}
