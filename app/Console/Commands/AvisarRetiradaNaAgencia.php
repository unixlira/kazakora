<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Jobs\AvisarRetiradaNaAgenciaJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\RastreioDosCorreios;
use Illuminate\Console\Command;

/**
 * A cada 30 minutos (2026-11-20, pedido #4172): pedidos da loja enviados
 * e ainda não entregues cujo último evento nos Correios é "disponível para
 * retirada" recebem o WhatsApp com o endereço da agência. Uma vez por pedido
 * (orders.whatsapp_retirada_em).
 */
class AvisarRetiradaNaAgencia extends Command
{
    protected $signature = 'loja:avisar-retirada {--pedido= : Só este pedido} {--simular : Só lista, não envia}';

    protected $description = 'Avisa pelo WhatsApp quando o pacote do pedido fica aguardando retirada na agência';

    public function handle(RastreioDosCorreios $rastreio): int
    {
        $pedidos = Order::query()
            ->where('origin', Order::ORIGIN_STORE)
            ->where('status', Order::STATUS_SHIPPED)
            ->whereNull('whatsapp_retirada_em')
            ->where('created_at', '>=', now()->subDays(30))
            ->when($this->option('pedido'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $avisados = 0;
        foreach ($pedidos as $order) {
            $codigo = $order->trackingCode();
            if (! filled($codigo)) {
                continue;
            }
            $retirada = RastreioDosCorreios::retirada($rastreio->eventos((string) $codigo));
            if (! $retirada || ! filled($retirada['endereco'])) {
                continue;
            }
            $this->line("#{$order->id} {$codigo}: {$retirada['endereco']} (até {$retirada['ate']})");
            if (! $this->option('simular')) {
                AvisarRetiradaNaAgenciaJob::dispatch($order->id, $retirada['endereco'], $retirada['ate']);
            }
            $avisados++;
        }

        $this->info("Pedidos na agência: {$avisados} de {$pedidos->count()} consultados.");

        return self::SUCCESS;
    }
}
