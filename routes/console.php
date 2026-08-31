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

// Garantia específica pro Mercado Livre (pedido explícito 2026-08-29,
// "pedido embalado continua na fila mesmo depois do ponto de coleta
// escanear o pacote... preferir webhook, mas manter verificação periódica
// como garantia") — orders:sync-mercadolivre acima NÃO cobre isso: ele
// relê o pedido no nível de PEDIDO, e o Mercado Livre nunca reflete
// entrega ali (só no sub-recurso shipment, ver ShipmentService::
// processWebhook()). Rodando mais frequente que a reconciliação geral
// (30min, não 1h) porque é justamente a garantia contra webhook perdido —
// só reconsulta pedido ainda "pago" (ChannelShipment com order confirmado
// no Mercado Livre), consulta rápida e idempotente.
Schedule::command('orders:poll-mercadolivre-shipment-status')->everyThirtyMinutes();

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

// Avaliações (nota/comentário/imagens/nome do comprador) de todos os
// marketplaces conectados — pedido explícito 2026-08-16. Diário (não
// precisa de tempo real como pedido/etiqueta) num horário fora dos outros
// crons já agendados, pra não competir por chamada de API na mesma janela.
Schedule::command('reviews:sync')->dailyAt('05:15');

// Liberação operacional das vendas agendadas do Mercado Livre — reemite
// NF-e pendente, reenvia ao canal quando autorizada, cutuca confirmação de
// envio e checa etiqueta pra todo envio com scheduled_for vencido/hoje (ver
// ReleaseMercadoLivreScheduledShipments). 07:10 com --sync (reimporta
// pedidos recentes do ML antes) deixa o orders:sync-mercadolivre horário
// das 07:00 terminar antes; withoutOverlapping evita duas varreduras
// simultâneas.
Schedule::command('marketplace:release-scheduled-mercadolivre --sync')
    ->dailyAt('07:10')
    ->withoutOverlapping(60);

// 2 checagens extras, sem --sync (orders:sync-mercadolivre já roda de hora
// em hora, dado já está fresco) — pedido explícito do usuário 2026-08-30:
// "no dia certo temos que fazer uma verificação no servidor 00:05 se está
// liberada e outra as 06:05 se liberou e se tiver liberada imprime a
// etiqueta e vai pra fila de separação". Mesmo comando de cima
// (idempotente por design, ver docblock da classe) — só reduz o tempo
// entre o canal liberar de verdade e alguém perceber, sem esperar até as
// 07:10.
//
// RECRIADO 2026-08-31 — o comando e este agendamento tinham sido
// construídos ao vivo em produção (mesmo dia, 2026-08-30), nunca
// commitados, e um deploy de rotina sem relação nenhuma apagou os dois
// junto ao resetar o código pro estado do git. 27 pacotes agendados pra
// entregar sem NINGUÉM verificando etiqueta automaticamente até isso ser
// percebido. Desta vez fica commitado.
Schedule::command('marketplace:release-scheduled-mercadolivre')
    ->dailyAt('00:05')
    ->withoutOverlapping(60);

Schedule::command('marketplace:release-scheduled-mercadolivre')
    ->dailyAt('06:05')
    ->withoutOverlapping(60);
