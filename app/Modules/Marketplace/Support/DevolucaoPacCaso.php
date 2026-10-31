<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Admin\Http\Controllers\DevolucoesController;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Services\DevolucaoFiscalAutomatica;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\MarketplaceReturn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A etiqueta de Devolução PAC controla um caso em Devoluções (Lira 2026-10-31:
 * "nas próximas a gente já controla para dentro da KazaKora"). Só reage a
 * etiqueta nova: nada aqui percorre etiquetas antigas.
 *
 * O caso entra no fluxo fiscal normal pelo saved() de MarketplaceReturn:
 * rascunho ao abrir e NF-e quando o produto é recebido.
 *
 * Um pedido tem um caso só em aberto. Cancelada a única etiqueta, o caso vai
 * para CANCELADA (o rascunho é descartado); se vier outra etiqueta, o MESMO
 * caso reabre e monta um rascunho novo, com o histórico inteiro no lugar.
 *
 * A etiqueta já foi gerada e paga: erro aqui vai para o log e não quebra a tela.
 */
class DevolucaoPacCaso
{
    public const ORIGEM = 'etiqueta_devolucao_pac';

    public const MOTIVO = 'Etiqueta de Devolução PAC';

    public function etiquetaGerada(CorreiosPrePostagem $etiqueta): void
    {
        if (! $etiqueta->devolucao || ! $etiqueta->order_id || $etiqueta->status !== CorreiosPrePostagem::STATUS_GERADA) {
            return;
        }

        $this->comSeguranca($etiqueta, function (Order $pedido) use ($etiqueta) {
            $codigo = $this->codigo($etiqueta);
            $aberto = $this->casoEmAberto($pedido);

            if ($aberto) {
                // Caso da API segue a plataforma: só fica o registro.
                if ($aberto->manual && $etiqueta->codigo_objeto) {
                    $aberto->update(['tracking_number' => $etiqueta->codigo_objeto]);
                }
                $this->evento($aberto, "Nova etiqueta de Devolução PAC: {$codigo}.");

                return;
            }

            $cancelado = $this->canceladoPelaEtiqueta($pedido);

            if ($cancelado) {
                $cancelado->update([
                    'situacao' => MarketplaceReturn::AGUARDANDO_ENVIO,
                    'closed_at' => null,
                    'tracking_number' => $etiqueta->codigo_objeto,
                    'raw_payload' => ['origem' => self::ORIGEM],
                ]);
                $this->evento($cancelado, "Reaberta pela nova etiqueta de Devolução PAC: {$codigo}.");

                return;
            }

            $caso = MarketplaceReturn::query()->create([
                'channel' => $pedido->origin,
                'external_id' => 'manual-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'order_id' => $pedido->id,
                'external_order_id' => $pedido->external_order_id,
                'kind' => MarketplaceReturn::KIND_DEVOLUCAO,
                'reason_label' => self::MOTIVO,
                'situacao' => MarketplaceReturn::AGUARDANDO_ENVIO,
                'tracking_number' => $etiqueta->codigo_objeto,
                'opened_at' => now(),
                'manual' => true,
                'raw_payload' => ['origem' => self::ORIGEM],
            ]);
            $this->evento($caso, "Aberta pela etiqueta de Devolução PAC: {$codigo}.");
        });
    }

    /** @param  string  $como  "cancelada" ou "removida" */
    public function etiquetaSaiu(CorreiosPrePostagem $etiqueta, string $como): void
    {
        if (! $etiqueta->devolucao || ! $etiqueta->order_id) {
            return;
        }

        $this->comSeguranca($etiqueta, function (Order $pedido) use ($etiqueta, $como) {
            $caso = $this->casoEmAberto($pedido);

            if (! $caso) {
                return;
            }

            $texto = "Etiqueta de Devolução PAC {$this->codigo($etiqueta)} {$como}.";

            // Produto já aqui ou nota já emitida: a etiqueta não desfaz isso.
            $chegou = $caso->received_at || $caso->situacao === MarketplaceReturn::ENTREGUE
                || $caso->devolucao_fiscal_status === DevolucaoFiscalAutomatica::EMITIDA;

            if (! $caso->manual || $chegou) {
                $this->evento($caso, $texto);

                return;
            }

            $outra = CorreiosPrePostagem::query()
                ->where('order_id', $pedido->id)
                ->where('devolucao', true)
                ->where('status', CorreiosPrePostagem::STATUS_GERADA)
                ->whereKeyNot($etiqueta->id)
                ->latest('id')
                ->first();

            if ($outra) {
                $caso->update(['tracking_number' => $outra->codigo_objeto ?? $caso->tracking_number]);
                $this->evento($caso, "{$texto} Segue com a etiqueta {$this->codigo($outra)}.");

                return;
            }

            if (($caso->raw_payload['origem'] ?? null) === self::ORIGEM) {
                $caso->update([
                    'situacao' => MarketplaceReturn::CANCELADA,
                    'closed_at' => now(),
                    'raw_payload' => ['origem' => self::ORIGEM, 'cancelada_pela_etiqueta' => true],
                ]);
                $this->evento($caso, "{$texto} Sem outra etiqueta válida: caso cancelado.");

                return;
            }

            // Registrado à mão pela equipe: quem encerra é ela. Só tira o rastreio que morreu.
            if ($caso->tracking_number === $etiqueta->codigo_objeto) {
                $caso->update(['tracking_number' => null]);
            }
            $this->evento($caso, $texto);
        });
    }

    private function comSeguranca(CorreiosPrePostagem $etiqueta, callable $acao): void
    {
        try {
            DB::transaction(function () use ($etiqueta, $acao) {
                // Trava o pedido: duas etiquetas juntas não abrem dois casos.
                $pedido = Order::query()->whereKey($etiqueta->order_id)->lockForUpdate()->first();

                if ($pedido) {
                    $acao($pedido);
                }
            });

            DevolucoesController::limparContagem();
        } catch (Throwable $e) {
            Log::error('Devolução PAC: caso em Devoluções não foi atualizado', [
                'pre_postagem_id' => $etiqueta->id,
                'order_id' => $etiqueta->order_id,
                'erro' => $e->getMessage(),
            ]);
        }
    }

    private function casoEmAberto(Order $pedido): ?MarketplaceReturn
    {
        return MarketplaceReturn::query()
            ->where('order_id', $pedido->id)
            ->whereIn('situacao', MarketplaceReturn::EM_ABERTO)
            ->orderByDesc('manual')
            ->orderByDesc('id')
            ->first();
    }

    private function canceladoPelaEtiqueta(Order $pedido): ?MarketplaceReturn
    {
        return MarketplaceReturn::query()
            ->where('order_id', $pedido->id)
            ->where('manual', true)
            ->where('situacao', MarketplaceReturn::CANCELADA)
            ->latest('id')
            ->get()
            ->first(fn (MarketplaceReturn $caso) => ($caso->raw_payload['cancelada_pela_etiqueta'] ?? false) === true);
    }

    private function codigo(CorreiosPrePostagem $etiqueta): string
    {
        return $etiqueta->codigo_objeto ?: "#{$etiqueta->id}";
    }

    private function evento(MarketplaceReturn $caso, string $texto): void
    {
        $caso->events()->create([
            'situacao' => $caso->situacao,
            'description' => $texto,
            'user_id' => Auth::id(),
            'happened_at' => now(),
        ]);
    }
}
