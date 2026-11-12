<?php

namespace App\Console\Commands;

use App\Modules\Admin\Support\AlertasAdmin;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\FechamentoFiscalService;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Modules\Fiscal\Support\PackDoPedido;
use App\Modules\Fiscal\Support\SituacaoNaSefaz;
use App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob;
use App\Modules\Marketplace\Jobs\SubmitInvoiceToChannelJob;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Support\MercadoLivreFull;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Destrava sozinho o pedido cuja NOTA não saiu — e, com ela, a etiqueta.
 *
 * Pedido do usuário em 2026-09-07, depois do #1604: "esses problemas devem
 * ser resolvidos automaticamente e depois gerar todas etiquetas". Até aqui
 * o único retry automático de nota era o do próprio job (3 tentativas, ~15
 * min) — passou disso, o pedido ficava parado pra sempre esperando alguém
 * olhar. Foi o que aconteceu com 5 pedidos do Mercado Livre: dois com
 * rejeição 232 (IE do destinatário) de ANTES da correção do XML, dois com
 * 539 (duplicidade) de antes da correção da numeração, e um sem nota
 * nenhuma. Os três problemas já estavam resolvidos no código; ninguém
 * mandou tentar de novo.
 *
 * E destravar a nota basta pra etiqueta sair: nota autorizada dispara
 * SubmitInvoiceToChannelJob, o canal libera o envio, CheckShipmentLabelJob
 * baixa a etiqueta e a impressão automática (PRINT_AUTO_SINCE) manda pra
 * impressora. O botão "Gerar etiquetas em lote" continua sendo a rede pro
 * que ficou pra trás do corte.
 *
 * NÃO mexe em nota DENEGADA (cStat 110/301/302): a SEFAZ queima o número e
 * o problema é cadastral, fora do sistema — retry ali é só barulho.
 */
class RetryStuckInvoices extends Command
{
    protected $signature = 'nfe:retry-stuck
        {--minutos=30 : Só tenta de novo notas paradas há mais que isso (evita martelar a SEFAZ)}
        {--forcar : Ignora a espera acima}
        {--sincrono : Processa na hora, sem passar pela fila}
        {--limite=20 : Teto de notas por rodada — trava de segurança, ver comentário}
        {--canal= : Só este canal (ex: mercado_livre)}
        {--so-conferir : Só consulta na SEFAZ as notas em limbo, sem reemitir nada}
        {--incluir-delegados : Inclui na CONSULTA os canais cuja nota é do Bling (auditoria do teto)}';

    protected $description = 'Tenta de novo a NF-e dos pedidos pagos cuja nota ficou pendente, rejeitada ou nunca foi emitida — e redispara o envio travado por causa dela';

    public function handle(): int
    {
        $espera = now()->subMinutes((int) $this->option('minutos'));
        $pack = app(PackDoPedido::class);
        $delegadosAoBling = (array) config('services.bling.invoice_issuer_channels', []);
        $canal = $this->option('canal');

        // A SEFAZ-SP bloqueia o CNPJ por ~1h quando leva requisição demais
        // (cStat 656, "Consumo Indevido"). Durante a punição, tentar de novo
        // só renova a punição — e foi assim que 4 notas do Mercado Livre
        // ficaram repetindo erro em 2026-09-07. Sai sem fazer nada; a
        // próxima rodada de 15 min pega quando liberar.
        if ($bloqueadoAte = app(InvoiceService::class)->bloqueadoPorConsumoIndevidoAte()) {
            $this->warn("SEFAZ bloqueando por consumo indevido (656) até {$bloqueadoAte}. Nada foi enviado — a próxima rodada tenta de novo.");

            return self::SUCCESS;
        }

        // Auditoria do teto do MEI (2026-09-07): as notas do TikTok que
        // ficaram em limbo podem estar autorizadas na SEFAZ sem a gente
        // saber — e aí contam no faturamento. Consultar é leitura; o que
        // muda aqui é só o registro do que a SEFAZ já decidiu.
        $this->reconciliarEnviadas($canal, $this->option('incluir-delegados') ? [] : $delegadosAoBling);
        $this->conferirParadasDePedidoCancelado($canal);

        if ($this->option('so-conferir')) {
            return self::SUCCESS;
        }

        $orders = Order::query()
            ->with('invoice')
            ->where('status', Order::STATUS_PAID)
            ->whereNotIn('origin', array_merge($delegadosAoBling, [Order::ORIGIN_STORE, Order::ORIGIN_MANUAL_INVOICE]))
            ->when($canal, fn ($query) => $query->where('origin', $canal))
            // Full do Mercado Livre: a nota é do ML, não está travada (ver
            // MercadoLivreFull). Sem isto voltaria à fila a cada 15 min.
            ->whereDoesntHave('channelShipment', fn ($q) => $q->where('shipping_method', MercadoLivreFull::LOGISTIC_TYPE))
            ->where(function ($query) {
                $query->whereDoesntHave('invoice')
                    ->orWhereHas('invoice', fn ($q) => $q->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_REJECTED]));
            })
            ->orderBy('id')
            ->get()
            // A espera vale por NOTA, não por pedido: pedido sem nota
            // nenhuma nunca tentou, então entra sempre.
            ->filter(fn (Order $order) => $this->option('forcar')
                || $order->invoice === null
                || $order->invoice->updated_at === null
                || $order->invoice->updated_at->lt($espera))
            // Carrinho do Mercado Livre: o pedido coberto pela nota de outro
            // pedido do carrinho nunca tem nota própria, e o carrinho
            // bloqueado espera o contador — nenhum dos dois é nota travada,
            // e sem isto ocupariam o teto da rodada a cada 15 min.
            ->reject(fn (Order $order) => $pack->cobertoPor($order) || $pack->motivoDeBloqueio($order));

        // TRAVA REAL 2026-09-07, aprendida na primeira execução: rodei isto
        // com BLING_INVOICE_ISSUER_CHANNELS vazio e ele varreu 94 notas do
        // TikTok Shop — canal cuja nota é do BLING, não nossa. Cada
        // rejeição 539 dessas queima um número de NF-e (ver
        // InvoiceService::reserveNewNumber()), e a série 2 pulou de 2041
        // pra 2091 em minutos. Número de nota fiscal não volta.
        //
        // O env foi corrigido (tiktok_shop entrou na lista, que é o certo —
        // ver o incidente da nota dupla de 2026-09-05), mas configuração
        // errada não pode custar 50 números de novo: a partir daqui, um
        // teto por rodada. Se houver mais que isso travado, é acúmulo de
        // verdade e alguém tem que olhar, não é caso pra varredura cega.
        $total = $orders->count();
        $limite = max(1, (int) $this->option('limite'));

        if ($total > $limite) {
            $this->warn("{$total} notas travadas — acima do teto de {$limite} por rodada. Tratando as {$limite} mais antigas; rode de novo (ou aumente --limite) depois de conferir por que são tantas.");
            $orders = $orders->take($limite);
        }

        $this->info("Notas travadas pra tentar de novo: {$orders->count()}");

        foreach ($orders as $order) {
            $motivo = $order->invoice === null
                ? 'nunca emitida'
                : ($order->invoice->status === Invoice::STATUS_PENDING
                    ? 'ficou pendente'
                    : trim(substr((string) $order->invoice->motivo_rejeicao, 0, 90)));

            $this->line("  #{$order->id} ({$order->origin}) — {$motivo}");

            if ($this->option('sincrono')) {
                try {
                    (new GenerateInvoiceJob($order->id))->handle(
                        app(InvoiceService::class),
                        app(OrderFulfillmentTimeline::class),
                        app(OrderImportService::class),
                    );
                    $this->line('     -> '.($order->fresh()->invoice?->status ?? '?'));
                } catch (\Throwable $exception) {
                    $this->warn('     -> falhou: '.substr($exception->getMessage(), 0, 120));

                    // Levou 656 no meio da rodada: a SEFAZ já barrou o CNPJ,
                    // e cada tentativa seguinte renova a punição. Para aqui.
                    if (app(InvoiceService::class)->bloqueadoPorConsumoIndevidoAte()) {
                        $this->warn('     SEFAZ bloqueou por consumo indevido — parando a rodada aqui.');

                        break;
                    }
                }

                continue;
            }

            GenerateInvoiceJob::dispatch($order->id);
        }

        // 3ª parte: envio que morreu ESPERANDO a nota ("Etiqueta não ficou
        // disponível após 4h") não volta sozinho depois que ela sai — o
        // canal precisa ser reconsultado. Sem isto, consertar a nota não
        // faria a etiqueta aparecer.
        $enviosTravados = ChannelShipment::query()
            ->where('status', ChannelShipment::STATUS_ERROR)
            // Canal que disse "não conheço este pedido" não muda de ideia:
            // sem isto, cada um desses era reconsultado a cada 15 min pra
            // sempre (pedido #994: 4.458 consultas ao Bling, 2026-10-09).
            ->whereNull('unrecoverable_at')
            ->whereHas('order', fn ($query) => $query
                ->where('status', Order::STATUS_PAID)
                ->when($canal, fn ($q) => $q->where('origin', $canal))
                ->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_AUTHORIZED)))
            ->get();

        $this->info(PHP_EOL."Envios travados com nota já autorizada: {$enviosTravados->count()}");

        foreach ($enviosTravados as $shipment) {
            $this->line("  pedido #{$shipment->order_id} — reconsultando o canal");
            ConfirmChannelShippingJob::dispatch($shipment->order_id);
        }

        Log::info('nfe.retry_stuck', [
            'notas' => $orders->count(),
            'envios' => $enviosTravados->count(),
        ]);

        return self::SUCCESS;
    }

    /**
     * 1ª parte, e a mais importante: nota que ficou em `sent`.
     *
     * `sent` é o limbo — foi assinada e enviada, mas a resposta da SEFAZ
     * veio sem protocolo (comunicação, timeout, lote em processamento) e
     * NINGUÉM olhava pra ela de novo. O canal não libera envio sem nota
     * autorizada, então a etiqueta nunca sai; e reemitir às cegas arrisca
     * duplicar uma NF-e que talvez já esteja autorizada lá.
     *
     * Consultar por chave é a única resposta confiável, e vem ANTES de
     * qualquer reemissão de propósito: sem isso a rodada seguinte
     * reemitiria em cima de nota autorizada.
     *
     * @param  array<int, string>  $delegadosAoBling
     */
    private function reconciliarEnviadas(?string $canal, array $delegadosAoBling): void
    {
        $enviadas = Invoice::query()
            // `signed` parado há mais de 10 min: o envio caiu no meio (ex.:
            // "Empty reply from server", pedido #3279) e a nota pode estar
            // autorizada lá. Os 10 min evitam pegar um envio em andamento.
            ->where(fn ($query) => $query
                ->where('status', Invoice::STATUS_SENT)
                ->orWhere(fn ($q) => $q
                    ->where('status', Invoice::STATUS_SIGNED)
                    ->where('updated_at', '<', now()->subMinutes(10))))
            ->whereNotNull('chave_acesso')
            ->whereHas('order', fn ($query) => $query
                ->where('status', Order::STATUS_PAID)
                ->whereNotIn('origin', $delegadosAoBling)
                ->when($canal, fn ($q) => $q->where('origin', $canal)))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limite')))
            ->get();

        $this->info("Notas em limbo (enviadas sem resposta) pra conferir na SEFAZ: {$enviadas->count()}");

        foreach ($enviadas as $invoice) {
            try {
                $resolvida = app(InvoiceService::class)->reconcileSent($invoice);
                $this->line("  #{$invoice->order_id} nº {$invoice->numero} -> {$resolvida->status}".
                    ($resolvida->motivo_rejeicao ? ' ('.substr($resolvida->motivo_rejeicao, 0, 70).')' : ''));

                // Autorizada agora: manda pro canal, que é o que destrava a
                // etiqueta. O fluxo normal faz isso no GenerateInvoiceJob.
                if ($resolvida->status === Invoice::STATUS_AUTHORIZED) {
                    SubmitInvoiceToChannelJob::dispatch($resolvida->order_id);
                }
            } catch (\Throwable $exception) {
                $this->warn("  #{$invoice->order_id} -> falhou na consulta: ".substr($exception->getMessage(), 0, 110));
            }
        }

        $this->newLine();
    }

    /**
     * Nota da nossa série parada em pendente/assinada/enviada há mais de
     * 24h de pedido CANCELADO. As rodadas acima só olham pedido pago, então
     * ela ficava invisível pra sempre (ex.: invoice 2261, nº 2981, assinada
     * desde 11/10). Pedido do Lira em 12/11/2026: consulta a SEFAZ pela
     * chave e decide sozinho.
     *
     * - Não consta (217): o número não foi usado. Vira rejeitada com o
     *   motivo, e o buraco entra na inutilização automática
     *   (fiscal:conciliar --executar, depois de 3 dias).
     * - Existe lá: assinada/enviada passa pelo reconcileSent (fica
     *   autorizada e o cancelamento/devolução automático assume); pendente
     *   só alerta, porque não temos o XML assinado.
     * - Resposta inconclusiva: só alerta, nunca age.
     */
    private function conferirParadasDePedidoCancelado(?string $canal): void
    {
        $serie = (int) config('nfe.serie');
        $fechamento = app(FechamentoFiscalService::class);

        $paradas = Invoice::query()
            ->whereIn('status', FechamentoFiscalService::STATUS_PARADOS)
            ->where('ambiente', (string) config('nfe.ambiente'))
            ->whereNotNull('chave_acesso')
            ->where('updated_at', '<', now()->subDay())
            ->where(fn ($q) => $q->whereNull('emitida_por')->orWhere('emitida_por', Invoice::EMITIDA_KAZAKORA))
            ->whereHas('order', fn ($q) => $q
                ->where('status', Order::STATUS_CANCELLED)
                ->when($canal, fn ($q) => $q->where('origin', $canal)))
            ->with('order')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limite')))
            ->get()
            // Série vem da chave: nota do Bling com a coluna sobrescrita não entra.
            ->filter(fn (Invoice $nota) => $fechamento->serieENumero($nota)[0] === $serie);

        if ($paradas->isEmpty()) {
            return;
        }

        $this->info("Notas paradas de pedido cancelado pra conferir na SEFAZ: {$paradas->count()}");
        $sefaz = app(SituacaoNaSefaz::class);
        $alertas = app(AlertasAdmin::class);

        foreach ($paradas as $nota) {
            [, $numero] = $fechamento->serieENumero($nota);
            $rotulo = "invoice {$nota->id} nº {$numero} (pedido #{$nota->order_id}, {$nota->status})";

            try {
                $situacao = $sefaz->consultar((string) $nota->chave_acesso);
            } catch (\Throwable $e) {
                $this->warn("  {$rotulo} -> consulta falhou: ".substr($e->getMessage(), 0, 110));

                continue;
            }

            Log::info('nfe.parada_de_pedido_cancelado', ['invoice_id' => $nota->id, 'numero' => $numero, 'cStat' => $situacao['cStat']]);

            if ($situacao['existe'] === false) {
                $nota->update([
                    'status' => Invoice::STATUS_REJECTED,
                    'motivo_rejeicao' => "Não consta na SEFAZ ({$situacao['cStat']} - {$situacao['xMotivo']}) e o pedido foi cancelado: o número vai para inutilização.",
                ]);
                $this->line("  {$rotulo} -> não consta na SEFAZ; número vai para inutilização");
                rescue(fn () => $alertas->disparar(
                    AlertasAdmin::FISCAL_CONCILIACAO,
                    "parada:{$nota->id}",
                    "NF-e nº {$numero} parada de pedido cancelado: não existe na SEFAZ",
                    "{$rotulo}: a SEFAZ não conhece a chave. O sistema marcou a nota como rejeitada e o número entra na inutilização automática depois de 3 dias (fiscal:conciliar --executar). Nada a fazer, só acompanhar.",
                    "/admin/pedidos/{$nota->order_id}",
                ), report: false);

                continue;
            }

            if ($situacao['existe'] === true && in_array($nota->status, [Invoice::STATUS_SIGNED, Invoice::STATUS_SENT], true)) {
                try {
                    $resolvida = app(InvoiceService::class)->reconcileSent($nota);
                    $this->line("  {$rotulo} -> existe na SEFAZ; registrada como {$resolvida->status}");
                } catch (\Throwable $e) {
                    $this->warn("  {$rotulo} -> existe na SEFAZ, mas não deu pra registrar: ".substr($e->getMessage(), 0, 110));
                }

                continue;
            }

            $this->warn("  {$rotulo} -> SEFAZ respondeu {$situacao['cStat']}; precisa de conferência");
            rescue(fn () => $alertas->disparar(
                AlertasAdmin::FISCAL_CONCILIACAO,
                "parada:{$nota->id}",
                "NF-e nº {$numero} parada de pedido cancelado precisa de conferência",
                "{$rotulo}: a SEFAZ respondeu {$situacao['cStat']} - {$situacao['xMotivo']}. O sistema não age sozinho nesse caso: confira a nota na tela do pedido.",
                "/admin/pedidos/{$nota->order_id}",
            ), report: false);
        }

        $this->newLine();
    }
}
