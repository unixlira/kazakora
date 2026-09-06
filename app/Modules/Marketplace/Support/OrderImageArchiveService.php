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
     * JPEG, não PNG. Medido na mesma foto, já reduzida pra 320px:
     * PNG 129 KB contra JPEG 22 KB. PNG é sem perdas e ótimo pra desenho
     * com poucas cores; foto de produto é o caso oposto. A qualidade 82
     * é indistinguível num quadro de 50px.
     */
    private const QUALIDADE_JPEG = 82;

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
            $gd = $this->achatar($this->reduzir($gd));

            ob_start();
            imagejpeg($gd, null, self::QUALIDADE_JPEG);
            $bytes = ob_get_clean();
        } finally {
            imagedestroy($gd);
        }

        if ($bytes === false || $bytes === '') {
            return null;
        }

        Storage::disk(self::DISK)->put($path, $bytes);

        return $path;
    }

    /**
     * Reduz mantendo a proporção; imagem já pequena passa intacta.
     *
     * SEM passar modo de interpolação: IMG_BICUBIC devolve `false` nesta
     * build do GD (testado no servidor), e como o código tratava false
     * como "não deu, segue com a original", a redução silenciosamente
     * NUNCA acontecia — a miniatura continuava saindo em tamanho cheio.
     * O modo padrão funciona e a diferença visual em 320px é nenhuma.
     */
    private function reduzir(\GdImage $gd): \GdImage
    {
        $maior = max(imagesx($gd), imagesy($gd));

        if ($maior <= self::LADO_MAXIMO) {
            return $gd;
        }

        $escala = self::LADO_MAXIMO / $maior;
        $menor = imagescale($gd, (int) round(imagesx($gd) * $escala), (int) round(imagesy($gd) * $escala));

        if ($menor === false) {
            return $gd;
        }

        imagedestroy($gd);

        return $menor;
    }

    /**
     * JPEG não tem canal alfa: sem achatar antes, todo pixel transparente
     * das fotos recortadas (padrão do catálogo) sairia PRETO. Fundo branco
     * é o que o card já mostra atrás da miniatura.
     */
    private function achatar(\GdImage $gd): \GdImage
    {
        $fundo = imagecreatetruecolor(imagesx($gd), imagesy($gd));
        imagefill($fundo, 0, 0, imagecolorallocate($fundo, 255, 255, 255));
        imagecopy($fundo, $gd, 0, 0, 0, 0, imagesx($gd), imagesy($gd));
        imagedestroy($gd);

        return $fundo;
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
            'order-images/t320/%s/%s/%s/%s/%s.jpg',
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
