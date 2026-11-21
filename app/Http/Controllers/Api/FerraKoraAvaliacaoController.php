<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Review;
use App\Modules\Catalog\Models\ReviewImage;
use App\Modules\Catalog\Services\CatalogoOutroSite;
use App\Modules\Catalog\Support\ModalAvaliacao;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\FerraKora\Models\FerraKoraCliente;
use App\Modules\Catalog\Support\ProductImageOptimizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Modal "Avalie sua compra" no site FerraKora (pedido do Lira 2026-11-21),
 * com as mesmas regras do da KazaKora (ModalAvaliacao): pedido do FerraKora
 * ENTREGUE, produto ainda sem avaliação do cliente, o mais recente primeiro.
 * "Não avaliar agora" fica no navegador (o site manda os pulados em `pular`)
 * e volta na próxima visita, até o cliente avaliar.
 *
 * GET  avaliacao/pendente?pular[]=kz-12   próximo item ou null
 * POST avaliacao/kz-{id}                  nota, comentário e até 4 fotos
 */
class FerraKoraAvaliacaoController extends Controller
{
    public function pendente(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'pular' => ['sometimes', 'array', 'max:200'],
            'pular.*' => ['string', 'regex:/^'.CatalogoOutroSite::PREFIXO_PRODUTO.'\d{1,10}$/'],
        ]);
        $pulados = collect($dados['pular'] ?? [])->map(fn (string $id) => $this->numero($id))->all();

        $item = $this->pendentes($this->cliente($request))->first(fn (OrderItem $item) => ! in_array((int) $item->product_id, $pulados, true));

        return response()->json(['item' => $item ? [
            'produto' => CatalogoOutroSite::PREFIXO_PRODUTO.$item->product_id,
            'nome' => $item->product_name ?: $item->product->name,
            'imagem' => $item->product->images->first()?->thumb_url ?? $item->product->parent?->images->first()?->thumb_url,
            'pedido' => $item->order->numero,
        ] : null])->header('Cache-Control', 'no-store');
    }

    public function avaliar(Request $request, string $produto): JsonResponse
    {
        $cliente = $this->cliente($request);
        $productId = $this->numero($produto);

        $pedido = $cliente->pedidos()
            ->where('site_origem', Order::SITE_FERRAKORA)
            ->where('status', Order::STATUS_COMPLETED)
            ->whereHas('items', fn ($query) => $query->where('product_id', $productId))
            ->latest()
            ->first(['id', 'shipping_state']);

        if (! $pedido) {
            return response()->json(['message' => 'Você só pode avaliar produtos que já recebeu.'], 422);
        }

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:'.ModalAvaliacao::MAX_COMENTARIO],
            'fotos' => ['sometimes', 'array', 'max:'.ModalAvaliacao::MAX_FOTOS],
            'fotos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.ModalAvaliacao::MAX_FOTO_KB],
        ], [
            'rating.required' => 'Escolha de 1 a 5 estrelas.',
            'fotos.max' => 'Envie no máximo '.ModalAvaliacao::MAX_FOTOS.' fotos.',
            'fotos.*.mimes' => 'As fotos precisam ser JPG, PNG ou WebP.',
            'fotos.*.mimetypes' => 'As fotos precisam ser JPG, PNG ou WebP.',
            'fotos.*.max' => 'Cada foto pode ter no máximo '.intdiv(ModalAvaliacao::MAX_FOTO_KB, 1024).' MB.',
        ]);

        $fotos = $request->file('fotos', []);

        $review = Review::query()->where('ferrakora_cliente_id', $cliente->id)->where('product_id', $productId)->first() ?? new Review;
        $review->forceFill([
            'ferrakora_cliente_id' => $cliente->id,
            'product_id' => $productId,
            'user_id' => null,
            'reviewer_name' => $cliente->name,
            'rating' => $validated['rating'],
            'comment' => $validated['comment'] ?? null,
            'ocultar_nome' => false,
            'uf' => $pedido->shipping_state ?: null,
        ])->save();

        $posicao = (int) $review->images()->max('position');
        foreach (array_slice($fotos, 0, max(0, ModalAvaliacao::MAX_FOTOS - $review->images()->count())) as $foto) {
            $extensao = match ($foto->getMimeType()) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $caminho = $foto->storeAs("avaliacoes/{$review->id}", Str::random(32).'.'.$extensao, 'public');
            if (! $caminho) {
                continue;
            }
            $caminho = ProductImageOptimizer::optimize($caminho) ?? $caminho;
            ReviewImage::create(['review_id' => $review->id, 'image_url' => asset('storage/'.$caminho), 'position' => ++$posicao]);
        }

        return response()->json(['message' => 'Avaliação enviada, obrigado!'], 201);
    }

    private function cliente(Request $request): FerraKoraCliente
    {
        $cliente = $request->user();
        abort_unless($cliente instanceof FerraKoraCliente, 401);

        return $cliente;
    }

    /** Itens entregues do FerraKora e sem avaliação do cliente, mais recente primeiro. */
    private function pendentes(FerraKoraCliente $cliente): Collection
    {
        $avaliados = Review::query()->where('ferrakora_cliente_id', $cliente->id)->pluck('product_id');

        return OrderItem::query()
            ->whereNotNull('order_items.product_id')
            ->whereNotIn('order_items.product_id', $avaliados)
            ->whereHas('order', fn ($query) => $query
                ->where('ferrakora_cliente_id', $cliente->id)
                ->where('site_origem', Order::SITE_FERRAKORA)
                ->where('status', Order::STATUS_COMPLETED))
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->with([
                'order:id,external_order_id,updated_at',
                'product:id,name,slug,parent_product_id',
                'product.images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position'),
                'product.parent.images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position'),
            ])
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->orderByDesc('orders.updated_at')
            ->orderByDesc('order_items.id')
            ->select('order_items.*')
            ->get()
            ->unique('product_id')
            ->values();
    }

    private function numero(string $id): int
    {
        return (int) Str::after($id, CatalogoOutroSite::PREFIXO_PRODUTO);
    }
}
