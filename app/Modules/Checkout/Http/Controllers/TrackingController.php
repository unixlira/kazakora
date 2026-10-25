<?php

namespace App\Modules\Checkout\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\CaptchaRastreio;
use App\Modules\Checkout\Support\NovoPixDoPedido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Rastrear pedido (pedido 2026-10-10). Desde 2026-10-17 só com login: o dono
 * do pedido ou um admin. Por formulário (número do pedido) ou pelo link
 * direto que vai no WhatsApp/e-mail (/rastreio/{id}-{assinatura}, ver
 * Order::trackingRef()); quem abre o link sem login entra e volta para ele.
 * Depois de 3 buscas com erro do mesmo IP, pede CAPTCHA (CaptchaRastreio).
 */
class TrackingController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Checkout/Rastreio', ['pedido' => null, 'captcha' => CaptchaRastreio::exigido($request)]);
    }

    public function captcha(Request $request): HttpResponse
    {
        return response(CaptchaRastreio::imagem($request), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function search(Request $request): RedirectResponse
    {
        if (CaptchaRastreio::exigido($request) && ! CaptchaRastreio::confere($request, $request->input('captcha'))) {
            CaptchaRastreio::registrarFalha($request);

            return back()->withErrors(['captcha' => 'Digite os caracteres da imagem para continuar.'])->withInput();
        }

        try {
            $data = $request->validate([
                'pedido' => ['required', 'string', 'max:20'],
            ], [], ['pedido' => 'número do pedido']);
        } catch (ValidationException $e) {
            CaptchaRastreio::registrarFalha($request);

            throw $e;
        }

        // Aceita o número novo (261011K7Q2M9XD) ou o antigo (só dígitos).
        $digitado = strtoupper(trim(str_replace(['#', ' '], '', $data['pedido'])));
        $order = Order::query()->where('origin', Order::ORIGIN_STORE)->where('external_order_id', $digitado)->first()
            ?? (ctype_digit($digitado) ? Order::query()->find((int) $digitado) : null);

        if (! $order || ! $this->podeVer($request->user(), $order)) {
            CaptchaRastreio::registrarFalha($request);

            return back()->withErrors(['pedido' => 'Não encontramos esse pedido na sua conta. Confira o número.'])->withInput();
        }

        CaptchaRastreio::limparFalhas($request);

        return redirect()->route('rastreio.ver', $order->trackingRef());
    }

    public function show(Request $request, string $ref): Response|RedirectResponse
    {
        $order = Order::findByTrackingRef($ref);
        abort_unless($order, 404);
        $order->load(['items', 'invoice', 'latestCorreiosPrePostagem', 'channelShipment', 'payments']);

        $pix = NovoPixDoPedido::pixAberto($order);
        $user = $request->user();

        if (! $user || ! $this->podeVer($user, $order)) {
            // Novo Pix (pedidos:novo-pix, 2026-10-23): quem tem o link
            // assinado paga sem login; a página mostra só o pagamento, sem
            // endereço nem andamento. Sem Pix aberto, vale o login de sempre.
            if ($pix) {
                return Inertia::render('Checkout/Rastreio', [
                    'pedido' => [
                        'id' => $order->id,
                        'numero' => $order->numero,
                        'criado_em' => $order->created_at?->toIso8601String(),
                        'status' => $order->status,
                        'cancelado' => false,
                        'publico' => true,
                        'total' => (float) $order->total,
                        'itens' => $order->items->map(fn ($item) => ['nome' => $item->product_name, 'quantidade' => $item->quantity])->values(),
                        'cidade' => null,
                        'codigo_rastreio' => null,
                        'transportadora' => null,
                        'etapas' => [],
                        'pix' => $this->dadosDoPix($ref, $pix),
                    ],
                ]);
            }

            if (! $user) {
                return redirect()->guest(route('entrar'));
            }

            abort(403);
        }

        $codigo = $order->trackingCode();

        return Inertia::render('Checkout/Rastreio', [
            'pedido' => [
                'id' => $order->id,
                'numero' => $order->numero,
                'criado_em' => $order->created_at?->toIso8601String(),
                'status' => $order->status,
                'cancelado' => $order->status === Order::STATUS_CANCELLED,
                'publico' => false,
                'total' => (float) $order->total,
                'itens' => $order->items->map(fn ($item) => ['nome' => $item->product_name, 'quantidade' => $item->quantity])->values(),
                'cidade' => trim(($order->shipping_city ?? '').'/'.($order->shipping_state ?? ''), '/'),
                'codigo_rastreio' => $codigo,
                'transportadora' => $order->shipping_carrier_name,
                'etapas' => $order->etapas(),
                'pix' => $pix ? $this->dadosDoPix($ref, $pix) : null,
            ],
        ]);
    }

    /**
     * Consultado pela página do rastreio a cada poucos segundos enquanto o
     * Pix novo está na tela: confere no Mercado Pago e, se pago, confirma o
     * pedido pelo fluxo normal (NovoPixDoPedido::conferir).
     */
    public function statusDoPix(string $ref, NovoPixDoPedido $novoPix): JsonResponse
    {
        $order = Order::findByTrackingRef($ref);
        abort_unless($order, 404);
        $order->load('payments');

        if ($pix = NovoPixDoPedido::pixAberto($order)) {
            try {
                $novoPix->conferir($pix);
            } catch (Throwable) {
                // Mercado Pago fora: tenta de novo na próxima consulta.
            }

            $order->refresh();
        }

        return response()->json([
            'pago' => $order->status === Order::STATUS_PAID,
            'aberto' => NovoPixDoPedido::pixAberto($order->load('payments')) !== null,
        ]);
    }

    /** @return array<string, mixed> */
    private function dadosDoPix(string $ref, Payment $pix): array
    {
        return [
            'valor' => (float) $pix->amount,
            'vencimento' => $pix->expira_em?->toIso8601String(),
            'copia_e_cola' => $pix->pix_copia_e_cola,
            'qr_base64' => $pix->pix_qr_base64,
            'status_url' => route('rastreio.pix', $ref, false),
        ];
    }

    private function podeVer(?User $user, Order $order): bool
    {
        return $user !== null && ($user->isAdmin() || ($order->user_id !== null && $order->user_id === $user->id));
    }
}
