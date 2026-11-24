<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Review;
use App\Modules\Catalog\Support\ModalAvaliacao;
use App\Modules\Catalog\Support\PedidoDeAvaliacao;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Support\MarcaDoEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Página pública do link de avaliação do pedido (pedido do Lira 2026-11-23).
 * Sem login: o link é assinado (middleware signed) e vence em 30 dias. O
 * cliente dá de 1 a 5 estrelas, comentário e até 4 fotos para cada produto do
 * pedido; pode voltar e mudar enquanto o link valer.
 */
class AvaliacaoDoPedidoController extends Controller
{
    public function ver(Request $request, int $pedido): Response
    {
        $order = $this->pedido($pedido);
        $itens = PedidoDeAvaliacao::itens($order);
        $existentes = $itens->mapWithKeys(fn (OrderItem $item) => [$item->product_id => PedidoDeAvaliacao::avaliacaoExistente($order, (int) $item->product_id)]);
        $ferrakora = PedidoDeAvaliacao::ferrakora($order);

        return response()->view('avaliacao-pedido', [
            'marca' => $ferrakora ? MarcaDoEmail::ferrakora() : MarcaDoEmail::kazakora($order),
            'nome' => PedidoDeAvaliacao::primeiroNome($order),
            'numero' => $order->numero,
            'acao' => $request->fullUrl(),
            'aberto' => $this->aberto($order),
            'maxFotos' => ModalAvaliacao::MAX_FOTOS,
            'maxComentario' => ModalAvaliacao::MAX_COMENTARIO,
            'produtos' => $itens->map(function (OrderItem $item) use ($existentes) {
                /** @var Review|null $review */
                $review = $existentes->get($item->product_id);

                return [
                    'id' => (int) $item->product_id,
                    'nome' => PedidoDeAvaliacao::nomeDoItem($item),
                    'imagem' => $item->product->images->first()?->thumb_url ?? $item->product->parent?->images->first()?->thumb_url,
                    'nota' => $review ? (int) $review->rating : null,
                    'comentario' => $review?->comment,
                    'fotos' => $review ? $review->images()->count() : 0,
                ];
            })->all(),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function salvar(Request $request, int $pedido, PedidoDeAvaliacao $servico): RedirectResponse
    {
        $order = $this->pedido($pedido);

        if (! $this->aberto($order)) {
            return redirect()->to($request->fullUrl())->withErrors(['avaliacoes' => 'Este pedido não pode mais ser avaliado por aqui.']);
        }

        $dados = $request->validate([
            'avaliacoes' => ['required', 'array'],
            'avaliacoes.*.nota' => ['nullable', 'integer', 'min:1', 'max:5'],
            'avaliacoes.*.comentario' => ['nullable', 'string', 'max:'.ModalAvaliacao::MAX_COMENTARIO],
            'fotos' => ['sometimes', 'array'],
            'fotos.*' => ['array', 'max:'.ModalAvaliacao::MAX_FOTOS],
            'fotos.*.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.ModalAvaliacao::MAX_FOTO_KB],
        ], [
            'avaliacoes.required' => 'Escolha de 1 a 5 estrelas em pelo menos um produto.',
            'avaliacoes.*.nota.*' => 'Escolha de 1 a 5 estrelas.',
            'avaliacoes.*.comentario.max' => 'O comentário pode ter no máximo '.ModalAvaliacao::MAX_COMENTARIO.' letras.',
            'fotos.*.max' => 'Envie no máximo '.ModalAvaliacao::MAX_FOTOS.' fotos por produto.',
            'fotos.*.*.mimes' => 'As fotos precisam ser JPG, PNG ou WebP.',
            'fotos.*.*.mimetypes' => 'As fotos precisam ser JPG, PNG ou WebP.',
            'fotos.*.*.max' => 'Cada foto pode ter no máximo '.intdiv(ModalAvaliacao::MAX_FOTO_KB, 1024).' MB.',
            'fotos.*.*.uploaded' => 'Não deu para enviar uma das fotos. Tente de novo.',
        ]);

        $gravadas = $servico->avaliar($order, $dados['avaliacoes'], $request->file('fotos', []));

        if ($gravadas === 0) {
            return redirect()->to($request->fullUrl())->withInput()->withErrors(['avaliacoes' => 'Escolha de 1 a 5 estrelas em pelo menos um produto.']);
        }

        return redirect()->to($request->fullUrl())->with('avaliacao_enviada', true);
    }

    private function pedido(int $id): Order
    {
        $order = Order::query()->with(['user', 'ferrakoraCliente'])->find($id);
        abort_unless($order && $order->origin === Order::ORIGIN_STORE, 404);

        return $order;
    }

    /** Cancelado ou devolvido depois do envio do link: não avalia. */
    private function aberto(Order $order): bool
    {
        return $order->status === Order::STATUS_COMPLETED
            && $order->disputed_at === null
            && $order->stock_restored_at === null;
    }
}
