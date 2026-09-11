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
/**
 * AS TRÊS CONFERÊNCIAS DO DIA — pedido do usuário em 2026-09-10, no dia em
 * que uma venda da Shopee (260910M2M4KAK5) ficou 24h fora do sistema, sem
 * nota e sem etiqueta, e ele descobriu abrindo o painel do canal.
 *
 * A varredura de hora em hora abaixo já existia e não salvou: ela falhava
 * no MESMO pedido a cada hora e só reclamava num console que ninguém lê.
 * Por isso estas rodadas vêm junto com o alerta por e-mail (ver
 * OrderImportFailedNotification) — sem o e-mail, mais cron é mais silêncio.
 *
 * 06:00 — o dia anterior inteiro (a madrugada é onde a venda some sem
 *         ninguém por perto).
 * 12:00 — o dia anterior + o dia corrente até agora.
 * 18:00 — o dia corrente, antes de fechar a expedição.
 *
 * A data é calculada a cada `schedule:run`, então no disparo ela é sempre
 * a do dia certo. Importação é idempotente: rodar de novo não duplica.
 */
foreach (['orders:sync-shopee', 'orders:sync-mercadolivre'] as $comando) {
    Schedule::command($comando.' --desde='.now()->subDay()->toDateString().' --ate='.now()->subDay()->toDateString())
        ->dailyAt('06:00')
        ->withoutOverlapping(30);

    Schedule::command($comando.' --desde='.now()->subDay()->toDateString().' --ate='.now()->toDateString())
        ->dailyAt('12:00')
        ->withoutOverlapping(30);

    Schedule::command($comando.' --desde='.now()->toDateString().' --ate='.now()->toDateString())
        ->dailyAt('18:00')
        ->withoutOverlapping(30);
}

Schedule::command('orders:sync-mercadolivre')->hourly();
Schedule::command('orders:sync-shopee')->hourly();
Schedule::command('orders:sync-amazon')->hourly();

// Garantia específica pro Mercado Livre (pedido explícito 2026-08-29,
// "pedido embalado continua na fila mesmo depois do ponto de coleta
// escanear o pacote... preferir webhook, mas manter verificação periódica
// como garantia") — orders:sync-mercadolivre acima NÃO cobre isso: ele
// relê o pedido no nível de PEDIDO, e o Mercado Livre nunca reflete
// entrega ali (só no sub-recurso shipment, ver ShipmentService::
// processWebhook()).
//
// DEVOLVIDO 2026-09-11: esta linha sumiu em 2b7d3c3 (31/08), num arquivo
// antigo copiado por cima do servidor via SCP — ninguém decidiu tirar. Sem
// ela, pedido cujo webhook de envio se perdeu ficava "pago" pra sempre; e
// como o webhook só atualizava UM pedido do carrinho, os irmãos #894,
// #1300, #1368 e #1541 ficaram parados na fila. Ensaio no dia: 55 envios
// pagos nos últimos 60 dias = 55 consultas por rodada, e só esses 4 mudavam.
Schedule::command('orders:poll-mercadolivre-shipment-status')->everyThirtyMinutes()->withoutOverlapping(25);
// TikTok Shop via Bling. Idempotente e silencioso (não falha o schedule)
// quando o Bling ainda não foi conectado ou a loja do TikTok Shop ainda
// não foi configurada.
//
// Pedido explícito 2026-08-31 ("preciso de uma sincronia em tempo real —
// saiu a venda lá, cai no KoraSync já"): sem webhook documentado do lado
// do Bling pra pedidos/vendas, a saída real é poll frequente — de 2 em 2
// min, escopado só pro dia de hoje (barato: poucos pedidos, cache de 2min
// em BlingOrderService::listOrders() evita nova chamada à API se rodar 2x
// no mesmo intervalo de 2min). O passe de hora em hora abaixo continua
// existindo como rede de segurança de período maior (mês inteiro).
Schedule::command('orders:sync-tiktok --desde='.now()->toDateString())
    ->everyTwoMinutes()
    ->withoutOverlapping(5);

Schedule::command('orders:sync-tiktok')->hourly();

// A NF-e do TikTok Shop é emitida pelo Bling desde 02/09/2026 (ver
// services.bling.invoice_issuer_channels), e a emissão lá é assíncrona —
// quando o pedido chega pelo webhook a nota quase nunca existe ainda.
// Esta varredura fecha isso: pedido pago sem nota (ou com nota sem XML)
// tenta de novo a cada 5 min. Sai sozinha do ar se a chave for desligada.
Schedule::command('invoices:sync-bling')->everyFiveMinutes()->withoutOverlapping(10);

// Nota que não saiu trava a etiqueta: Shopee e Mercado Livre só liberam o
// envio depois de aceitar a NF-e. O retry do próprio job são 3 tentativas
// em ~15 min — passou disso, o pedido ficava parado pra sempre esperando
// alguém olhar. Foi o que segurou 5 pedidos do ML até 2026-09-07, todos
// por problemas JÁ corrigidos no código (rejeição 232 da IE, 539 da
// numeração duplicada, e um que nunca chegou a emitir).
//
// De 15 em 15 minutos, com espera de 30 min por nota pra não martelar a
// SEFAZ com o mesmo erro. Nota autorizada segue sozinha o resto do
// caminho até a impressora — ver RetryStuckInvoices.
Schedule::command('nfe:retry-stuck')->everyFifteenMinutes()->withoutOverlapping(20);

// Produto novo do TikTok nasce no Bling SEM NCM (a integração dele não
// preenche dado fiscal), e sem NCM a nota não emite — foi o que travou a
// primeira venda pelo caminho novo, o pedido #1216. Empurra o NCM do
// nosso catálogo pra lá antes que a primeira venda do produto aconteça.
// Só preenche o que está vazio no Bling, nunca sobrescreve.
Schedule::command('bling:sync-fiscal')->everyThirtyMinutes()->withoutOverlapping(20);

// Pedido que o canal já despachou mas que nunca recebeu o clique de
// separar fica na fila do KoraSync pra sempre — a fila é `paid` +
// `packed_at IS NULL`, e ninguém clica em "separar" num pedido que já foi
// embora. Foi assim que a fila chegou a 211 cards, o mais antigo de
// 06/08. Roda de madrugada porque reconsulta canal a canal.
Schedule::command('separation:close-shipped')->dailyAt('04:30')->withoutOverlapping(60);

// Card de separação sem foto é inaceitável (pedido do usuário, 2026-09-05:
// é pela imagem que o operador confere o que embalar). O auto-import já
// busca a foto na hora da venda; esta varredura cobre o que ficou pra trás
// e o produto cujo canal estava fora do ar naquele instante.
Schedule::command('catalog:fill-missing-images')->hourly()->withoutOverlapping(30);

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

// Envios Flex depois que saem daqui — pedido explícito 2026-09-11 (a
// bicicleta do #1384, cancelada com o produto fora e sem rota iniciada no
// ML). De 30 em 30 min: reconsulta no ML o que ainda pode mudar e recalcula
// os alertas (o de "entregador não iniciou a rota" depende só do relógio).
Schedule::command('flex:acompanhar-envios')->everyThirtyMinutes()->withoutOverlapping(25);

// Retenção das imagens do comprovante do KoraFlex: cumpre o prazo prometido
// no consentimento, MENOS recibo retido como prova ou com pendência aberta
// (ver PurgeKoraFlexReceiptImages). Existia desde 2026-09-10, mas nunca
// tinha sido agendado.
Schedule::command('koraflex:limpar-recibos')->dailyAt('03:40');

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
