<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\BoletoMercadoPago;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Boletos do Mercado Pago ainda aguardando (pedido 2026-10-14): consulta cada
 * um no Mercado Pago e confirma o pedido quando pago, ou cancela e devolve o
 * estoque quando o boleto venceu/foi cancelado. Ver BoletoMercadoPago.
 */
class ConferirBoletosMercadoPago extends Command
{
    protected $signature = 'mercadopago:conferir-boletos';

    protected $description = 'Confere no Mercado Pago os boletos da loja aguardando pagamento (pago → pedido pago; vencido → cancela e devolve estoque)';

    public function handle(BoletoMercadoPago $boletos): int
    {
        $pendentes = Payment::query()
            ->where('provider', Payment::PROVIDER_MERCADOPAGO)
            ->where('method_type', Payment::METHOD_BOLETO)
            ->where('status', Payment::STATUS_REQUIRES_CONFIRMATION)
            ->whereNotNull('mercadopago_payment_id')
            ->orderBy('id')
            ->get();

        $contagem = [BoletoMercadoPago::PAGO => 0, BoletoMercadoPago::CANCELADO => 0, BoletoMercadoPago::PENDENTE => 0];

        foreach ($pendentes as $payment) {
            try {
                $resultado = $boletos->sincronizar($payment);
                $contagem[$resultado]++;

                if ($resultado !== BoletoMercadoPago::PENDENTE) {
                    $this->line("Pedido #{$payment->order_id}: boleto {$resultado}.");
                }
            } catch (Throwable $exception) {
                Log::channel('mercadopago')->error('mercadopago.boleto.conferir_falhou', [
                    'payment_id' => $payment->mercadopago_payment_id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Boletos: {$contagem['pago']} pagos, {$contagem['cancelado']} cancelados, {$contagem['pendente']} aguardando.");

        return self::SUCCESS;
    }
}
