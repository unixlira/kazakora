<?php

namespace App\Console\Commands;

use App\Mail\FechamentoFiscalMail;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Fiscal\Services\FechamentoFiscalService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Todo dia 1º: fechamento fiscal do mês anterior por e-mail (o Lira
 * encaminha pro contador). Destinatário em NFE_FECHAMENTO_EMAIL; sem ele,
 * vai pros admins. Não reenvia o mesmo mês, a não ser com --forcar.
 */
class EnviarFechamentoFiscal extends Command
{
    protected $signature = 'fiscal:fechamento-mensal {--mes= : AAAA-MM (padrão: mês passado)} {--para= : e-mail (padrão: NFE_FECHAMENTO_EMAIL)} {--forcar : reenvia mesmo se já foi}';

    protected $description = 'Gera o fechamento fiscal do mês (resumo, CSV e ZIP dos XMLs) e envia por e-mail.';

    public function handle(FechamentoFiscalService $fechamento): int
    {
        $mes = $this->option('mes') ? Carbon::createFromFormat('Y-m-d', $this->option('mes').'-01') : now()->subMonthNoOverflow();
        $chave = 'fiscal.fechamento.enviado.'.$mes->format('Y-m');

        if (! $this->option('forcar') && Setting::get($chave)) {
            $this->info("Fechamento de {$mes->format('m/Y')} já foi enviado em ".Setting::get($chave).'.');

            return self::SUCCESS;
        }

        $destinatarios = array_filter(array_map('trim', explode(',', (string) ($this->option('para') ?: config('nfe.fechamento_email')))));
        if ($destinatarios === []) {
            $destinatarios = User::query()->where('role', User::ROLE_ADMIN)->pluck('email')->filter()->all();
        }

        if ($destinatarios === []) {
            $this->warn('Ninguém pra receber o fechamento: configure NFE_FECHAMENTO_EMAIL.');

            return self::FAILURE;
        }

        $relatorio = $fechamento->gerar($mes);
        $zip = rescue(fn () => $fechamento->zip($relatorio), null);

        try {
            Mail::to($destinatarios)->send(new FechamentoFiscalMail($relatorio, $fechamento->csv($relatorio), $zip));
        } catch (Throwable $e) {
            $this->error("Falha ao enviar o fechamento: {$e->getMessage()}");

            return self::FAILURE;
        }

        Setting::set($chave, now()->format('d/m/Y H:i').' para '.implode(', ', $destinatarios));
        $this->info("Fechamento de {$mes->format('m/Y')} enviado para ".implode(', ', $destinatarios).'.');

        return self::SUCCESS;
    }
}
