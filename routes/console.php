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
