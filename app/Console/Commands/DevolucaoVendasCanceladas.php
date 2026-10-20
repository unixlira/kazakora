<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Models\MercadoPagoPagamento;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\SalesReturnService;
use App\Notifications\FiscalAlertNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Venda cancelada ou estornada com NF-e de venda ainda autorizada e sem
 * devolução (levantamento do DAS de setembro, 2026-10-19: 19 casos).
 *
 * A devolução automática (DevolucaoFiscalAutomatica) só nasce de um CASO de
 * devolução (marketplace_returns, kind=devolucao). Cancelamento no canal e
 * estorno da conciliação do Mercado Pago não abrem caso, e o TikTok/série 3
 * fica de fora de propósito; passada a janela de 24h para cancelar a nota,
 * ninguém emitia a entrada. Este comando acha esses casos.
 *
 * Emitir NÃO é automático: há venda "cancelada" no sistema que a plataforma
 * concluiu (Shopee #2429), extravio indenizado (Shopee #1942) e estorno que
 * o próprio ML cobriu (bpp_covered, #1290/#2287), em que o dinheiro ficou
 * com a loja. Por isso só emite com --pedido, depois de conferir.
 */
class DevolucaoVendasCanceladas extends Command
{
    protected $signature = 'devolucoes:vendas-canceladas
        {--simular : Só lista, não emite nada}
        {--pedido=* : Emite a devolução só destes pedidos (obrigatório para emitir)}
        {--desde=2026-08-01 : Notas de venda autorizadas a partir desta data}
        {--avisar : Com --simular, avisa os admins quando houver caso}';

    protected $description = 'Acha venda cancelada/estornada com NF-e de venda autorizada e sem devolução (e emite a devolução dos pedidos indicados)';

    public function handle(SalesReturnService $devolucoes): int
    {
        $simular = (bool) $this->option('simular');
        $pedidos = array_map('intval', (array) $this->option('pedido'));

        if (! $simular && $pedidos === []) {
            $this->error('Para emitir, informe --pedido=ID (um ou mais). Sem isso, use --simular.');

            return self::FAILURE;
        }

        $casos = $this->casos($devolucoes, $pedidos);

        foreach ($casos as $caso) {
            /** @var Invoice $venda */
            $venda = $caso['venda'];
            $pedido = $venda->order;
            $linha = sprintf('#%d %s %s: NF-e %d-%d R$ %s (%s%s)', $pedido->id, $pedido->origin, $pedido->external_order_id, $venda->serie, $venda->numero,
                number_format((float) $venda->valor_total, 2, ',', '.'), $pedido->status, $pedido->estornado_em ? ', estornado '.\Illuminate\Support\Carbon::parse($pedido->estornado_em)->format('d/m') : '');

            if ($caso['atencao']) {
                $this->warn("{$linha} ATENÇÃO: {$caso['atencao']}");

                continue;
            }

            if ($simular) {
                $this->line($linha);

                continue;
            }

            try {
                $devolucao = $devolucoes->registrar($venda, $caso['quantidades'], "Venda cancelada/estornada no canal ({$pedido->origin} {$pedido->external_order_id})", voltaAoEstoque: false);
                $this->info("{$linha} -> devolução #{$devolucao->id} enviada à SEFAZ (R$ ".number_format((float) $devolucao->total, 2, ',', '.').')');
            } catch (Throwable $e) {
                $this->error("{$linha} -> não emitida: ".$e->getMessage());
            }
        }

        $semAtencao = $casos->filter(fn ($caso) => ! $caso['atencao']);
        $this->info("{$casos->count()} caso(s), {$semAtencao->count()} sem pendência de conferência.");

        if ($simular && $this->option('avisar') && $semAtencao->isNotEmpty()) {
            $this->avisar($semAtencao);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $pedidos
     * @return Collection<int, array{venda: Invoice, quantidades: array<int, int>, atencao: ?string}>
     */
    public function casos(SalesReturnService $devolucoes, array $pedidos = []): Collection
    {
        $cobertosPeloMl = MercadoPagoPagamento::query()->where('status_detalhe', 'bpp_covered')->whereNotNull('order_id')->pluck('order_id')->flip();
        $horas = (int) config('nfe.cancelamento_horas', 24);

        return Invoice::query()
            ->where('status', Invoice::STATUS_AUTHORIZED)
            ->whereNotNull('chave_acesso')
            ->where('autorizada_em', '>=', $this->option('desde') ?: '2026-08-01')
            ->where('autorizada_em', '<=', now()->subHours($horas))
            ->whereHas('order', fn ($q) => $q
                ->whereNotIn('origin', [Order::ORIGIN_SALES_RETURN_INVOICE, Order::ORIGIN_PURCHASE_RETURN_INVOICE, Order::ORIGIN_MANUAL_INVOICE])
                ->where(fn ($q) => $q->where('status', Order::STATUS_CANCELLED)->orWhereNotNull('estornado_em'))
                ->when($pedidos !== [], fn ($q) => $q->whereIn('id', $pedidos)))
            ->with('order.items')
            ->orderBy('id')
            ->get()
            ->map(function (Invoice $venda) use ($devolucoes, $cobertosPeloMl) {
                if ($devolucoes->impedimento($venda)) {
                    return null;
                }

                $quantidades = $devolucoes->itensDevolviveis($venda)
                    ->filter(fn ($item) => $item['disponivel'] > 0)
                    ->mapWithKeys(fn ($item) => [$item['id'] => $item['disponivel']])
                    ->all();

                if ($quantidades === []) {
                    return null;
                }

                $atencao = $cobertosPeloMl->has($venda->order_id)
                    ? 'estorno coberto pelo Mercado Livre (bpp_covered): o dinheiro ficou com a loja, a venda pode valer'
                    : null;

                return ['venda' => $venda, 'quantidades' => $quantidades, 'atencao' => $atencao];
            })
            ->filter()
            ->values();
    }

    private function avisar(Collection $casos): void
    {
        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();
        $lista = $casos->take(10)->map(fn ($caso) => '#'.$caso['venda']->order_id.' (NF-e '.$caso['venda']->serie.'-'.$caso['venda']->numero.')')->implode(', ');

        if ($admins->isNotEmpty()) {
            rescue(fn () => Notification::send($admins, new FiscalAlertNotification(
                "{$casos->count()} venda(s) cancelada(s)/estornada(s) com NF-e autorizada e sem devolução: {$lista}. Confira e emita com devolucoes:vendas-canceladas --pedido=ID."
            )), report: false);
        }
    }
}
