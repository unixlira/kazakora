<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Corrige o nome dos itens de pedidos ainda não despachados (18/11/2026).
 *
 * Os products.name viraram título de vitrine ("... + PDF Grátis") e os
 * pedidos criados depois disso gravaram esse título no item, que aparece no
 * KoraSync e no xProd da NF-e. Aqui:
 *  - pedido de marketplace: relê o pedido no canal (só leitura, mesmo
 *    importOrder() da importação) e grava o título do anúncio;
 *  - pedido da loja: grava Product::nomeParaPedido() (nome interno).
 *
 * Nota autorizada não é tocada: só conta quantas saíram com o nome de vitrine.
 * Sem --aplicar só mostra o que mudaria.
 */
class CorrigirNomeDoItemPeloCanal extends Command
{
    protected $signature = 'pedidos:nome-do-item-pelo-canal
        {--desde= : Data/hora de criação do pedido (horário do servidor)}
        {--aplicar : Grava; sem isso só lista}';

    protected $description = 'Reescreve o nome dos itens de pedidos não despachados com o título do anúncio do canal (ou o nome interno, na loja).';

    public function handle(MarketplaceDriverManager $manager): int
    {
        $desde = $this->option('desde');
        if (! $desde) {
            $this->error('Informe --desde="AAAA-MM-DD HH:MM".');

            return self::FAILURE;
        }

        $aplicar = (bool) $this->option('aplicar');

        $pedidos = Order::query()
            ->with(['items.product', 'invoice'])
            ->where('created_at', '>=', $desde)
            ->whereNotIn('status', [Order::STATUS_SHIPPED, Order::STATUS_COMPLETED, Order::STATUS_CANCELLED])
            ->orderBy('id')
            ->get();

        $corrigidos = 0;
        $itensCorrigidos = 0;
        $falhas = 0;

        foreach ($pedidos as $pedido) {
            $novos = [];

            if ($pedido->origin === Order::ORIGIN_STORE) {
                foreach ($pedido->items as $item) {
                    if ($item->product) {
                        $novos[$item->id] = $item->product->nomeParaPedido();
                    }
                }
            } else {
                if (! $pedido->external_order_id) {
                    continue;
                }

                try {
                    $dados = $manager->driver($pedido->origin)->importOrder((string) $pedido->external_order_id);
                } catch (Throwable $e) {
                    $this->warn("#{$pedido->id} {$pedido->origin}: não consegui ler no canal ({$e->getMessage()})");
                    $falhas++;

                    continue;
                }

                $doCanal = collect($dados['items'] ?? []);

                foreach ($pedido->items as $item) {
                    $par = $doCanal->first(fn ($i) => (string) ($i['external_id'] ?? '') === (string) $item->external_item_id
                            && ($i['external_model_id'] ?? null) == $item->external_model_id)
                        ?? $doCanal->first(fn ($i) => (string) ($i['external_id'] ?? '') === (string) $item->external_item_id);

                    if ($par && trim((string) ($par['external_name'] ?? '')) !== '') {
                        $novos[$item->id] = OrderImportService::nomeDoItem($par, $item->product);
                    } elseif ($item->product && ! OrderImportService::nomeProvisorio($item->product_name) && $item->product_name === $item->product->name) {
                        // Canal sem título para este item: ao menos tira o nome de vitrine.
                        $novos[$item->id] = $item->product->nomeParaPedido();
                    }
                }
            }

            $mudou = false;
            foreach ($pedido->items as $item) {
                $novo = $novos[$item->id] ?? null;
                if ($novo === null || $novo === $item->product_name) {
                    continue;
                }

                $nota = $pedido->invoice?->status === Invoice::STATUS_AUTHORIZED ? ' [nota já autorizada, não mexo nela]' : '';
                $this->line("#{$pedido->id} {$pedido->origin} item {$item->id}: \"{$item->product_name}\" -> \"{$novo}\"{$nota}");

                if ($aplicar) {
                    $item->forceFill(['product_name' => $novo])->save();
                }

                $mudou = true;
                $itensCorrigidos++;
            }

            $corrigidos += $mudou ? 1 : 0;
        }

        $this->info(($aplicar ? 'Corrigidos' : 'Seriam corrigidos').": {$corrigidos} pedido(s), {$itensCorrigidos} item(ns), de {$pedidos->count()} não despachados. Falhas de leitura no canal: {$falhas}.");

        return self::SUCCESS;
    }
}
