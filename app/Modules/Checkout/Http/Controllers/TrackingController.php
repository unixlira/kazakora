<?php

namespace App\Modules\Checkout\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\CaptchaRastreio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

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

    public function show(Request $request, string $ref): Response
    {
        $order = Order::findByTrackingRef($ref);
        abort_unless($order, 404);
        abort_unless($this->podeVer($request->user(), $order), 403);
        $order->load(['items', 'invoice', 'latestCorreiosPrePostagem', 'channelShipment']);

        $codigo = $order->trackingCode();

        return Inertia::render('Checkout/Rastreio', [
            'pedido' => [
                'id' => $order->id,
                'numero' => $order->numero,
                'criado_em' => $order->created_at?->toIso8601String(),
                'status' => $order->status,
                'cancelado' => $order->status === Order::STATUS_CANCELLED,
                'total' => (float) $order->total,
                'itens' => $order->items->map(fn ($item) => ['nome' => $item->product_name, 'quantidade' => $item->quantity])->values(),
                'cidade' => trim(($order->shipping_city ?? '').'/'.($order->shipping_state ?? ''), '/'),
                'codigo_rastreio' => $codigo,
                'transportadora' => $order->shipping_carrier_name,
                'etapas' => $order->etapas(),
            ],
        ]);
    }

    private function podeVer(User $user, Order $order): bool
    {
        return $user->isAdmin() || ($order->user_id !== null && $order->user_id === $user->id);
    }
}
