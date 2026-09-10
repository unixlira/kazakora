<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Support\FlexPickupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A API do KoraFlex — o app de celular que bipa o QR da etiqueta do Flex
 * (repositório à parte: github.com/unixlira/koraflex).
 *
 * Três rotas e nada mais: o que sai hoje, bipar, desfazer. A regra toda
 * (janela do corte, pack com uma etiqueta só, venda cancelada) mora no
 * FlexPickupService — o app é tela, o dado é daqui. Mesma divisão que vale
 * pro KoraSync Web.
 */
class KoraFlexController extends Controller
{
    public function __construct(private readonly FlexPickupService $flex) {}

    /** O que a transportadora tem que levar hoje. */
    public function dia(): JsonResponse
    {
        return response()->json($this->flex->dia());
    }

    /**
     * Uma bipada. Responde 200 mesmo quando recusa: quem está com a caixa
     * na mão precisa VER o motivo na tela, e um 4xx no meio do galpão (com
     * sinal ruim) só vira "erro de conexão" genérico no app.
     */
    public function bipar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'qr' => ['required', 'string', 'max:2000'],
            'dispositivo' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json($this->flex->bipar($dados['qr'], $dados['dispositivo'] ?? null));
    }

    /** Bipou errado, ou a coleta não aconteceu e a caixa voltou. */
    public function desfazer(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'pedido' => ['required', 'integer'],
        ]);

        return response()->json($this->flex->desfazer($dados['pedido']));
    }
}
