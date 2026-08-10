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
Schedule::command('orders:sync-amazon')->hourly();

// Gasto real com anúncio (Shopee Ads + Mercado Ads) pro painel de lucro
// líquido — pedido explícito 2026-08-09. Cedo o suficiente pra já estar
// pronto quando o admin abrir o dashboard financeiro de manhã; janela de
// 3 dias (padrão do comando) corrige sozinha o número parcial do dia
// anterior, que a Shopee ainda ajusta por umas horas depois da virada.
Schedule::command('ads:sync-spend')->dailyAt('06:00');

// Saldo disponível pra saque do Mercado Pago — pedido explícito
// 2026-08-09/10. Roda de hora em hora (não diário como o de cima): o
// relatório da própria Mercado Pago leva ~15-20min pra ficar pronto depois
// de pedido, então precisa de várias janelas ao longo do dia pra sempre ter
// um relativamente fresco — rodando só 1x/dia o saldo ficaria "velho" a
// maior parte do tempo.
Schedule::command('ads:sync-wallet-balance')->hourly();
