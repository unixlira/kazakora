<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Newsletter\Support\InscreverCliente;
use Illuminate\Console\Command;

/**
 * Coloca na newsletter os clientes que já compraram (pago) no site KazaKora antes de 25/11/2026,
 * com a mesma regra da inscrição automática da compra (InscreverCliente): quem saiu não volta.
 */
class NewsletterInscreverClientes extends Command
{
    protected $signature = 'newsletter:inscrever-clientes {--dry-run : Só conta, sem gravar}';

    protected $description = 'Inscreve na newsletter os clientes com compra paga no site KazaKora (quem saiu não volta)';

    public function handle(InscreverCliente $inscrever): int
    {
        $pedidos = Order::query()->with('user')->where('origin', Order::ORIGIN_STORE)
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED])
            ->orderBy('id')->get();

        $contagem = [];
        foreach ($pedidos as $order) {
            $resultado = $this->option('dry-run') ? 'simulado' : $inscrever->doPedido($order);
            $contagem[$resultado] = ($contagem[$resultado] ?? 0) + 1;
        }

        foreach ($contagem as $resultado => $n) {
            $this->line("{$resultado}: {$n}");
        }
        $this->info('Pedidos pagos do site lidos: '.$pedidos->count());

        return self::SUCCESS;
    }
}
