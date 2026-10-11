<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Notifications\OrderImportFailedNotification;
use App\Services\MercadoLivre\MercadoLivreAuthService;
use App\Services\MercadoLivre\Services\OrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Importa/sincroniza os pedidos do Mercado Livre pro banco local, filtrado
 * por data direto na API (não busca tudo pra filtrar depois) — pedido real
 * do usuário 2026-08-06 ("só o mês atual", refeito no mesmo dia depois de
 * um primeiro backfill sem escopo de data). Idempotente de propósito:
 * OrderImportService::import() já detecta pedido existente
 * (origin+external_order_id) e só sincroniza status/data, nunca duplica
 * nem debita estoque de novo — seguro rodar quantas vezes quiser, inclusive
 * como reconciliação periódica.
 */
class SyncMercadoLivreOrders extends Command
{
    protected $signature = 'orders:sync-mercadolivre {--desde= : Data inicial (Y-m-d), padrão: início do mês corrente} {--ate= : Data final (Y-m-d), padrão: agora}';

    protected $description = 'Importa/sincroniza os pedidos do Mercado Livre pro banco local, por período';

    public function handle(MercadoLivreAuthService $auth, OrderService $orders, OrderImportService $importer): int
    {
        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)->first();
        $token = $auth->currentToken();

        if (! $account?->isConnected() || ! $token) {
            $this->error('Mercado Livre não está conectado.');

            return self::FAILURE;
        }

        $from = $this->option('desde') ? Carbon::parse($this->option('desde'))->startOfDay() : now()->startOfMonth();
        $to = $this->option('ate') ? Carbon::parse($this->option('ate'))->endOfDay() : now();

        $this->info("Buscando pedidos de {$from->toDateString()} até {$to->toDateString()}...");
        $ids = $orders->listOrderIds((int) $token->ml_user_id, $from, $to);
        $this->info(count($ids).' pedido(s) encontrado(s) no período.');

        $imported = 0;
        $falhas = [];

        foreach ($ids as $id) {
            try {
                $importer->import(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, $id, viaVarredura: true);
                $imported++;
            } catch (Throwable $exception) {
                $falhas[] = ['sn' => (string) $id, 'message' => $exception->getMessage()];
                $this->warn("Pedido {$id}: {$exception->getMessage()}");
            }
        }

        $this->info('Concluído: '.$imported.' sincronizado(s), '.count($falhas).' com erro.');

        // Mesma regra da Shopee, pelo mesmo motivo (incidente 2026-09-10):
        // venda que não entra não aparece em tela nenhuma, porque toda tela
        // mostra o que existe no banco. Falha aqui grita por e-mail.
        if ($falhas !== []) {
            $janela = $from->toDateString().' a '.$to->toDateString();

            Log::error('mercadolivre.sync.import_failed', [
                'janela' => $janela,
                'quantidade' => count($falhas),
                'vendas' => $falhas,
            ]);

            $aviso = new OrderImportFailedNotification('Mercado Livre', $falhas, $janela);
            $destino = config('services.alerts.email');

            if ($destino) {
                Notification::route('mail', $destino)->notify($aviso);
            }

            $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, $aviso);
            }
        }

        return self::SUCCESS;
    }
}
