<?php

namespace App\Console\Commands;

use App\Modules\Operacional\Mail\CanceladosSeparados;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Pedido do Mercado Livre/Shopee cancelado DEPOIS de separado (pedido
 * 2026-10-10, era uma rotina da Naia às 11h): avisa por e-mail para não
 * despachar. Olha os últimos 7 dias e não repete pedido já avisado (mesmo
 * arquivo de controle da Naia).
 */
class AlertarCanceladosSeparados extends Command
{
    protected $signature = 'loja:cancelados-separados {--para= : Outro destinatário (teste, não marca como avisado)}';

    protected $description = 'Avisa por e-mail pedidos já separados que foram cancelados';

    private const CONTROLE = 'automation/notified_cancelled_packed_orders.json';

    public function handle(): int
    {
        $controle = Storage::disk('local')->exists(self::CONTROLE)
            ? (json_decode((string) Storage::disk('local')->get(self::CONTROLE), true) ?: [])
            : [];
        $teste = filled($this->option('para'));

        $pedidos = [];
        $linhas = DB::table('orders')
            ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('channel_shipments', 'channel_shipments.order_id', '=', 'orders.id')
            ->whereIn('orders.origin', ['mercado_livre', 'shopee'])
            ->where('orders.status', 'cancelled')
            ->whereNotNull('orders.packed_at')
            ->where('orders.updated_at', '>=', now()->subDays(7)->startOfDay())
            ->orderBy('orders.updated_at')
            ->get(['orders.id', 'orders.origin', 'orders.external_order_id', 'orders.packed_at',
                'order_items.product_name', 'order_items.quantity', 'channel_shipments.external_shipment_id']);

        foreach ($linhas as $linha) {
            if (isset($controle[(string) $linha->id]) && ! $teste) {
                continue;
            }
            $pedidos[$linha->id] ??= [
                'id' => $linha->id,
                'origin' => $linha->origin,
                'external_order_id' => $linha->external_order_id,
                'external_shipment_id' => $linha->external_shipment_id,
                'packed_at' => $linha->packed_at,
                'items' => [],
            ];
            if ($linha->product_name) {
                $pedidos[$linha->id]['items'][] = ['product_name' => $linha->product_name, 'quantity' => (int) $linha->quantity];
            }
        }

        if (! $pedidos) {
            $this->info('Nenhum pedido separado cancelado novo.');

            return self::SUCCESS;
        }

        $para = $teste ? [$this->option('para')] : array_filter(array_map('trim', explode(',', (string) config('loja.email_relatorios'))));
        Mail::to($para)->send(new CanceladosSeparados(array_values($pedidos)));

        if (! $teste) {
            foreach (array_keys($pedidos) as $id) {
                $controle[(string) $id] = now()->toIso8601String();
            }
            Storage::disk('local')->put(self::CONTROLE, json_encode($controle, JSON_PRETTY_PRINT));
        }

        $this->info(count($pedidos).' pedido(s) avisado(s) para '.implode(', ', $para).'.');

        return self::SUCCESS;
    }
}
