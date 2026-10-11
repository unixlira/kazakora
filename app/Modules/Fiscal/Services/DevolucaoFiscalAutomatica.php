<?php

namespace App\Modules\Fiscal\Services;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Marketplace\Models\MarketplaceReturn;
use App\Notifications\FiscalAlertNotification;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Nota de devolução automática (pedido 2026-10-11: "quando for aberta
 * reclamação de devolução, os dados fiscais estejam prontos e a emissão da
 * nota de devolução iniciada").
 *
 * 1. Caso de devolução aberto → acha a NF-e de venda, confere o dado fiscal
 *    dos itens e deixa a NF-e de ENTRADA (1202/2202) montada como rascunho
 *    (SalesReturnService, sem emitir).
 * 2. Produto chegou (entregue/conferida, veredito diferente de "não
 *    recebemos") → emite o rascunho.
 * 3. Caso cancelado, encerrado sem o produto voltar ou "não recebemos" →
 *    descarta o rascunho; nenhuma nota sai.
 *
 * Fica de fora: TikTok (o canal emite a devolução — nota 2567 foi cancelada
 * por duplicidade) e venda cuja nota é do Bling (série 3).
 * Estoque NÃO mexe aqui: segue o botão "devolver ao estoque" da tela.
 */
class DevolucaoFiscalAutomatica
{
    public const PREPARADA = 'preparada';

    public const EMITIDA = 'emitida';

    public const DESCARTADA = 'descartada';

    public const PELO_CANAL = 'pelo_canal';

    public const SEM_NOTA = 'sem_nota';

    public const PENDENCIA = 'pendencia';

    public function __construct(private readonly SalesReturnService $devolucoes)
    {
    }

    public function processar(MarketplaceReturn $caso): void
    {
        if ($caso->kind !== MarketplaceReturn::KIND_DEVOLUCAO) {
            return;
        }

        if ($this->deveDescartar($caso)) {
            $this->descartar($caso);

            return;
        }

        if (! $caso->devolucao_order_id) {
            $this->preparar($caso);
            $caso->refresh();
        }

        if ($caso->devolucao_fiscal_status === self::PREPARADA && $this->produtoChegou($caso)) {
            GenerateInvoiceJob::dispatch($caso->devolucao_order_id);
            $this->marcar($caso, self::EMITIDA, 'Produto chegou: NF-e de devolução enviada à SEFAZ.');
        }
    }

    public function preparar(MarketplaceReturn $caso): void
    {
        $pedido = $caso->order()->with(['invoice', 'items.product.fiscalData'])->first();

        if (! $pedido) {
            $this->marcar($caso, self::SEM_NOTA, 'Caso sem pedido no Kazakora.');

            return;
        }

        if (in_array($pedido->origin, SalesReturnService::CANAIS_QUE_EMITEM_DEVOLUCAO, true) || (int) $pedido->invoice?->serie === 3) {
            $this->marcar($caso, self::PELO_CANAL, 'A devolução fiscal é emitida pelo canal/Bling, não por aqui.');

            return;
        }

        $venda = $pedido->invoice;

        if (! $venda || ($impedimento = $this->devolucoes->impedimento($venda))) {
            $this->marcar($caso, self::SEM_NOTA, $impedimento ?? 'Pedido sem NF-e de venda autorizada.');

            return;
        }

        // Dado fiscal pronto: item sem NCM trava a nota de entrada.
        $faltando = [];
        foreach ($pedido->items as $item) {
            if (! $item->product) {
                continue;
            }
            $fiscal = $item->product->fiscalData ?? ProductFiscalData::query()->create(['product_id' => $item->product->id, ...ProductFiscalData::defaultMeiAttributes()]);
            if (! $fiscal->ncm) {
                $faltando[] = $item->product_name;
            }
        }

        if ($faltando !== []) {
            $this->marcar($caso, self::PENDENCIA, 'Falta NCM no cadastro: '.implode(', ', $faltando).'.');
            $this->avisar($caso, 'Devolução sem dado fiscal: falta NCM em '.implode(', ', $faltando).'. Complete o cadastro para a nota de devolução sair.');

            return;
        }

        $quantidades = $this->devolucoes->itensDevolviveis($venda)
            ->filter(fn ($item) => $item['disponivel'] > 0)
            ->mapWithKeys(fn ($item) => [$item['id'] => $item['disponivel']])
            ->all();

        if ($quantidades === []) {
            $this->marcar($caso, self::SEM_NOTA, 'Todos os itens dessa venda já têm devolução emitida.');

            return;
        }

        try {
            $rascunho = $this->devolucoes->registrar(
                $venda,
                $quantidades,
                trim(($caso->reason_label ?: 'Devolução').' — caso '.$caso->channel.' '.$caso->external_id),
                voltaAoEstoque: false,
                emitirAgora: false,
            );
        } catch (Throwable $e) {
            $this->marcar($caso, self::PENDENCIA, mb_substr($e->getMessage(), 0, 400));

            return;
        }

        $caso->forceFill(['devolucao_order_id' => $rascunho->id])->save();
        $this->marcar($caso, self::PREPARADA, "NF-e de entrada montada (pedido #{$rascunho->id}); sai quando o produto chegar.");
    }

    private function descartar(MarketplaceReturn $caso): void
    {
        $rascunho = $caso->devolucaoOrder()->with('invoice')->first();

        if ($rascunho && ! $rascunho->invoice) {
            $rascunho->items()->delete();
            $rascunho->delete();
            $caso->forceFill(['devolucao_order_id' => null])->save();
            $this->marcar($caso, self::DESCARTADA, 'Produto não voltou: nota de devolução descartada, nenhuma nota saiu.');
        }
    }

    private function deveDescartar(MarketplaceReturn $caso): bool
    {
        if ($caso->devolucao_fiscal_status === self::EMITIDA) {
            return false;
        }

        return $caso->situacao === MarketplaceReturn::CANCELADA
            || $caso->verdict === 'nao_recebido'
            || ($caso->situacao === MarketplaceReturn::ENCERRADA && ! $caso->received_at && ! $caso->delivered_at);
    }

    private function produtoChegou(MarketplaceReturn $caso): bool
    {
        return in_array($caso->situacao, [MarketplaceReturn::ENTREGUE, MarketplaceReturn::CONFERIDA], true)
            && $caso->verdict !== 'nao_recebido';
    }

    private function marcar(MarketplaceReturn $caso, string $status, string $obs): void
    {
        if ($caso->devolucao_fiscal_status === $status && $caso->devolucao_fiscal_obs === $obs) {
            return;
        }

        $caso->forceFill(['devolucao_fiscal_status' => $status, 'devolucao_fiscal_obs' => mb_substr($obs, 0, 500)])->save();

        // Histórico só com o que importa para a nota NOSSA.
        if (! in_array($status, [self::PELO_CANAL, self::SEM_NOTA], true)) {
            $caso->events()->create(['situacao' => $caso->situacao, 'description' => 'Nota de devolução: '.$obs, 'happened_at' => now()]);
        }
    }

    private function avisar(MarketplaceReturn $caso, string $mensagem): void
    {
        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

        if ($admins->isNotEmpty()) {
            rescue(fn () => Notification::send($admins, new FiscalAlertNotification($mensagem)), report: false);
        }
    }
}
