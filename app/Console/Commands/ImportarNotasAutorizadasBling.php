<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Services\Bling\BlingInvoiceImporter;
use Illuminate\Console\Command;

/**
 * Traz para o Kazakora as NF-e que o Bling já AUTORIZOU nos canais
 * delegados a ele (TikTok), em qualquer data (pedido 2026-10-11).
 *
 * Diferente do invoices:sync-bling, nunca transmite nada: nota pendente
 * ou rejeitada no Bling fica como está e só aparece no relatório. Pula o
 * pedido que já tem nota NOSSA (série 2) — esses têm decisão própria.
 * Sem --gravar, só simula.
 */
class ImportarNotasAutorizadasBling extends Command
{
    protected $signature = 'bling:importar-notas-autorizadas
        {--desde=2026-08-01 : Pedidos criados a partir desta data}
        {--gravar : Grava de verdade (sem isso só simula)}';

    protected $description = 'Importa do Bling as NF-e já autorizadas lá, sem transmitir nada à SEFAZ';

    private const SERIE_KAZAKORA = 2;

    public function handle(BlingInvoiceImporter $importer): int
    {
        $canais = (array) config('services.bling.invoice_issuer_channels', []);
        $gravar = (bool) $this->option('gravar');

        $pedidos = Order::query()
            ->with('invoice')
            ->whereIn('origin', $canais)
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED])
            ->where('created_at', '>=', $this->option('desde'))
            ->where(fn ($q) => $q->doesntHave('invoice')->orWhereHas('invoice', fn ($i) => $i->where('status', '!=', 'authorized')->orWhereNull('xml_path')))
            ->orderBy('id')
            ->get();

        $this->info(($gravar ? 'GRAVANDO' : 'SIMULAÇÃO').": {$pedidos->count()} pedido(s) sem nota autorizada completa.");

        $resumo = ['importadas' => 0, 'nossas_serie_2' => 0, 'sem_nota_no_bling' => 0, 'nao_autorizadas' => []];

        foreach ($pedidos as $pedido) {
            if ((int) $pedido->invoice?->serie === self::SERIE_KAZAKORA) {
                $resumo['nossas_serie_2']++;

                continue;
            }

            $r = $importer->importarSeAutorizada($pedido, $gravar);

            if (! $r['nota']) {
                $resumo['sem_nota_no_bling']++;

                continue;
            }

            if ($importer->statusDaSituacao($r['situacao']) !== 'authorized') {
                $resumo['nao_autorizadas'][$r['situacao']] = ($resumo['nao_autorizadas'][$r['situacao']] ?? 0) + 1;
                $this->line("  #{$pedido->id} {$pedido->external_order_id}: nota {$r['nota']['serie']}-{$r['nota']['numero']} situação {$r['situacao']} (fica no Bling)");

                continue;
            }

            $resumo['importadas']++;
            $this->line("  #{$pedido->id} {$pedido->external_order_id}: nota {$r['nota']['serie']}-{$r['nota']['numero']} autorizada".($gravar ? ' -> importada' : ''));
        }

        $this->newLine();
        $this->info(($gravar ? 'Importadas' : 'A importar').": {$resumo['importadas']}");
        $this->info("Puladas (nota nossa série 2): {$resumo['nossas_serie_2']}");
        $this->info("Sem nota no Bling: {$resumo['sem_nota_no_bling']}");
        foreach ($resumo['nao_autorizadas'] as $situacao => $n) {
            $this->info("No Bling sem autorização (situação {$situacao}): {$n}");
        }

        return self::SUCCESS;
    }
}
