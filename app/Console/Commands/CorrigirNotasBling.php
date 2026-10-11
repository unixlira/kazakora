<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Services\Bling\BlingInvoiceImporter;
use Illuminate\Console\Command;

/**
 * Corrige as NF-e do TikTok travadas no Bling (2026-10-11): alinha CFOP,
 * NCM, CEST e origem dos itens ao nosso cadastro (CFOP interestadual 6102,
 * ordem do Lira) e, com --enviar, manda à SEFAZ pelo caminho normal do
 * importador (que confere de novo antes de transmitir).
 *
 * Trava de duplicidade: não envia se o pedido já tiver nota AUTORIZADA
 * deste lado (ex.: nota nossa série 2).
 *
 * Sem pedidos, pega os do canal com nota pendente/rejeitada nos últimos
 * --dias. Sem --aplicar, só mostra o que mudaria.
 */
class CorrigirNotasBling extends Command
{
    protected $signature = 'bling:corrigir-notas
        {pedidos?* : IDs dos pedidos (padrão: todos do TikTok com nota pendente ou rejeitada)}
        {--dias=30 : Janela quando não informar pedidos}
        {--aplicar : Grava a correção no Bling}
        {--enviar : Depois de corrigir, envia à SEFAZ}
        {--forcar : Reenvia nota rejeitada mesmo sem mudança (ex.: SEFAZ estava fora do ar)}';

    protected $description = 'Alinha as NF-e travadas do Bling ao cadastro (CFOP/NCM) e, opcionalmente, envia';

    public function handle(BlingInvoiceImporter $importer): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $enviar = $aplicar && $this->option('enviar');

        $pedidos = Order::query()->with('invoice')
            ->when($this->argument('pedidos'), fn ($q, $ids) => $q->whereIn('id', $ids),
                fn ($q) => $q->where('origin', Order::ORIGIN_TIKTOK_SHOP)
                    ->where('status', Order::STATUS_PAID)
                    ->where('created_at', '>=', now()->subDays((int) $this->option('dias')))
                    ->whereHas('invoice', fn ($i) => $i->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_REJECTED])->where('serie', 3)))
            ->orderBy('id')->get();

        $this->info(($aplicar ? ($enviar ? 'CORRIGINDO E ENVIANDO' : 'CORRIGINDO') : 'SIMULAÇÃO').": {$pedidos->count()} pedido(s)");

        foreach ($pedidos as $pedido) {
            $linha = "#{$pedido->id} {$pedido->external_order_id} ({$pedido->shipping_state})";

            if ($pedido->invoice?->status === Invoice::STATUS_AUTHORIZED) {
                $this->warn("{$linha}: já tem nota autorizada ({$pedido->invoice->serie}-{$pedido->invoice->numero}) — pulado para não duplicar");

                continue;
            }

            if ((int) $pedido->invoice?->serie === 2) {
                $this->warn("{$linha}: tem nota nossa série 2 ({$pedido->invoice->numero}) — pulado para não duplicar");

                continue;
            }

            if ((float) $pedido->total <= 0) {
                $this->warn("{$linha}: pedido de R$ 0,00 (amostra/brinde) — não vira nota de venda, pulado");

                continue;
            }

            // Bling: 3 req/s para a conta inteira (nota e etiqueta dividem).
            usleep(1_200_000);

            try {
                $r = $importer->alinharNotaAoCadastro($pedido, $aplicar);
            } catch (\Throwable $e) {
                $this->error("{$linha}: ".mb_substr($e->getMessage(), 0, 160).' — fica para a próxima rodada');

                continue;
            }
            $this->line("{$linha}: nota {$r['numero']} situação {$r['situacao']}");

            if ($r['erro']) {
                $this->error("   {$r['erro']}");

                continue;
            }

            foreach ($r['mudancas'] ?: ['nada a mudar'] as $m) {
                $this->line("   {$m}");
            }

            if ($enviar) {
                usleep(1_200_000);
                if ($this->option('forcar')) {
                    $importer->esquecerUltimaTentativa($pedido);
                }
                $invoice = $importer->syncForOrder($pedido->fresh());
                $this->line('   -> '.($invoice ? "nota {$invoice->serie}-{$invoice->numero}: {$invoice->status}".($invoice->motivo_rejeicao ? " ({$invoice->motivo_rejeicao})" : '') : 'sem retorno do Bling'));
            }
        }

        $this->info('Fim.');

        return self::SUCCESS;
    }
}
