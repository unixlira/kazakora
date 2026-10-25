<?php

namespace App\Modules\Checkout\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\BoletoComMarca;
use Symfony\Component\HttpFoundation\Response;

/**
 * Boleto com o logo da KazaKora (2026-10-24): link assinado do e-mail, da
 * tela do checkout e da página do pedido, abre sem login como o do Mercado
 * Pago. Sem o PDF do Mercado Pago, manda para o boleto original.
 */
class BoletoController extends Controller
{
    public function pdf(Payment $payment, BoletoComMarca $boleto): Response
    {
        abort_unless($payment->method_type === Payment::METHOD_BOLETO && $payment->boleto_url, 404);

        $pdf = BoletoComMarca::temMarca($payment) ? $boleto->pdf($payment) : null;

        if ($pdf === null) {
            return redirect()->away($payment->boleto_url);
        }

        $order = $payment->order;
        $nome = 'boleto-kazakora-'.($order?->numero ?? $payment->order_id).'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nome.'"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
