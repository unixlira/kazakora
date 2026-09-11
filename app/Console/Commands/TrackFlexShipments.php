<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\FlexControlService;
use App\Services\MercadoLivre\Services\ShipmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Acompanha os envios Flex depois que saem daqui (2026-09-11).
 *
 * Duas coisas, nesta ordem:
 *
 * 1. Reconsulta no Mercado Livre o envio que ainda pode mudar — o que não
 *    chegou num estado final, a venda cancelada sem ninguém ter conferido o
 *    produto, e a entrega recente (uma devolução começa DEPOIS da entrega).
 *    Só GET; o webhook de shipments continua sendo o caminho principal,
 *    isto é a garantia pro webhook perdido.
 *
 * 2. Reavalia os alertas de todos os envios da janela, sem chamar o ML: o
 *    "entregador não iniciou a rota" depende só do relógio passar.
 *
 * `--sem-notificar` existe pra primeira carga: marcar o histórico inteiro
 * como já avisado sem despejar dezenas de notificações de uma vez.
 */
class TrackFlexShipments extends Command
{
    protected $signature = 'flex:acompanhar-envios
        {--dias=60 : Quantos dias de envios olhar}
        {--sem-notificar : Registra os alertas sem mandar notificação}
        {--sem-canal : Só reavalia os alertas, sem consultar o Mercado Livre}';

    protected $description = 'Atualiza o status real dos envios Flex no Mercado Livre e recalcula os alertas (devolução, cancelada fora da loja, rota não iniciada)';

    public function handle(ShipmentService $shipments, FlexControlService $controle): int
    {
        $dias = max(1, (int) $this->option('dias'));
        $notificar = ! $this->option('sem-notificar');

        // Vale também pra reavaliação que o syncOrderStatusFromShipment faz
        // por dentro — senão a primeira carga notificaria do mesmo jeito.
        FlexControlService::$silenciar = ! $notificar;

        $envios = $controle->query()
            ->where('channel_shipments.created_at', '>=', now()->subDays($dias))
            ->whereNotNull('external_shipment_id')
            ->get();

        $consultados = 0;
        $falhas = 0;

        $conectado = MarketplaceAccount::query()
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->first()?->isConnected();

        if (! $this->option('sem-canal') && $conectado) {
            foreach ($envios->filter(fn (ChannelShipment $envio) => $this->precisaConsultar($envio)) as $envio) {
                try {
                    // syncOrderStatusFromShipment já grava o espelho do canal
                    // e reavalia os alertas deste envio.
                    $shipments->syncOrderStatusFromShipment($envio);
                    $consultados++;
                } catch (Throwable $exception) {
                    $falhas++;
                    Log::channel(config('mercadolivre.log_channel'))->warning('flex.acompanhar.consulta_falhou', [
                        'envio' => $envio->id,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        }

        // O webhook/sync acima já reavaliou quem foi consultado com
        // notificação ligada; aqui o resto (e a primeira carga sem aviso).
        $comAlerta = 0;

        foreach ($envios as $envio) {
            $envio->refresh();
            $controle->reavaliar($envio, $notificar);

            if ($envio->flex_alerts) {
                $comAlerta++;
            }
        }

        $this->info("{$envios->count()} envio(s) Flex nos últimos {$dias} dias · {$consultados} consultado(s) no ML · {$falhas} falha(s) · {$comAlerta} com alerta aberto.");

        return self::SUCCESS;
    }

    /**
     * Estado final e já conferido não precisa gastar chamada: entregue há
     * mais de 15 dias sem venda cancelada, ou cancelado no canal com a
     * conferência feita aqui.
     */
    private function precisaConsultar(ChannelShipment $envio): bool
    {
        if (! $envio->channel_status_checked_at) {
            return true;
        }

        $cancelada = $envio->order?->status === Order::STATUS_CANCELLED;
        $conferidoHaPouco = $envio->channel_status_checked_at->gt(now()->subHours(6));

        if (in_array($envio->channel_status, ['delivered', 'cancelled'], true)) {
            // Venda cancelada: consulta de 6 em 6 horas até alguém conferir
            // o produto aqui — o ML pode registrar a devolução nesse meio.
            if ($cancelada) {
                return $envio->return_resolution === null && ! $conferidoHaPouco;
            }

            return $envio->channel_status === 'delivered'
                && ($envio->channel_delivered_at?->gt(now()->subDays(15)) ?? true)
                && ! $conferidoHaPouco;
        }

        return true;
    }
}
