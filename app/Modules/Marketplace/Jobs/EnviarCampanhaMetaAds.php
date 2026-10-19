<?php

namespace App\Modules\Marketplace\Jobs;

use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Modules\Marketplace\Models\MetaAdsConjunto;
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
 *
 * Com conjuntoId (2026-10-19), envia só o conjunto novo de uma campanha que
 * já está na Meta; o andamento e o erro ficam no conjunto, não na campanha.
 */
class EnviarCampanhaMetaAds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Rodadas: uma por mídia enviada (até 50) mais as esperas de 30 s (~30 min). */
    public int $tries = 120;

    public int $timeout = 280;

    public function __construct(public readonly int $campanhaId, public readonly ?int $conjuntoId = null) {}

    public function handle(MetaAdsService $meta): void
    {
        $campanha = MetaAdsCampanha::query()->with(['conjuntos', 'videos'])->find($this->campanhaId);

        if (! $campanha) {
            return;
        }

        $conjunto = $this->conjuntoId !== null ? $campanha->conjuntos->firstWhere('id', $this->conjuntoId) : null;

        if ($this->conjuntoId !== null ? ! $conjunto?->enviando : $campanha->status !== MetaAdsCampanha::STATUS_ENVIANDO) {
            return;
        }

        try {
            $resultado = $meta->avancarEnvio($campanha, $conjunto);
        } catch (MetaAdsException) {
            // Erro já gravado na campanha e no vídeo; a tela mostra e deixa reenviar.
            return;
        }

        if ($resultado === MetaAdsService::ENVIO_PRONTO) {
            return;
        }

        if ($this->attempts() >= $this->tries) {
            if ($conjunto) {
                $conjunto->forceFill([
                    'enviando' => false,
                    'ultimo_erro' => 'A Meta demorou demais para processar os vídeos. Clique em Enviar de novo para continuar de onde parou.',
                ])->save();

                return;
            }

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
        if ($this->conjuntoId !== null) {
            MetaAdsConjunto::query()
                ->whereKey($this->conjuntoId)
                ->where('enviando', true)
                ->update([
                    'enviando' => false,
                    'ultimo_erro' => 'O envio parou no meio. Clique em Enviar de novo para continuar de onde parou.',
                ]);

            return;
        }

        MetaAdsCampanha::query()
            ->whereKey($this->campanhaId)
            ->where('status', MetaAdsCampanha::STATUS_ENVIANDO)
            ->update([
                'status' => MetaAdsCampanha::STATUS_ERRO,
                'ultimo_erro' => 'O envio parou no meio. Clique em Enviar de novo para continuar de onde parou.',
            ]);
    }
}
