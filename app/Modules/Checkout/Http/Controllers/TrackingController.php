<?php

namespace App\Modules\Checkout\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rastrear pedido (pedido 2026-10-10): página pública. Por formulário (número
 * do pedido + e-mail ou CPF) ou pelo link direto que vai no WhatsApp/e-mail
 * (/rastreio/{id}-{assinatura}, ver Order::trackingRef()).
 */
class TrackingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Checkout/Rastreio', ['pedido' => null]);
    }

    public function search(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pedido' => ['required', 'string', 'max:20'],
            'documento' => ['required', 'string', 'max:255'],
        ], [], ['pedido' => 'número do pedido', 'documento' => 'e-mail ou CPF']);

        $order = Order::query()->with('user')->find((int) preg_replace('/\D/', '', $data['pedido']));
        $documento = trim(mb_strtolower($data['documento']));
        $digitos = preg_replace('/\D/', '', $documento);

        $confere = $order && (
            ($order->user && mb_strtolower((string) $order->user->email) === $documento)
            || mb_strtolower((string) $order->shipping_email) === $documento
            || ($digitos !== '' && strlen($digitos) === 11 && $order->user && preg_replace('/\D/', '', (string) $order->user->cpf) === $digitos)
        );

        if (! $confere) {
            return back()->withErrors(['pedido' => 'Não encontramos um pedido com esses dados. Confira o número e o e-mail ou CPF usados na compra.'])->withInput();
        }

        return redirect()->route('rastreio.ver', $order->trackingRef());
    }

    public function show(string $ref): Response
    {
        $order = Order::findByTrackingRef($ref);
        abort_unless($order, 404);
        $order->load(['items', 'invoice', 'latestCorreiosPrePostagem', 'channelShipment']);

        $pago = in_array($order->status, [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true);
        $codigo = $order->trackingCode();
        $enviado = in_array($order->status, [Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true) || filled($codigo);

        return Inertia::render('Checkout/Rastreio', [
            'pedido' => [
                'id' => $order->id,
                'criado_em' => $order->created_at?->toIso8601String(),
                'status' => $order->status,
                'cancelado' => $order->status === Order::STATUS_CANCELLED,
                'total' => (float) $order->total,
                'itens' => $order->items->map(fn ($item) => ['nome' => $item->product_name, 'quantidade' => $item->quantity])->values(),
                'cidade' => trim(($order->shipping_city ?? '').'/'.($order->shipping_state ?? ''), '/'),
                'codigo_rastreio' => $codigo,
                'transportadora' => $order->shipping_carrier_name,
                'etapas' => [
                    ['titulo' => 'Pedido recebido', 'feito' => true],
                    ['titulo' => 'Pagamento aprovado', 'feito' => $pago],
                    ['titulo' => 'Nota fiscal emitida', 'feito' => $order->invoice?->status === \App\Modules\Fiscal\Models\Invoice::STATUS_AUTHORIZED],
                    ['titulo' => 'Pedido enviado', 'feito' => $enviado],
                    ['titulo' => 'Entregue', 'feito' => $order->status === Order::STATUS_COMPLETED],
                ],
            ],
        ]);
    }
}
