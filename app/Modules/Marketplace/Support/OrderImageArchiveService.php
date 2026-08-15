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
     * @return string|null caminho relativo no disco 'local' se a imagem
     *                      existe (recém-arquivada ou já arquivada antes),
     *                      null se o pedido não tem produto/imagem pra
     *                      arquivar.
     */
    public function archive(Order $order): ?string
    {
        $path = $this->pathFor($order);

        // Idempotente/barato depois da primeira vez — só um exists() no
        // disco, sem reabrir/reconverter a imagem a cada chamada (este
        // método é chamado a cada poll do KoraSync, ~a cada 2s, pra
        // qualquer pedido ainda em destaque na fila).
        if (Storage::disk(self::DISK)->exists($path)) {
            return $path;
        }

        $image = $this->resolveProductImage($order);

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
     * @return string|null bytes da imagem já arquivada (chama archive()
     *                      primeiro se ainda não existir) — usado pelo
     *                      endpoint que serve a imagem pro KoraSync.
     */
    public function bytes(Order $order): ?string
    {
        $path = $this->archive($order);

        if ($path === null) {
            return null;
        }

        return Storage::disk(self::DISK)->get($path);
    }

    private function pathFor(Order $order): string
    {
        $when = $order->created_at ?? now();

        return sprintf(
            'order-images/%s/%s/%s/%s/%d.png',
            $when->format('Y'),
            $when->format('m'),
            $when->format('d'),
            $order->origin,
            $order->id,
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
}
