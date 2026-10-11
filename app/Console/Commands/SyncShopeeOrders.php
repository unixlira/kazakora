<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Models\User;
use App\Notifications\OrderImportFailedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Importa/sincroniza os pedidos da Shopee pro banco local, filtrado por
 * data direto na API (não busca tudo pra filtrar depois) — pedido real do
 * usuário 2026-08-06 ("só o mês atual", refeito no mesmo dia depois de um
 * primeiro backfill sem escopo de data). Idempotente, seguro rodar de novo.
 */
class SyncShopeeOrders extends Command
{
    protected $signature = 'orders:sync-shopee {--desde= : Data inicial (Y-m-d), padrão: início do mês corrente} {--ate= : Data final (Y-m-d), padrão: agora}';

    protected $description = 'Importa/sincroniza os pedidos da Shopee pro banco local, por período';

    public function handle(ShopeeDriver $driver, OrderImportService $importer): int
    {
        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->first();

        if (! $account?->isConnected()) {
            $this->error('Shopee não está conectada.');

            return self::FAILURE;
        }

        $from = $this->option('desde') ? Carbon::parse($this->option('desde'))->startOfDay() : now()->startOfMonth();
        $to = $this->option('ate') ? Carbon::parse($this->option('ate'))->endOfDay() : now();

        $this->info("Buscando pedidos de {$from->toDateString()} até {$to->toDateString()}...");
        $sns = $driver->listOrderSns($from, $to);
        $this->info(count($sns).' pedido(s) encontrado(s) no período.');

        $imported = 0;
        $falhas = [];

        foreach ($sns as $sn) {
            try {
                $importer->import(MarketplaceAccount::CHANNEL_SHOPEE, $sn, viaVarredura: true);
                $imported++;
            } catch (Throwable $exception) {
                $falhas[] = ['sn' => $sn, 'message' => $exception->getMessage()];
                $this->warn("Pedido {$sn}: {$exception->getMessage()}");
            }
        }

        $this->info('Concluído: '.$imported.' sincronizado(s), '.count($falhas).' com erro.');

        // INCIDENTE 2026-09-10: até aqui a falha era SÓ o warn acima — e
        // isto roda por cron, num console que ninguém lê. A venda
        // 260910M2M4KAK5 falhou a cada hora, o dia inteiro, em silêncio:
        // ficou 24h sem nota e sem etiqueta, e quem descobriu foi o
        // usuário abrindo o painel da Shopee.
        //
        // Venda que não entra é o pior erro possível deste sistema: não
        // aparece em tela nenhuma, porque toda tela mostra o que existe no
        // banco. A única defesa é gritar — log de erro E notificação pros
        // admins, com o número da venda, pra dar pra ir atrás na mão.
        if ($falhas !== []) {
            $janela = $from->toDateString().' a '.$to->toDateString();

            Log::channel('shopee')->error('shopee.sync.import_failed', [
                'janela' => $janela,
                'quantidade' => count($falhas),
                'vendas' => $falhas,
            ]);

            $aviso = new OrderImportFailedNotification('Shopee', $falhas, $janela);
            $destino = config('services.alerts.email');

            if ($destino) {
                Notification::route('mail', $destino)->notify($aviso);
            }

            // Os admins continuam recebendo (e-mail + sino da tela) — o
            // endereço de alerta acima é um a mais, não um no lugar.
            $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, $aviso);
            }
        }

        return self::SUCCESS;
    }
}
