<?php

namespace App\Console\Commands;

use App\Mail\FlexBillingCycleMail;
use App\Modules\Marketplace\Support\FlexDeliveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Roda diariamente (ver routes/console.php) mas só age em 2 dias por mês —
 * dia 15 (fecha o ciclo 1-15) e o último dia do mês (fecha 16-fim) — pedido
 * explícito 2026-08-10 ("dia 15" e "dia 30"; usa fim de mês de verdade em
 * vez de fixar "30" pra não quebrar em fevereiro/meses de 31 dias, ver
 * FlexDeliveryService::cycleContaining()).
 *
 * Idempotente por dia: se já rodou hoje (ex: reexecução manual/deploy),
 * FlexBillingPeriod::updateOrCreate() do FlexDeliveryService::closeCycle()
 * não duplica a linha, mas o e-mail só é reenviado se ainda não tiver sido
 * mandado com sucesso (email_sent_at null) — evita spammar o mesmo
 * fechamento duas vezes.
 */
class CheckFlexBillingCycle extends Command
{
    protected $signature = 'flex:check-billing-cycle';

    protected $description = 'Fecha o ciclo quinzenal do Mercado Envios Flex (dia 15 e fim do mês) e envia o e-mail de cobrança';

    public function handle(FlexDeliveryService $flex): int
    {
        $today = Carbon::today();

        if ($today->day !== 15 && ! $today->isLastOfMonth()) {
            $this->info('Hoje não é dia de fechamento do ciclo Flex (dia 15 ou fim do mês) — nada a fazer.');

            return self::SUCCESS;
        }

        $cycle = $flex->cycleContaining($today);
        $period = $flex->closeCycle($cycle['start'], $cycle['end']);

        $this->info("Ciclo {$period->period_start->format('d/m')} a {$period->period_end->format('d/m')}: {$period->deliveries_count} fretes, R$ {$period->total_amount}.");

        if ($period->email_sent_at) {
            $this->info('E-mail já tinha sido enviado pra esse ciclo — não reenvia.');

            return self::SUCCESS;
        }

        try {
            $recipient = config('services.mercado_livre_flex.billing_email');

            if (! $recipient) {
                $this->warn('services.mercado_livre_flex.billing_email não configurado — e-mail não enviado.');

                return self::SUCCESS;
            }

            Mail::to($recipient)->send(new FlexBillingCycleMail($period));
            $period->update(['email_sent_at' => now(), 'email_error' => null]);

            $this->info("E-mail enviado pra {$recipient}.");
        } catch (Throwable $exception) {
            $period->update(['email_error' => $exception->getMessage()]);
            $this->error("Falha ao enviar e-mail: {$exception->getMessage()}");
        }

        return self::SUCCESS;
    }
}
