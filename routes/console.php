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
// TikTok Shop via Bling (pedido explícito 2026-08-31) — mesmo padrão dos
// 3 de cima, ver SyncTikTokShopOrders/BlingOrderService. Idempotente e
// silencioso (não falha o schedule) quando o Bling ainda não foi
// conectado ou a loja do TikTok Shop ainda não foi configurada.
Schedule::command('orders:sync-tiktok')->hourly();

// Rede de segurança pro caso de autoImportProduct() falhar na hora do
// import (API do canal fora do ar naquele instante) — sem isso o item
// ficava sem produto/SKU vinculado pra sempre (achado real 2026-08-19,
// quase causou embalagem errada no KoraSync — ver RelinkUnmappedMarketplaceItems).
Schedule::command('marketplace:relink-unmapped-items')->everyThirtyMinutes();

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

// Fechamento quinzenal do Mercado Envios Flex — pedido explícito
// 2026-08-10. Roda todo dia (o comando mesmo decide se hoje é dia de
// fechar, dia 15 ou fim do mês — ver CheckFlexBillingCycle) em vez de
// tentar agendar direto pro "último dia do mês" (Schedule não tem esse
// helper nativo, e um cron fixo em "30" erraria fevereiro e os meses de
// 31 dias).
Schedule::command('flex:check-billing-cycle')->dailyAt('07:00');

// Vendas agendadas pelo canal (Coleta/Places do Mercado Livre, etiqueta só
// liberada perto de uma data futura) — pedido explícito 2026-08-14, depois
// do pedido #278 (agendado pro dia 17, ninguém do time sabia por que a
// etiqueta não saía). 2x/dia em horário comercial (8h abrindo o dia, 15h
// pra pegar quem só chega de tarde) — silencioso quando não há nada a
// avisar, ver NotifyScheduledShipmentsCommand.
Schedule::command('marketplace:notify-scheduled-shipments')->twiceDaily(8, 15);

// Liberação operacional das vendas agendadas do Mercado Livre — pedido
// explícito 2026-08-31 ("a partir das 6h da manhã, roda uma cron que
// verifica via API se existe pedido pra enviar, emite NF-e se faltar,
// imprime etiqueta, deixa disponível pro KoraSync"). A 1ª passada do dia
// (06:00) leva --sync, que reimporta pedidos recentes do Mercado Livre —
// deixa o orders:sync-mercadolivre horário rodar de novo depois sem
// conflito (withoutOverlapping). As passadas seguintes, de 30 em 30 min
// até as 21h, batem de novo na API do canal pra cada envio agendado ainda
// sem etiqueta — não só relêem o que já está salvo — pra pegar rápido
// tanto a etiqueta liberando quanto o canal corrigindo a data prometida
// (ver BUG REAL 2026-08-31 no docblock de ReleaseMercadoLivreScheduledShipments:
// antes disso, uma data errada gravada na 1ª confirmação podia ficar
// escondida por dias sem ninguém perceber).
Schedule::command('marketplace:release-scheduled-mercadolivre --sync')
    ->dailyAt('06:00')
    ->withoutOverlapping(60);

Schedule::command('marketplace:release-scheduled-mercadolivre')
    ->everyThirtyMinutes()
    ->between('06:30', '21:00')
    ->withoutOverlapping(60);

// Avaliações Shopee alimentam a resposta automática da Manuela/Naia.
// Roda a cada 4 minutos porque resposta pública atrasada prejudica a loja;
// falhas ficam registradas no banco, em log PT-BR e notificam administradores.
Schedule::command('reviews:sync')->cron('*/4 * * * *');
Schedule::command('video-downloads:clean')->hourly();
