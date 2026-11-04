<?php

namespace App\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\AmazonDriver;
use App\Modules\Marketplace\Models\AmazonNotificacao;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\CorreiosAutoShipping;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Services\Amazon\AmazonClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aviso ORDER_CHANGE da Amazon (lido da fila SQS por
 * amazon:notificacoes-ouvir) aplicado no pedido.
 *
 * - Pedido que já existe aqui: o status do aviso vai por
 *   OrderImportService::syncStatus() — a mesma trava de sempre (nada volta
 *   pra trás, cancelado devolve estoque e avisa se já tem NF-e autorizada;
 *   nota nunca é cancelada sozinha).
 * - Pedido novo e pago (Unshipped): importa direto da SP-API (Orders API,
 *   AmazonDriver::importOrder(); o Bling não entra na Amazon desde
 *   04/11/2026). NF-e do KazaKora e envio DBA seguem o fluxo de sempre.
 *   Falha passageira da API tenta de novo em 5, 10 e 20 min; depois, a
 *   varredura de hora em hora (orders:sync-amazon) pega.
 * - Resto (Pending, pedido que nem existe e já veio cancelado): só registra.
 */
class ProcessarNotificacaoAmazon implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public function __construct(public readonly int $notificacaoId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [300, 600, 1200];
    }

    public function handle(OrderImportService $importer): void
    {
        $aviso = AmazonNotificacao::find($this->notificacaoId);

        if (! $aviso || $aviso->status !== AmazonNotificacao::STATUS_RECEBIDA) {
            return;
        }

        if ($aviso->notification_type !== 'ORDER_CHANGE' || ! $aviso->amazon_order_id) {
            $aviso->concluir(AmazonNotificacao::STATUS_IGNORADA, "Tipo {$aviso->notification_type} sem pedido: nada a fazer.");

            return;
        }

        $statusAmazon = (string) $aviso->order_status;
        $novo = in_array($statusAmazon, ['Unshipped', 'PartiallyShipped', 'Shipped', 'Canceled', 'Unfulfillable'], true)
            ? AmazonDriver::mapOrderStatus($statusAmazon)
            : null;

        $order = Order::query()
            ->where('origin', Order::ORIGIN_AMAZON)
            ->where('external_order_id', $aviso->amazon_order_id)
            ->first();

        if ($order) {
            if ($novo === null) {
                $aviso->concluir(AmazonNotificacao::STATUS_IGNORADA, "Pedido #{$order->id}: status {$statusAmazon} na Amazon não muda nada aqui.");

                return;
            }

            // DBA (03/11/2026): agendar o pacote no Ponto de Envio já deixa o
            // pedido "Shipped" na Amazon, com o pacote ainda na bancada
            // (EasyShipShipmentStatus PendingDropOff). Enquanto não foi
            // postado, segue pago, na fila do KoraSync.
            if ($statusAmazon === 'Shipped' && $order->status === Order::STATUS_PAID && $this->dbaAindaNaLoja($order)) {
                $aviso->concluir(AmazonNotificacao::STATUS_IGNORADA, "Pedido #{$order->id}: Shipped na Amazon = pacote DBA agendado, ainda não postado; segue pago.");

                return;
            }

            $antes = $order->status;
            $order = $importer->syncStatus($order, $novo, $statusAmazon);

            $aviso->concluir(AmazonNotificacao::STATUS_PROCESSADA, "Pedido #{$order->id}: {$antes} → {$order->status} ({$statusAmazon} na Amazon).");

            return;
        }

        if ($novo !== Order::STATUS_PAID) {
            $aviso->concluir(AmazonNotificacao::STATUS_IGNORADA, "Pedido ainda não existe aqui e está {$statusAmazon} na Amazon: não importado.");

            return;
        }

        try {
            $order = $importer->import(MarketplaceAccount::CHANNEL_AMAZON, $aviso->amazon_order_id);
        } catch (Throwable $e) {
            if ($this->attempts() < $this->tries) {
                $aviso->update(['resultado' => "Tentativa {$this->attempts()}: {$e->getMessage()}"]);
                $this->release($this->backoff()[$this->attempts() - 1] ?? 1200);

                return;
            }

            Log::channel('amazon')->warning('amazon.notificacao.import_desistiu', [
                'amazon_order_id' => $aviso->amazon_order_id,
                'erro' => $e->getMessage(),
            ]);

            $aviso->concluir(AmazonNotificacao::STATUS_FALHOU, "Não importado após {$this->tries} tentativas ({$e->getMessage()}). A varredura de hora em hora tenta de novo.");

            return;
        }

        $aviso->concluir(AmazonNotificacao::STATUS_PROCESSADA, $order
            ? "Pedido #{$order->id} importado pelo aviso da Amazon."
            : 'Importação não criou pedido.');
    }

    /** Pedido sem pré-postagem dos Correios cujo pacote DBA ainda não saiu da loja. Sem resposta da Amazon, não arrisca tirar da fila. */
    private function dbaAindaNaLoja(Order $order): bool
    {
        if (app(CorreiosAutoShipping::class)->geradaPara($order)) {
            return false;
        }

        try {
            $pedido = app(AmazonClient::class)->get("/orders/v0/orders/{$order->external_order_id}")['payload'] ?? [];
        } catch (Throwable) {
            return true;
        }

        return AmazonDriver::statusDoPedido($pedido) === Order::STATUS_PAID;
    }
}
