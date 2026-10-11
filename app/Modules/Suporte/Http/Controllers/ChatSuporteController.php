<?php

namespace App\Modules\Suporte\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Suporte\Jobs\ResponderComManuela;
use App\Modules\Suporte\Models\Chamado;
use App\Modules\Suporte\Models\MensagemSuporte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Falar com suporte" no detalhe da compra (pedido 2026-10-11). Uma conversa
 * ativa por pedido; a Manuela responde pela fila (ResponderComManuela) a não
 * ser que um atendente tenha assumido (humano_ativo).
 */
class ChatSuporteController extends Controller
{
    public function mostrar(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        $chamado = $this->chamadoAtivo($order) ?? Chamado::query()->where('order_id', $order->id)->latest('id')->first();

        return response()->json($this->payload($chamado));
    }

    public function enviar(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        $dados = $request->validate(['mensagem' => ['required', 'string', 'min:1', 'max:2000']], [], ['mensagem' => 'mensagem']);

        $chamado = $this->chamadoAtivo($order) ?? Chamado::create([
            'numero' => Chamado::gerarNumero(),
            'user_id' => $request->user()->id,
            'order_id' => $order->id,
            'status' => Chamado::CONVERSA,
            'aberto_por' => 'cliente',
        ]);

        $chamado->registrar(MensagemSuporte::CLIENTE, strip_tags(trim($dados['mensagem'])), $request->user()->id);

        if (! $chamado->humano_ativo) {
            ResponderComManuela::dispatch($chamado->id);
        }

        return response()->json($this->payload($chamado->fresh()));
    }

    private function chamadoAtivo(Order $order): ?Chamado
    {
        return Chamado::query()->where('order_id', $order->id)->whereIn('status', Chamado::ATIVOS)->latest('id')->first();
    }

    private function payload(?Chamado $chamado): array
    {
        return [
            'chamado' => $chamado?->paraTela(),
            'mensagens' => $chamado ? $chamado->mensagens()->get()->map->paraTela()->values() : [],
        ];
    }
}
