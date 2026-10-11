<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\FlexPickupReceipt;
use App\Modules\Marketplace\Support\FlexPickupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;

/**
 * A API do KoraFlex — o app de celular que bipa o QR da etiqueta do Flex
 * (repositório à parte: github.com/unixlira/koraflex).
 *
 * O ciclo inteiro de uma caixa do Flex, em rotas: o que sai hoje (`dia`),
 * a bipada que a dá como pronta (`bipar`), a entrega ao entregador com
 * comprovante (`entregar`) ou sem (`coletar`), a imagem do comprovante e o
 * `desfazer` de um passo.
 *
 * A regra toda (janela do corte, pack com uma etiqueta só, venda
 * cancelada, consentimento) mora no FlexPickupService — o app é tela, o
 * dado é daqui. Mesma divisão que vale pro KoraSync Web.
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

    /**
     * "Conferida e entregue": a tela do comprovante — assinatura do
     * entregador, foto tirada no envio e o registro do consentimento.
     *
     * A foto e a assinatura chegam como data URL do celular e só são
     * gravadas com `consentimento` verdadeiro; sem ele a entrega vira uma
     * baixa simples, sem recibo assinado. `aviso` é o TEXTO exato que a
     * pessoa leu na tela, gravado junto pra valer daqui a meses.
     */
    public function entregar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'pedidos' => ['required', 'array', 'min:1', 'max:200'],
            'pedidos.*' => ['integer'],
            'consentimento' => ['required', 'boolean'],
            // ~2,7 MB de base64 = ~2 MB de imagem; o app manda bem menos.
            'assinatura' => ['nullable', 'string', 'max:2800000'],
            'foto' => ['nullable', 'string', 'max:7000000'],
            'nome' => ['nullable', 'string', 'max:120'],
            'aviso' => ['nullable', 'string', 'max:4000'],
            'dispositivo' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json($this->flex->entregar(
            pedidos: $dados['pedidos'],
            consentimento: (bool) $dados['consentimento'],
            assinatura: $dados['assinatura'] ?? null,
            foto: $dados['foto'] ?? null,
            nome: $dados['nome'] ?? null,
            dispositivo: $dados['dispositivo'] ?? null,
            textoDoAviso: $dados['aviso'] ?? null,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        ));
    }

    /** A entrega sem comprovante: o entregador com pressa, ou que não quis assinar. */
    public function coletar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'pedidos' => ['required', 'array', 'min:1', 'max:200'],
            'pedidos.*' => ['integer'],
            'dispositivo' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json($this->flex->coletar($dados['pedidos'], $dados['dispositivo'] ?? null));
    }

    /**
     * A imagem de um comprovante (assinatura ou foto).
     *
     * Servida por rota autenticada, nunca de pasta pública: é a foto de uma
     * pessoa. Sem isso o recibo seria uma prova que ninguém consegue ver.
     */
    public function imagemDoRecibo(FlexPickupReceipt $recibo, string $tipo): HttpResponse
    {
        $caminho = $tipo === 'assinatura' ? $recibo->signature_path : ($tipo === 'foto' ? $recibo->photo_path : null);

        abort_unless($caminho && Storage::disk('local')->exists($caminho), 404, 'Esse comprovante não tem essa imagem.');

        return response(Storage::disk('local')->get($caminho), 200, [
            'Content-Type' => str_ends_with($caminho, '.png') ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Bipou errado, a caixa voltou, ou marcou entregue antes da hora. */
    public function desfazer(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'pedido' => ['required', 'integer'],
        ]);

        return response()->json($this->flex->desfazer($dados['pedido']));
    }
}
