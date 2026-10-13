<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Support\ProductImageOptimizer;
use App\Services\Bling\BlingClient;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Preenche as fotos de produto que ficou sem nenhuma imagem (2026-10-13: 57
 * produtos da clonagem da Kikool entraram sem foto porque o levantamento da
 * Shopee não achou o anúncio deles). Procura, na ordem:
 *
 *  1. arquivo: lista {sku, fotos[]} (novo levantamento do fornecedor ou fotos
 *     exportadas de outro ambiente);
 *  2. bling: o produto com o mesmo código (SKU) no Bling, campo midia.imagens;
 *  3. ml-catalogo: o produto de catálogo do Mercado Livre com o nome mais
 *     parecido. É foto de um produto PARECIDO, não necessariamente o mesmo:
 *     só entra se pedir essa fonte e a semelhança passar do mínimo.
 *
 * Só toca em produto que continua sem foto na hora de gravar: rodar de novo
 * não duplica nada.
 */
class CompletarFotosProduto
{
    public const FONTES = ['arquivo', 'bling', 'ml-catalogo'];

    private const MAX_FOTOS = 6;

    /** @var array<string, array<int, string>> */
    private array $arquivo = [];

    private ?string $erroBling = null;

    public function __construct(
        private readonly BlingClient $bling,
        private readonly MercadoLivreClient $mercadoLivre,
    ) {}

    /** @param  array<int, array{sku?: string, fotos?: array<int, string>}>  $itens */
    public function usarArquivo(array $itens): void
    {
        foreach ($itens as $item) {
            $fotos = array_values(array_filter((array) ($item['fotos'] ?? [])));
            if (filled($item['sku'] ?? null) && $fotos !== []) {
                $this->arquivo[trim((string) $item['sku'])] = $fotos;
            }
        }
    }

    /**
     * Produtos vivos (não excluídos), que não são variação de outro, sem
     * nenhuma foto.
     */
    public function semFoto(?string $prefixo = null, array $skus = [])
    {
        return Product::query()
            ->whereNull('parent_product_id')
            ->whereDoesntHave('images')
            ->when($prefixo, fn ($q) => $q->where('sku', 'like', $prefixo.'%'))
            ->when($skus !== [], fn ($q) => $q->whereIn('sku', $skus))
            ->orderBy('id');
    }

    /**
     * @param  array<int, string>  $fontes
     * @return array{fonte: ?string, fotos: array<int, string>, detalhe: ?string}
     */
    public function procurar(Product $produto, array $fontes, float $similaridadeMinima = 0.8): array
    {
        if (in_array('arquivo', $fontes, true) && isset($this->arquivo[$produto->sku])) {
            return ['fonte' => 'arquivo', 'fotos' => array_slice($this->arquivo[$produto->sku], 0, self::MAX_FOTOS), 'detalhe' => null];
        }

        if (in_array('bling', $fontes, true) && ($fotos = $this->fotosDoBling((string) $produto->sku)) !== []) {
            return ['fonte' => 'bling', 'fotos' => $fotos, 'detalhe' => null];
        }

        if (in_array('ml-catalogo', $fontes, true)) {
            $achado = $this->fotosDoCatalogoMl((string) $produto->name, $similaridadeMinima);
            if ($achado !== null) {
                return ['fonte' => 'ml-catalogo', 'fotos' => $achado['fotos'], 'detalhe' => $achado['detalhe']];
            }
        }

        return ['fonte' => null, 'fotos' => [], 'detalhe' => $this->erroBling ? "Bling: {$this->erroBling}" : null];
    }

    /**
     * Baixa e grava as fotos. Devolve quantas entraram (0 se o produto já
     * ganhou foto por outro caminho ou se nenhum link baixou).
     *
     * @param  array<int, string>  $urls
     */
    public function gravar(Product $produto, array $urls): int
    {
        $fotos = collect($urls)->take(self::MAX_FOTOS)->map(fn (string $url) => self::baixar($url))->filter()->values();
        if ($fotos->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($produto, $fotos) {
            if (ProductImage::query()->where('product_id', $produto->id)->lockForUpdate()->exists()) {
                return 0;
            }

            foreach ($fotos as $posicao => $foto) {
                $caminho = "products/{$produto->id}/".Str::random(24).".{$foto['extensao']}";
                Storage::disk('public')->put($caminho, $foto['bytes']);
                ProductImage::query()->create([
                    'product_id' => $produto->id,
                    'path' => ProductImageOptimizer::optimize($caminho) ?? $caminho,
                    'position' => $posicao,
                    'is_primary' => $posicao === 0,
                ]);
            }

            return $fotos->count();
        });
    }

    /** @return array{bytes: string, extensao: string}|null */
    public static function baixar(string $url): ?array
    {
        try {
            $resposta = Http::timeout(30)->retry(2, 500, throw: false)->get($url);
        } catch (Throwable) {
            return null;
        }

        $bytes = $resposta->body();
        if (! $resposta->successful() || strlen($bytes) < 1000) {
            return null;
        }

        $extensao = match (true) {
            str_starts_with($bytes, "\x89PNG") => 'png',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'webp',
            str_starts_with($bytes, "\xFF\xD8") => 'jpg',
            default => null,
        };

        // HTML de erro/bloqueio com status 200 não vira "foto".
        return $extensao === null ? null : ['bytes' => $bytes, 'extensao' => $extensao];
    }

    /** @return array<int, string> */
    private function fotosDoBling(string $sku): array
    {
        if ($sku === '' || $this->erroBling !== null) {
            return [];
        }

        try {
            $id = $this->bling->get('produtos', ['codigo' => $sku])['data'][0]['id'] ?? null;
            if (! $id) {
                return [];
            }
            $imagens = $this->bling->get("produtos/{$id}")['data']['midia']['imagens'] ?? [];
        } catch (Throwable $e) {
            // Sem acesso ao Bling (ex.: homolog sem token): não insiste em cada produto.
            $this->erroBling = Str::limit($e->getMessage(), 120);

            return [];
        }

        return collect([...($imagens['internas'] ?? []), ...($imagens['externas'] ?? []), ...($imagens['imagensURL'] ?? [])])
            ->map(fn ($img) => is_array($img) ? ($img['link'] ?? $img['url'] ?? null) : $img)
            ->filter(fn ($link) => is_string($link) && str_starts_with($link, 'http'))
            ->unique()->take(self::MAX_FOTOS)->values()->all();
    }

    /** @return array{fotos: array<int, string>, detalhe: string}|null */
    private function fotosDoCatalogoMl(string $nome, float $minimo): ?array
    {
        $busca = Str::limit(trim(preg_replace('/\s*kic?ool( comercial)?\s*/i', ' ', $nome)), 120, '');

        try {
            $resultados = $this->mercadoLivre->get('products/search', [
                'status' => 'active', 'site_id' => 'MLB', 'q' => $busca, 'limit' => 5,
            ])['results'] ?? [];
        } catch (Throwable) {
            return null;
        }

        $melhor = null;
        foreach ($resultados as $r) {
            $fotos = collect($r['pictures'] ?? [])->pluck('url')->filter()->take(self::MAX_FOTOS)->values()->all();
            $nota = self::semelhanca($busca, (string) ($r['name'] ?? ''));
            if ($fotos !== [] && $nota >= $minimo && (! $melhor || $nota > $melhor['nota'])) {
                $melhor = ['fotos' => $fotos, 'nota' => $nota, 'id' => $r['id'] ?? '?', 'nome' => $r['name'] ?? ''];
            }
        }

        return $melhor ? [
            'fotos' => $melhor['fotos'],
            'detalhe' => sprintf('%s "%s" (semelhança %.2f)', $melhor['id'], Str::limit($melhor['nome'], 80), $melhor['nota']),
        ] : null;
    }

    /**
     * Fração das palavras (3+ letras, sem acento) do nome menor que aparecem
     * no outro. O divisor é no mínimo 5: nome curto do catálogo ("Furadeira
     * elétrica sem fio") não pode casar com qualquer coisa só por ser curto.
     */
    public static function semelhanca(string $a, string $b): float
    {
        $palavras = fn (string $s) => collect(preg_split('/\s+/', preg_replace('/[^a-z0-9 ]/', ' ', Str::lower(Str::ascii($s)))))
            ->filter(fn ($p) => strlen($p) > 2)->unique()->values();

        $pa = $palavras($a);
        $pb = $palavras($b);
        $divisor = max(5, min($pa->count(), $pb->count()));

        return round($pa->intersect($pb)->count() / $divisor, 2);
    }
}
