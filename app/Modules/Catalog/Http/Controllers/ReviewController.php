<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Catalog\Models\ReviewImage;
use App\Modules\Catalog\Support\ClubeDeAvaliacoes;
use App\Modules\Catalog\Support\ModalAvaliacao;
use App\Modules\Catalog\Support\ProductImageOptimizer;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ReviewController extends Controller
{
    /**
     * Esboço da tela "Avalie suas compras" (pedido 2026-10-20): cada produto
     * já entregue ao cliente, com a avaliação dele se já existir, e o Clube
     * de Avaliações (pontos, níveis e cupons ganhos).
     */
    public function index(Request $request, ClubeDeAvaliacoes $clube): Response
    {
        $user = $request->user();

        $itens = OrderItem::query()
            ->whereNotNull('product_id')
            ->whereHas('order', fn ($query) => $query->where('user_id', $user->id)->where('status', Order::STATUS_COMPLETED))
            ->with(['order:id,external_order_id,updated_at', 'product:id,name,slug,parent_product_id', 'product.images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position')])
            ->latest('id')
            ->get()
            ->unique('product_id');

        $avaliacoes = Review::query()
            ->where('user_id', $user->id)
            ->whereIn('product_id', $itens->pluck('product_id'))
            ->get(['product_id', 'rating', 'comment', 'ocultar_nome', 'updated_at'])
            ->keyBy('product_id');

        $produtos = $itens->filter(fn (OrderItem $item) => $item->product)->map(fn (OrderItem $item) => [
            'id' => $item->product_id,
            'nome' => $item->product_name ?: $item->product->name,
            'slug' => $item->product->slug,
            'imagem' => $item->product->images->first()?->thumb_url,
            'pedido' => $item->order->numero,
            'entregue_em' => $item->order->updated_at?->toIso8601String(),
            'avaliacao' => ($a = $avaliacoes->get($item->product_id)) ? [
                'nota' => (int) $a->rating,
                'comentario' => $a->comment,
                'ocultar_nome' => (bool) $a->ocultar_nome,
            ] : null,
        ])->values();

        return Inertia::render('Catalog/Avaliar', [
            'produtos' => $produtos,
            'clube' => $clube->resumo($user),
            'nomeCliente' => $user->name,
        ]);
    }

    public function store(Request $request, Product $product, ClubeDeAvaliacoes $clube): RedirectResponse
    {
        $pedido = $request->user()->orders()
            ->where('status', Order::STATUS_COMPLETED)
            ->whereHas('items', fn ($query) => $query->where('product_id', $product->id))
            ->latest()
            ->first(['id', 'shipping_state']);

        if (! $pedido) {
            return back()->withErrors(['review' => 'Você só pode avaliar produtos que já recebeu.']);
        }

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'ocultar_nome' => ['sometimes', 'boolean'],
            // Fotos (modal pós-login, 2026-10-23): galeria ou câmera do celular.
            'fotos' => ['sometimes', 'array', 'max:'.ModalAvaliacao::MAX_FOTOS],
            'fotos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.ModalAvaliacao::MAX_FOTO_KB],
        ], [
            'fotos.max' => 'Envie no máximo '.ModalAvaliacao::MAX_FOTOS.' fotos.',
            'fotos.*.mimes' => 'As fotos precisam ser JPG, PNG ou WebP.',
            'fotos.*.mimetypes' => 'As fotos precisam ser JPG, PNG ou WebP.',
            'fotos.*.max' => 'Cada foto pode ter no máximo '.intdiv(ModalAvaliacao::MAX_FOTO_KB, 1024).' MB.',
            'fotos.*.file' => 'Não deu para ler uma das fotos. Tente de novo.',
            'fotos.*.uploaded' => 'Não deu para enviar uma das fotos. Tente de novo.',
        ]);

        $fotos = $request->file('fotos', []);
        unset($validated['fotos']);
        // UF do carrossel do checkout: estado de entrega do pedido avaliado.
        $validated['uf'] = $pedido->shipping_state ?: null;

        // Edição pela tela "Avalie suas compras" soma às fotos que já existem: teto total.
        $existente = Review::query()->where('user_id', $request->user()->id)->where('product_id', $product->id)->withCount('images')->first();
        if ($fotos && (($existente?->images_count ?? 0) + count($fotos)) > ModalAvaliacao::MAX_FOTOS) {
            return back()->withErrors(['fotos' => 'Envie no máximo '.ModalAvaliacao::MAX_FOTOS.' fotos por avaliação.']);
        }

        $review = Review::updateOrCreate(
            ['user_id' => $request->user()->id, 'product_id' => $product->id],
            $validated,
        );

        $this->guardarFotos($review, $fotos);

        // Clube de Avaliações: nível novo gera o cupom pessoal na hora.
        $novos = $clube->conceder($request->user());
        if ($novos->isNotEmpty()) {
            $codigos = $novos->map(fn ($cupom) => "{$cupom->code} ({$cupom->descricaoDesconto()})")->join(', ', ' e ');

            return back()->with('success', "Avaliação enviada, obrigado! Você ganhou o cupom {$codigos}. Ele fica guardado em Avalie suas compras.");
        }

        return back()->with('success', 'Avaliação enviada, obrigado!');
    }

    /**
     * Dono do modal fechou sem avaliar (X, Esc ou clique fora): não abre mais
     * neste login. A aparição já foi contada quando o modal abriu.
     */
    public function dispensar(Request $request): RedirectResponse
    {
        ModalAvaliacao::encerrar($request->session());

        return back();
    }

    /** @param  array<int, \Illuminate\Http\UploadedFile>  $fotos */
    private function guardarFotos(Review $review, array $fotos): void
    {
        $posicao = (int) $review->images()->max('position');

        foreach ($fotos as $foto) {
            // Nome sorteado, extensão pelo conteúdo (nunca pelo nome que veio do aparelho).
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

            ReviewImage::create([
                'review_id' => $review->id,
                'image_url' => asset('storage/'.$caminho),
                'position' => ++$posicao,
            ]);
        }
    }
}
