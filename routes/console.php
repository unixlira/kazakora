<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('mercadolivre:refresh-tokens')->everyThirtyMinutes();
Schedule::command('orders:expire-abandoned')->everyFiveMinutes();
// marketplace:poll-labels NÃO roda mais agendado (removido 2026-08-05,
// pedido explícito do usuário — "cron nem precisa ter"). O pipeline de
// etiqueta agora é orientado a evento: CheckShipmentLabelJob dispara na
// hora que o frete é confirmado (ChannelShippingService::confirm()) e de
// novo a cada webhook do Mercado Livre (PokeMercadoLivreLabelChecksJob),
// com retry próprio de 5 em 5s por até 4h por envio. O comando continua
// existindo (`php artisan marketplace:poll-labels`) só como fallback manual
// de operação, não como parte do fluxo normal.
// Texto só muda 1x por dia — rodar 2x (00h/12h) é redundante de propósito,
// cobre o caso da tentativa da meia-noite falhar por instabilidade de rede.
Schedule::command('daily-text:fetch')->twiceDaily(0, 12);

// Reconciliação periódica pedido/faturamento (2026-08-06) — rede de
// segurança pro caso de um webhook se perder por qualquer motivo (fila
// parada, instabilidade do canal, etc.). Idempotente
// (OrderImportService::importNormalized() já detecta pedido existente),
// seguro rodar de hora em hora. Sem --desde/--ate, os dois comandos
// escopam pro mês corrente por padrão (pedido explícito do usuário) — não
// varre o histórico inteiro a cada execução.
Schedule::command('orders:sync-mercadolivre')->hourly();
Schedule::command('orders:sync-shopee')->hourly();
