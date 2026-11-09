<?php

namespace App\Modules\Financeiro\Support;

use App\Modules\Admin\Support\AlertasAdmin;
use Illuminate\Support\Facades\Log;

/**
 * Falha na coleta financeira (Amazon, TikTok, ADS) abre (ou soma) o alerta
 * do painel do admin (AlertasAdmin, tipo coleta_financeira), que some
 * sozinho quando a coleta volta.
 *
 * Sem WhatsApp desde 09/11/2026 (pedido do Lira, tarefa 3965): a Manuela não
 * manda mais aviso de erro; WhatsApp só sai quando sai venda. Antes (16/10)
 * cada falha virava mensagem ao Lira e repetia de hora em hora.
 */
class AlertaColetaFinanceira
{
    /**
     * @param  string|null  $chave  mantido por compatibilidade (agrupava o intervalo do WhatsApp)
     * @return string sempre "painel"
     */
    public function avisar(string $canal, string $motivo, ?string $chave = null, ?int $intervaloMinutos = null): string
    {
        rescue(fn () => app(AlertasAdmin::class)->disparar(
            AlertasAdmin::COLETA_FINANCEIRA,
            mb_strtolower($canal),
            "Falha na coleta financeira {$canal} (Cora Box)",
            $motivo,
            mb_strtolower($canal) === 'tiktok' ? '/admin/arquivos-tiktok' : '/admin/alertas',
        ), report: false);

        Log::warning('coleta_financeira_falhou', ['canal' => $canal, 'motivo' => mb_substr($motivo, 0, 400)]);

        return 'painel';
    }
}
