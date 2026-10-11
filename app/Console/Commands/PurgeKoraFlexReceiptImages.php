<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\FlexPickupReceipt;
use App\Modules\Marketplace\Support\FlexControlService;
use App\Modules\Marketplace\Support\FlexPickupService;
use Illuminate\Console\Command;

/**
 * Apaga a assinatura e a FOTO dos comprovantes antigos de entrega do Flex.
 *
 * O recibo em si continua: hora, entregador, lista de pacotes, registro do
 * consentimento. O que some é a imagem — que é a parte que identifica uma
 * pessoa e a única que não faz falta depois que a dúvida sobre a entrega
 * passou. Foi isso que foi prometido ao entregador na tela de
 * consentimento, e promessa de retenção que ninguém executa é só texto.
 *
 * Prazo em `services.koraflex.receipt_retention_days` (padrão 180 dias).
 *
 * EXCEÇÃO, desde 2026-09-11: recibo que é PROVA não perde a imagem. Isso
 * vale pra recibo retido à mão na tela Envios Flex e pra recibo com
 * pendência aberta em algum pedido (devolução, venda cancelada com o
 * produto fora, rota não iniciada) — apagar a foto de uma entrega em
 * disputa seria destruir justamente o que ela existe pra provar. O texto do
 * consentimento avisa essa exceção.
 */
class PurgeKoraFlexReceiptImages extends Command
{
    protected $signature = 'koraflex:limpar-recibos {--dias= : Sobrescreve o prazo de retenção} {--seco : Só mostra o que seria apagado}';

    protected $description = 'Apaga assinatura e foto dos comprovantes de entrega do Flex mais velhos que o prazo de retenção (menos os retidos como prova)';

    public function handle(FlexPickupService $flex, FlexControlService $controle): int
    {
        $dias = (int) ($this->option('dias') ?: config('services.koraflex.receipt_retention_days', 180));

        if ($dias < 1) {
            $this->error('Prazo de retenção precisa ser de pelo menos 1 dia.');

            return self::FAILURE;
        }

        $corte = now()->subDays($dias);
        $seco = (bool) $this->option('seco');

        $recibos = FlexPickupReceipt::query()
            ->where('collected_at', '<', $corte)
            ->where(function ($query) {
                $query->whereNotNull('signature_path')->orWhereNotNull('photo_path');
            })
            ->get();

        if ($recibos->isEmpty()) {
            $this->info("Nenhum comprovante com imagem anterior a {$corte->format('d/m/Y')}.");

            return self::SUCCESS;
        }

        $limpos = 0;

        foreach ($recibos as $recibo) {
            $rotulo = "#{$recibo->id} de {$recibo->collected_at->format('d/m/Y H:i')} ({$recibo->orders_count} caixa(s))";

            if ($recibo->retido()) {
                $this->line("{$rotulo} — mantido: retido como prova".($recibo->legal_hold_reason ? " ({$recibo->legal_hold_reason})" : ''));

                continue;
            }

            if ($controle->reciboEmDisputa($recibo)) {
                $this->line("{$rotulo} — mantido: pedido com pendência aberta");

                continue;
            }

            $this->line($rotulo);
            $limpos++;

            if ($seco) {
                continue;
            }

            $flex->apagarImagens($recibo);

            // O hash fica: continua provando qual arquivo existiu.
            $recibo->forceFill(['signature_path' => null, 'photo_path' => null])->save();
        }

        $this->info($seco
            ? "{$limpos} comprovante(s) seriam limpos (modo seco)."
            : "{$limpos} comprovante(s) tiveram assinatura e foto apagadas.");

        return self::SUCCESS;
    }
}
