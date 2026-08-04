<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('mercadolivre:refresh-tokens')->everyThirtyMinutes();
Schedule::command('orders:expire-abandoned')->everyFiveMinutes();
Schedule::command('marketplace:poll-labels')->everyFiveMinutes();
// Texto só muda 1x por dia — rodar 2x (00h/12h) é redundante de propósito,
// cobre o caso da tentativa da meia-noite falhar por instabilidade de rede.
Schedule::command('daily-text:fetch')->twiceDaily(0, 12);
