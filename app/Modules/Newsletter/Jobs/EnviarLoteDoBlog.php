<?php

namespace App\Modules\Newsletter\Jobs;

use App\Modules\Newsletter\Mail\BlogPorEmail;
use App\Modules\Newsletter\Models\NewsletterInscrito;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Um lote do resumo do blog (pedido do Lira 24/11/2026). O comando
 * newsletter:blog divide os inscritos ativos em lotes e espaça os jobs, para
 * respeitar o limite do provedor de e-mail. Quem saiu entre o disparo e a
 * hora do lote é pulado. Falha num endereço não derruba o lote.
 */
class EnviarLoteDoBlog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  array<int, int>  $inscritoIds
     * @param  array<int, array<string, mixed>>  $itens
     */
    public function __construct(
        public readonly string $referencia,
        public readonly string $assunto,
        public readonly array $itens,
        public readonly array $inscritoIds,
    ) {
    }

    public function handle(): void
    {
        $enviados = 0;

        NewsletterInscrito::query()->ativos()->whereIn('id', $this->inscritoIds)->each(function (NewsletterInscrito $inscrito) use (&$enviados) {
            try {
                Mail::to($inscrito->email)->send(new BlogPorEmail(
                    $this->assunto,
                    $this->itens,
                    route('newsletter.sair', $inscrito->token),
                    $inscrito->nome,
                ));
                $inscrito->forceFill(['ultimo_envio_em' => now()])->saveQuietly();
                $enviados++;
            } catch (Throwable $e) {
                Log::warning('Newsletter: falha ao enviar resumo do blog', ['referencia' => $this->referencia, 'inscrito' => $inscrito->id, 'erro' => $e->getMessage()]);
            }
        });

        Log::info('Newsletter: lote do blog enviado', ['referencia' => $this->referencia, 'enviados' => $enviados, 'no_lote' => count($this->inscritoIds)]);
    }
}
