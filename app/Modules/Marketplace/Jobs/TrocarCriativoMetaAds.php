<?php

namespace App\Modules\Marketplace\Jobs;

use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Modules\Marketplace\Models\MetaAdsVideo;
use App\Services\Meta\MetaAdsException;
use App\Services\Meta\MetaAdsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Meta ADS (2026-10-19): troca o vídeo de um anúncio que já está na Meta.
 * O vídeo novo sobe para a conta, a Meta processa (minutos) e só então vira
 * criativo novo no anúncio (MetaAdsService::avancarEdicao). Enquanto isso o
 * anúncio segue com o criativo antigo; se a Meta recusar, nada muda e o
 * erro fica no anúncio. Mesmo ritmo do EnviarCampanhaMetaAds: um passo por
 * rodada e nova tentativa a cada 30 s enquanto a Meta processa.
 */
class TrocarCriativoMetaAds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** ~30 min de espera pelo processamento do vídeo. */
    public int $tries = 70;

    public int $timeout = 280;

    public function __construct(public readonly int $videoId) {}

    public function handle(MetaAdsService $meta): void
    {
        $video = MetaAdsVideo::query()->find($this->videoId);
        $campanha = $video ? MetaAdsCampanha::query()->with('conjuntos')->find($video->campanha_id) : null;

        if (! $video || ! $campanha || ! $video->temEdicaoPendente()) {
            return;
        }

        try {
            $resultado = $meta->avancarEdicao($campanha, $video, true);
        } catch (MetaAdsException) {
            // Erro gravado no anúncio; o criativo antigo continua.
            return;
        }

        if ($resultado === MetaAdsService::ENVIO_PRONTO) {
            return;
        }

        if ($this->attempts() >= $this->tries) {
            $meta->descartarEdicao($video, 'A Meta demorou demais para processar o vídeo novo. Nada foi alterado; tente trocar o arquivo de novo.');

            return;
        }

        $this->release($resultado === MetaAdsService::ENVIO_CONTINUAR ? 1 : 30);
    }

    public function failed(?Throwable $erro): void
    {
        $video = MetaAdsVideo::query()->find($this->videoId);

        if ($video?->temEdicaoPendente()) {
            app(MetaAdsService::class)->descartarEdicao($video, 'A troca do vídeo parou no meio. Nada foi alterado; tente trocar o arquivo de novo.');
        }
    }
}
