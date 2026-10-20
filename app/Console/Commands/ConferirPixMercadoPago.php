<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\PixMercadoPago;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rede de segurança do Pix (achado real 19/10, #3283): a tela do checkout
 * só consulta enquanto está aberta e o aviso do Mercado Pago pode não
 * chegar. A cada minuto, pergunta ao MP sobre os Pix ainda aguardando das
 * últimas 2 h. Antes disso, quem pagava com a tela fechada esperava até a
 * varredura de abandonados (30 min).
 */
class ConferirPixMercadoPago extends Command
{
    protected $signature = 'mercadopago:conferir-pix';

    protected $description = 'Confirma no Mercado Pago os Pix do site ainda aguardando pagamento (últimas 2 h)';

    public function handle(PixMercadoPago $pix): int
    {
        $pendentes = Payment::query()
            ->where('provider', Payment::PROVIDER_MERCADOPAGO)
            ->where('method_type', Payment::METHOD_PIX)
            ->where('status', Payment::STATUS_REQUIRES_CONFIRMATION)
            ->whereNotNull('mercadopago_payment_id')
            ->where('created_at', '>=', now()->subHours(2))
            ->get();

        foreach ($pendentes as $payment) {
            try {
                if ($pix->sincronizar($payment) === PixMercadoPago::PAGO) {
                    $this->info("Pix do pedido #{$payment->order_id} pago: confirmado.");
                }
            } catch (Throwable $exception) {
                Log::channel('mercadopago')->warning('mercadopago.conferir_pix.falhou', [
                    'payment_id' => $payment->mercadopago_payment_id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
