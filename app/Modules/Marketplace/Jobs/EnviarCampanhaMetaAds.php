<?php

namespace App\Modules\Marketplace\Jobs;

use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Services\Meta\MetaAdsException;
use App\Services\Meta\MetaAdsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Meta ADS (2026-10-13): envia a campanha de vídeo para a Meta em segundo
 * plano (subir vídeo grande não cabe numa requisição da tela). Cada rodada
 * sobe no máximo um vídeo (cabe no tempo limite e fica abaixo do
 * retry_after de 330 s do Redis) e volta para a fila; enquanto a Meta
 * processa os vídeos, tenta de novo a cada 30 segundos.
 */
class EnviarCampanhaMetaAds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Rodadas: uma por mídia enviada (até 50) mais as esperas de 30 s (~30 min). */
    public int $tries = 120;

    public int $timeout = 280;

    public function __construct(public readonly int $campanhaId) {}

    public function handle(MetaAdsService $meta): void
    {
        $campanha = MetaAdsCampanha::query()->with(['conjuntos', 'videos'])->find($this->campanhaId);

        if (! $campanha || $campanha->status !== MetaAdsCampanha::STATUS_ENVIANDO) {
            return;
        }

        try {
            $resultado = $meta->avancarEnvio($campanha);
        } catch (MetaAdsException) {
            // Erro já gravado na campanha e no vídeo; a tela mostra e deixa reenviar.
            return;
        }

        if ($resultado === MetaAdsService::ENVIO_PRONTO) {
            return;
        }

        if ($this->attempts() >= $this->tries) {
            $campanha->forceFill([
                'status' => MetaAdsCampanha::STATUS_ERRO,
                'ultimo_erro' => 'A Meta demorou demais para processar os vídeos. Clique em Enviar de novo para continuar de onde parou.',
            ])->save();

            return;
        }

        $this->release($resultado === MetaAdsService::ENVIO_CONTINUAR ? 1 : 30);
    }

    public function failed(?Throwable $erro): void
    {
        MetaAdsCampanha::query()
            ->whereKey($this->campanhaId)
            ->where('status', MetaAdsCampanha::STATUS_ENVIANDO)
            ->update([
                'status' => MetaAdsCampanha::STATUS_ERRO,
                'ultimo_erro' => 'O envio parou no meio. Clique em Enviar de novo para continuar de onde parou.',
            ]);
    }
}
