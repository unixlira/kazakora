<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Services\Bling\BlingOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Importa/sincroniza os pedidos do TikTok Shop pro banco local via Bling
 * (pedido explícito 2026-08-31 — ver TikTokShopDriver/BlingOrderService
 * pro porquê da ponte). Mesmo padrão real do orders:sync-mercadolivre/
 * orders:sync-shopee: filtra por data direto na API, idempotente
 * (OrderImportService::import() já detecta pedido existente por
 * origin+external_order_id, nunca duplica).
 *
 * Sem webhook do lado do Bling ainda (a API do Bling não documenta um
 * publicamente pra pedidos/vendas — só notificações internas da UI) —
 * pedido explícito 2026-08-31 de sincronia "em tempo real": em vez de
 * esperar um webhook, este comando roda de 2 em 2 minutos escopado só pro
 * dia de hoje (--desde=hoje, ver routes/console.php), o que é barato
 * (poucos pedidos, cache de 2min em BlingOrderService::listOrders() evita
 * bater na API de novo se rodar 2x no mesmo intervalo) — mais um passe
 * completo do mês inteiro de hora em hora como rede de segurança. Mesma
 * idempotência de sempre (import() já detecta pedido existente por
 * origin+external_order_id, nunca duplica) — seguro rodar com
 * sobreposição de período.
 */
class SyncTikTokShopOrders extends Command
{
    protected $signature = 'orders:sync-tiktok {--desde= : Data inicial (Y-m-d), padrão: início do mês corrente} {--ate= : Data final (Y-m-d), padrão: agora}';

    protected $description = 'Importa/sincroniza os pedidos do TikTok Shop (via Bling) pro banco local, por período';

    public function handle(BlingOrderService $blingOrders, OrderImportService $importer): int
    {
        if (! $blingOrders->tiktokLojaId()) {
            $this->error('Loja do TikTok Shop ainda não configurada — defina em Integrações > Bling qual loja do Bling é o TikTok Shop.');

            return self::FAILURE;
        }

        $from = $this->option('desde') ? Carbon::parse($this->option('desde'))->startOfDay() : now()->startOfMonth();
        $to = $this->option('ate') ? Carbon::parse($this->option('ate'))->endOfDay() : now();

        $this->info("Buscando pedidos do TikTok Shop (via Bling) de {$from->toDateString()} até {$to->toDateString()}...");
        $numbers = $blingOrders->listRecentOrderNumbers($from, $to);
        $this->info(count($numbers).' pedido(s) encontrado(s) no período.');

        $imported = 0;
        $failed = 0;

        foreach ($numbers as $number) {
            try {
                $importer->import(Order::ORIGIN_TIKTOK_SHOP, $number);
                $imported++;
            } catch (Throwable $exception) {
                $failed++;
                $this->warn("Pedido {$number}: {$exception->getMessage()}");
            }
        }

        $this->info("Concluído: {$imported} sincronizado(s), {$failed} com erro.");

        return self::SUCCESS;
    }
}
