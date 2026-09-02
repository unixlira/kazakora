<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Services\Bling\BlingInvoiceImporter;
use Illuminate\Console\Command;

/**
 * Rede de segurança do importador de nota do Bling (ver
 * BlingInvoiceImporter): a emissão lá é assíncrona, então no instante em
 * que o pedido chega pelo webhook a nota quase nunca existe ainda. Esta
 * varredura pega os pedidos do canal que estão pagos e ainda sem nota
 * deste lado e tenta de novo.
 *
 * Só canais cuja emissão foi delegada ao Bling
 * (services.bling.invoice_issuer_channels) — sem essa chave, a nota é
 * nossa e não há nada pra importar.
 */
class SyncBlingInvoices extends Command
{
    protected $signature = 'invoices:sync-bling {--dias=7 : Janela de pedidos a reconferir}';

    protected $description = 'Traz pro Kazakora as NF-e emitidas pelo Bling nos canais delegados a ele';

    public function handle(BlingInvoiceImporter $importer): int
    {
        $canais = (array) config('services.bling.invoice_issuer_channels', []);

        if ($canais === []) {
            $this->info('Nenhum canal com emissão delegada ao Bling — nada a fazer.');

            return self::SUCCESS;
        }

        $pedidos = Order::query()
            ->whereIn('origin', $canais)
            ->where('status', Order::STATUS_PAID)
            ->where('created_at', '>=', now()->subDays((int) $this->option('dias')))
            ->where(fn ($query) => $query->doesntHave('invoice')->orWhereHas('invoice', fn ($q) => $q->whereNull('xml_path')))
            ->get();

        $this->info($pedidos->count().' pedido(s) sem nota completa deste lado.');

        $trazidas = 0;

        foreach ($pedidos as $pedido) {
            $invoice = $importer->syncForOrder($pedido);

            if ($invoice) {
                $trazidas++;
                $this->line("  #{$pedido->id} -> nota {$invoice->numero} ({$invoice->status})");
            }
        }

        $this->info("Concluído: {$trazidas} nota(s) trazida(s) do Bling.");

        return self::SUCCESS;
    }
}
