<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use Illuminate\Support\Facades\Storage;

/**
 * Arquiva uma cópia local da foto do produto de um pedido, pra exibição no
 * card do KoraSync (pedido explícito 2026-08-15: "conseguir ver a imagem
 * ajuda a não errar na separação") — a mesma foto que já foi publicada nos
 * marketplaces, porque é a mesma ProductImage local usada pra publicar lá
 * (ver drivers de canal, publishProduct()), não uma consulta ao vivo na API
 * do canal.
 *
 * Hierarquia de pastas Ano/Mês/Dia/Canal/id_pedido.png (pedido explícito) —
 * mesmo espírito do SalesArchiveService (arquivo de etiqueta por
 * Mês/Canal/Dia), mas indexado por pedido em vez de por rastreio, porque
 * aqui o consumidor (endpoint de imagem) já sabe o id do pedido, não
 * precisa varrer pasta nenhuma.
 *
 * Convertida sempre pra PNG (GD, já disponível no host — sem dependência
 * nova) independente do formato original (jpg/webp) — formato pedido
 * explicitamente, e simplifica o content-type de quem serve o arquivo.
 */
class OrderImageArchiveService
{
    private const DISK = 'local';

    /**
     * Lado máximo da miniatura arquivada.
     *
     * BUG REAL 2026-09-06 (relatado como "foto sem aparecer"): isto
     * reencodava a foto no tamanho ORIGINAL pra um slot de 50x50 no card.
     * Medido na fila real: de 86 KB a 1,1 MB POR miniatura — com ~200
     * itens na tela, dezenas de megabytes por carregamento. As fotos não
     * faltavam: não terminavam de chegar, e ainda faziam a hospedagem
     * tratar a tela como tráfego de bot.
     *
     * 320 e não 50: o dobro do slot cobre tela retina, e o mesmo arquivo
     * serve se um dia a foto for ampliada na tela.
     */
    private const LADO_MAXIMO = 320;

    /**
     * @param  int|null  $productId  Foto de um produto ESPECÍFICO do
     *                                pedido (pedido explícito 2026-08-30:
     *                                "múltiplos produtos de 1 pedido... o
     *                                pessoal identifica pela imagem/foto
     *                                para embalar" — cada produto
     *                                DIFERENTE do pedido tem sua própria
     *                                foto, não só a do 1º item). null
     *                                mantém o comportamento antigo (1º
     *                                item com product_id) — usado pelo
     *                                endpoint de imagem única, mantido por
     *                                compatibilidade.
     * @return string|null caminho relativo no disco 'local' se a imagem
     *                      existe (recém-arquivada ou já arquivada antes),
     *                      null se o pedido/produto não tem imagem pra
     *                      arquivar.
     */
    public function archive(Order $order, ?int $productId = null): ?string
    {
        $path = $this->pathFor($order, $productId);

        // Idempotente/barato depois da primeira vez — só um exists() no
        // disco, sem reabrir/reconverter a imagem a cada chamada (este
        // método é chamado a cada poll do KoraSync, ~a cada 2s, pra
        // qualquer pedido ainda em destaque na fila).
        if (Storage::disk(self::DISK)->exists($path)) {
            return $path;
        }

        $image = $productId !== null
            ? $this->resolveProductImageById($productId)
            : $this->resolveProductImage($order);

        if ($image === null) {
            return null;
        }

        $sourceDisk = Storage::disk('public');

        if (! $sourceDisk->exists($image->path)) {
            return null;
        }

        $raw = $sourceDisk->get($image->path);

        $gd = @imagecreatefromstring($raw);

        if ($gd === false) {
            return null;
        }

        try {
            $gd = $this->reduzir($gd);

            ob_start();
            imagepng($gd);
            $pngBytes = ob_get_clean();
        } finally {
            imagedestroy($gd);
        }

        if ($pngBytes === false || $pngBytes === '') {
            return null;
        }

        Storage::disk(self::DISK)->put($path, $pngBytes);

        return $path;
    }

    /**
     * Reduz mantendo a proporção; imagem já pequena passa intacta.
     * imagealphablending(false) + imagesavealpha(true) preservam o fundo
     * transparente, que é o padrão das fotos de catálogo — sem isso o
     * recorte do produto ganha um fundo preto.
     */
    private function reduzir(\GdImage $gd): \GdImage
    {
        $largura = imagesx($gd);
        $altura = imagesy($gd);
        $maior = max($largura, $altura);

        if ($maior <= self::LADO_MAXIMO) {
            return $gd;
        }

        $escala = self::LADO_MAXIMO / $maior;
        $menor = imagescale($gd, (int) round($largura * $escala), (int) round($altura * $escala), IMG_BICUBIC);

        if ($menor === false) {
            return $gd;
        }

        imagealphablending($menor, false);
        imagesavealpha($menor, true);
        imagedestroy($gd);

        return $menor;
    }

    /**
     * @param  int|null  $productId  Ver comentário completo em archive().
     * @return string|null bytes da imagem já arquivada (chama archive()
     *                      primeiro se ainda não existir) — usado pelo
     *                      endpoint que serve a imagem pro KoraSync.
     */
    public function bytes(Order $order, ?int $productId = null): ?string
    {
        $path = $this->archive($order, $productId);

        if ($path === null) {
            return null;
        }

        return Storage::disk(self::DISK)->get($path);
    }

    private function pathFor(Order $order, ?int $productId = null): string
    {
        $when = $order->created_at ?? now();

        // Sufixo -{productId} pra não colidir com o cache da imagem única
        // (sem productId, comportamento/caminho antigos intactos).
        $suffix = $productId !== null ? "{$order->id}-{$productId}" : (string) $order->id;

        return sprintf(
            'order-images/t320/%s/%s/%s/%s/%s.png',
            $when->format('Y'),
            $when->format('m'),
            $when->format('d'),
            $order->origin,
            $suffix,
        );
    }

    /**
     * Produto do primeiro item com product_id (pedido com item avulso sem
     * produto local, ex.: emissão manual de nota, não tem foto pra
     * arquivar — retorna null, tratado como "sem imagem" pelo chamador, não
     * como erro). Prioriza a imagem marcada is_primary; sem nenhuma
     * marcada, a primeira por posição.
     */
    private function resolveProductImage(Order $order): ?object
    {
        $item = $order->items->first(fn ($item) => $item->product_id !== null);
        $product = $item?->product;

        if ($product === null) {
            return null;
        }

        $product->loadMissing('images');

        return $product->images->firstWhere('is_primary', true) ?? $product->images->first();
    }

    /**
     * Mesma lógica de resolveProductImage() acima, mas por productId
     * direto (não precisa saber a qual item do pedido ele pertence — só
     * qual produto mostrar).
     */
    private function resolveProductImageById(int $productId): ?object
    {
        $product = \App\Modules\Catalog\Models\Product::query()->with('images')->find($productId);

        if ($product === null) {
            return null;
        }

        return $product->images->firstWhere('is_primary', true) ?? $product->images->first();
    }
}
