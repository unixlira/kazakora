<?php

namespace App\Modules\Fiscal\Support;

use App\Modules\Admin\Support\AlertasAdmin;
use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Models\MercadoPagoPagamento;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NF-e de venda de pedido cancelado no canal, cancelada na SEFAZ sem multa
 * (dentro das 24h). Usado na hora em que o webhook cancela o pedido
 * (CancelarNotaDoPedidoCanceladoJob, Lira 2026-10-29: "cancelar
 * imediatamente ao receber o webhook; se der erro, marcar para checagem")
 * e pela rodada de 10 min (nfe:cancelar-pedidos-cancelados), que fica de
 * rede para o que o webhook não pegou.
 *
 * Nunca cancela fora do prazo: só nota autorizada há menos de
 * 24h − nfe.cancelamento_auto_folga_minutos, e sem confirmar multa.
 * Erro ou nota que não dá para cancelar sozinho (sem protocolo de
 * autorização gravado, caso das notas que vêm do Bling) vira alerta
 * nfe_cancelamento em Sistema › Alertas.
 */
class CancelamentoAutomaticoDaNota
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly AlertasAdmin $alertas,
    ) {}

    /** Só o servidor de produção (APP_URL kazakora.com) age sozinho; ver config nfe.automatico_host. */
    public static function servidorDeProducao(): bool
    {
        return strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)) === strtolower((string) config('nfe.automatico_host'));
    }

    /**
     * Notas que dá para cancelar sozinho agora.
     *
     * @return Collection<int, Invoice>
     */
    public function casos(?int $orderId = null): Collection
    {
        return $this->base($orderId)->whereNotNull('protocolo_autorizacao')->get();
    }

    /**
     * Pedido cancelado, nota no prazo, mas sem o protocolo de autorização
     * gravado (nota emitida pelo Bling: série 3 do TikTok e da Amazon). Sem
     * protocolo a SEFAZ não aceita o evento: vai para checagem.
     *
     * @return Collection<int, Invoice>
     */
    public function semProtocolo(?int $orderId = null): Collection
    {
        return $this->base($orderId)->whereNull('protocolo_autorizacao')->get();
    }

    /** Cancela na SEFAZ; se der erro, abre o alerta. Devolve true se a nota ficou cancelada. */
    public function cancelar(Invoice $nota): bool
    {
        $lock = Cache::lock("nfe-cancelamento-automatico:{$nota->id}", 120);

        if (! $lock->get()) {
            return false;
        }

        try {
            $nota->refresh();
            $pedido = $nota->order;

            if ($nota->status !== Invoice::STATUS_AUTHORIZED) {
                return $nota->status === Invoice::STATUS_CANCELLED;
            }

            try {
                $this->invoices->cancelInvoice($nota, "Pedido cancelado no canal ({$pedido->origin} {$pedido->external_order_id})");
            } catch (Throwable $e) {
                Log::warning('nfe.cancelamento_automatico.falhou', ['order_id' => $pedido->id, 'invoice_id' => $nota->id, 'erro' => $e->getMessage()]);
                $this->marcarParaChecagem($nota, 'A SEFAZ não cancelou: '.mb_substr($e->getMessage(), 0, 300));

                return false;
            }

            rescue(fn () => $this->alertas->resolver(AlertasAdmin::NFE_CANCELAMENTO, $this->chave($nota)), report: false);

            return true;
        } finally {
            $lock->release();
        }
    }

    /** Abre (ou atualiza) o alerta da nota em Sistema › Alertas. */
    public function marcarParaChecagem(Invoice $nota, string $motivo): void
    {
        $pedido = $nota->order;
        $prazo = $nota->autorizada_em?->copy()->addHours((int) config('nfe.cancelamento_horas', 24));

        rescue(fn () => $this->alertas->disparar(
            AlertasAdmin::NFE_CANCELAMENTO,
            $this->chave($nota),
            "NF-e {$nota->serie}-{$nota->numero} do pedido #{$pedido->id} cancelado não foi cancelada",
            "{$pedido->origin} {$pedido->external_order_id}, R$ ".number_format((float) $nota->valor_total, 2, ',', '.').'. '.$motivo
                .($prazo ? ' Prazo sem multa até '.$prazo->format('d/m H:i').'.' : ''),
            "/admin/pedidos/{$pedido->id}",
        ), report: false);
    }

    private function chave(Invoice $nota): string
    {
        return 'invoice:'.$nota->id;
    }

    /** @return Builder<Invoice> */
    private function base(?int $orderId): Builder
    {
        $limite = now()->subMinutes((int) config('nfe.cancelamento_horas', 24) * 60 - (int) config('nfe.cancelamento_auto_folga_minutos', 30));
        $valeram = collect(config('nfe.vendas_que_valeram', []))->map(fn ($id) => (int) $id)->all();
        $cobertosPeloMl = MercadoPagoPagamento::query()->where('status_detalhe', 'bpp_covered')->whereNotNull('order_id')->pluck('order_id')->all();

        return Invoice::query()
            ->where('status', Invoice::STATUS_AUTHORIZED)
            ->whereNotNull('chave_acesso')
            ->where('autorizada_em', '>', $limite)
            ->when($orderId, fn ($q) => $q->where('order_id', $orderId))
            ->whereHas('order', fn ($q) => $q
                ->where('status', Order::STATUS_CANCELLED)
                ->whereNotIn('origin', [Order::ORIGIN_SALES_RETURN_INVOICE, Order::ORIGIN_PURCHASE_RETURN_INVOICE, Order::ORIGIN_MANUAL_INVOICE])
                ->whereNotIn('id', array_merge($valeram, $cobertosPeloMl)))
            ->with('order')
            ->orderBy('autorizada_em');
    }
}
