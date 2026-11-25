<?php

namespace App\Console\Commands;

use App\Modules\Newsletter\Jobs\EnviarLoteDoBlog;
use App\Modules\Newsletter\Models\NewsletterEnvio;
use App\Modules\Newsletter\Models\NewsletterInscrito;
use App\Modules\Newsletter\Support\ResumoDoBlog;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * Resumo do blog por e-mail para os inscritos ativos da newsletter (pedido do
 * Lira 24/11/2026). Um e-mail por período (diário ou semanal), nunca um por
 * matéria. Cada lote vai para a fila espaçado pelo intervalo da config, para
 * respeitar o limite do provedor. O mesmo resumo (referência) nunca sai duas
 * vezes.
 *
 * Agendamento em routes/console.php, DESLIGADO até NEWSLETTER_BLOG_AGENDADO=true.
 */
class EnviarBlogPorEmail extends Command
{
    protected $signature = 'newsletter:blog
        {--dry-run : Só mostra o que seria enviado, sem enviar nada}
        {--data= : Dia do resumo (AAAA-MM-DD). Padrão: hoje}
        {--frequencia= : diario ou semanal. Padrão: NEWSLETTER_BLOG_FREQUENCIA}';

    protected $description = 'Envia o resumo do blog KazaKora por e-mail aos inscritos ativos da newsletter, em lotes pela fila';

    public function handle(): int
    {
        $frequencia = $this->option('frequencia');
        if ($frequencia !== null && ! in_array($frequencia, ['diario', 'semanal'], true)) {
            $this->error('--frequencia deve ser diario ou semanal.');

            return self::INVALID;
        }

        $resumo = ResumoDoBlog::do($this->option('data') ?: null, $frequencia);
        $periodo = $resumo->inicio->format('d/m/Y').($resumo->frequencia === 'semanal' ? ' a '.$resumo->fim->format('d/m/Y') : '');

        if ($resumo->vazio()) {
            $this->info("Nenhuma matéria do blog publicada em {$periodo}. Nada a enviar.");

            return self::SUCCESS;
        }

        $tamanho = max(1, (int) config('newsletter.lote_tamanho', 50));
        $intervalo = max(0, (int) config('newsletter.segundos_entre_lotes', 60));
        $ativos = NewsletterInscrito::query()->ativos()->count();
        $lotes = (int) ceil($ativos / $tamanho);
        $jaEnviado = NewsletterEnvio::query()->where('referencia', $resumo->referencia())->exists();

        $this->line("Resumo {$resumo->frequencia} ({$periodo}), referência {$resumo->referencia()}");
        $this->line('Assunto: '.$resumo->assunto());
        foreach ($resumo->itens() as $item) {
            $this->line(" - [{$item['tipo']}] {$item['titulo']}");
        }
        $this->line("Inscritos ativos: {$ativos} em {$lotes} lote(s) de até {$tamanho}, {$intervalo}s entre lotes.");

        if ($this->option('dry-run')) {
            $this->info('Simulação: nenhum e-mail foi enviado.'.($jaEnviado ? ' (Este resumo já foi enviado antes.)' : ''));

            return self::SUCCESS;
        }

        if ($jaEnviado) {
            $this->warn('Este resumo já foi enviado. Nada a fazer.');

            return self::SUCCESS;
        }

        if ($ativos === 0) {
            $this->info('Nenhum inscrito ativo. Nada a enviar.');

            return self::SUCCESS;
        }

        try {
            NewsletterEnvio::query()->create([
                'referencia' => $resumo->referencia(),
                'assunto' => $resumo->assunto(),
                'post_ids' => $resumo->posts->pluck('id')->all(),
                'destinatarios' => $ativos,
                'lotes' => $lotes,
            ]);
        } catch (QueryException) {
            // Outro processo registrou o mesmo resumo ao mesmo tempo.
            $this->warn('Este resumo já foi enviado. Nada a fazer.');

            return self::SUCCESS;
        }

        $assunto = $resumo->assunto();
        $itens = $resumo->itens();
        $numero = 0;
        NewsletterInscrito::query()->ativos()->select('id')->orderBy('id')->chunkById($tamanho, function ($inscritos) use (&$numero, $resumo, $assunto, $itens, $intervalo) {
            EnviarLoteDoBlog::dispatch($resumo->referencia(), $assunto, $itens, $inscritos->pluck('id')->all())
                ->delay(now()->addSeconds($numero * $intervalo));
            $numero++;
        });

        $this->info("{$numero} lote(s) na fila para {$ativos} inscrito(s).");

        return self::SUCCESS;
    }
}
