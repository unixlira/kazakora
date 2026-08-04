<?php

namespace App\Console\Commands;

use App\Modules\Content\Services\DailyTextFetcherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchDailyText extends Command
{
    protected $signature = 'daily-text:fetch';

    protected $description = 'Busca o texto diário das Testemunhas de Jeová (wol.jw.org) e salva/atualiza no banco';

    public function handle(DailyTextFetcherService $fetcher): int
    {
        try {
            $dailyText = $fetcher->fetchToday();
        } catch (Throwable $exception) {
            Log::error('daily_text.fetch.failed', ['message' => $exception->getMessage()]);
            $this->error("Falha ao buscar o texto diário: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Texto diário de {$dailyText->date->toDateString()} salvo: {$dailyText->weekday_label}");

        return self::SUCCESS;
    }
}
