<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Núcleo de "consultar o canal e, se a etiqueta já estiver pronta,
 * baixar/gravar/registrar" — extraído de PollChannelShippingLabels
 * (2026-08-05) pra ser reaproveitado pelo fluxo orientado a evento
 * (CheckShipmentLabelJob), que é quem dirige o pipeline de etiqueta desde
 * então. O comando de polling continua existindo como fallback manual, mas
 * não roda mais agendado — ver routes/console.php.
 */
class LabelFetchService
{
    /**
     * Canais que recebem a declaração de conteúdo na etiqueta — pedido
     * original 2026-08-15, escopo Shopee/TikTok.
     *
     * TikTok Shop SAIU da lista em 2026-08-31 (pedido explícito do
     * usuário, "quero só a etiqueta impressa, a declaração não" — depois
     * que fetchLabel() passou a funcionar de verdade via Bling): a
     * etiqueta que o Bling devolve pro TikTok Shop é só a etiqueta em si,
     * sem DANFE simplificada numa 2ª página (diferente do Mercado Livre)
     * — overlay de declaração aqui só correria risco de colidir com o
     * layout dela sem necessidade real.
     *
     * Mercado Livre ENTROU nessa lista 2026-08-21 (antes só entrava via
     * $isScheduled abaixo) — achado real numa venda de verdade: a etiqueta
     * dele sempre vem com uma DANFE simplificada numa 2ª página. Passou por
     * uma tentativa de layout combinado numa página só
     * (LabelProcessingService::composeSideBySideLabel(), método ainda
     * existe mas não é mais chamado daqui) — REVERTIDA no mesmo dia, 2
     * vezes seguidas: espremia o código de barras real até ficar ilegível.
     * Hoje usa overlayDeclarationFooter(targetPage: 'last') — a etiqueta
     * original nunca é redimensionada, a faixa de SKU/QTD vai na 2ª página
     * (DANFE), resultando em 2 folhas físicas por pedido do ML (aceito
     * conscientemente: código de barras legível > economia de papel).
     *
     * Shopee usa overlayDeclarationFooter(targetPage: 'first', default) —
     * etiqueta é 1 página só, retrato, faixa no rodapé dela mesma.
     *
     * EXCEÇÃO explícita 2026-08-17: um envio com entrega programada
     * (scheduled_for preenchido, ver ChannelShipment/extractScheduledFor())
     * recebe a mesma declaração de SKU MESMO fora desses canais (hoje só
     * Amazon ficaria de fora sem isso) — a venda saiu dias antes da
     * etiqueta, então o reforço de conferência vale tanto quanto pro caso
     * Shopee original. Ver uso de $isScheduled logo abaixo, não altera esta
     * constante.
     */
    /**
     * Canais cuja etiqueta NUNCA sai pela nossa impressora — ela é emitida
     * e impressa no painel do próprio marketplace.
     *
     * Decisão do usuário, repetida em 2026-09-06 depois de eu ter religado
     * o TikTok por engano e queimado 2 etiquetas: **"do TikTok é do Bling,
     * essas não imprimem pelo nosso fluxo, só Shopee e Mercado Livre — se
     * não, trava a impressora"**. A trava mora aqui, no único ponto que
     * cria PrintJob, pra nenhum caminho novo conseguir furar isso: nem a
     * separação, nem o botão de reimprimir, nem uma varredura futura.
     */
    public const CANAIS_SEM_IMPRESSAO_NOSSA = [
        MarketplaceAccount::CHANNEL_TIKTOK_SHOP,
        MarketplaceAccount::CHANNEL_SHEIN,
    ];

    private const CHANNELS_WITH_DECLARATION = [
        MarketplaceAccount::CHANNEL_SHOPEE,
        MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
    ];

    public function __construct(
        private readonly MarketplaceDriverManager $manager,
        private readonly OrderFulfillmentTimeline $timeline,
        private readonly LabelProcessingService $processor,
    ) {
    }

    /**
     * @return bool true se a etiqueta ficou pronta e foi gravada agora.
     *              false se o canal ainda não liberou — chamador decide o
     *              que fazer (tentar de novo, esperar).
     */
    public function attempt(ChannelShipment $shipment): bool
    {
        if (! $shipment->order) {
            return false;
        }

        // TRAVA REAL 2026-08-12 (incidente): um "empurrão" de checagem
        // disparado por webhook reprocessou 10 pedidos antigos já
        // completed/shipped/cancelled (parados em status não-final do
        // shipment por outro motivo qualquer, às vezes há semanas) e
        // reimprimiu etiquetas físicas reais desnecessárias — uma delas
        // pra um pedido CANCELADO. Etiqueta só faz sentido enquanto o
        // pedido ainda está esperando ser embalado/enviado; nunca gerar
        // (e nunca criar PrintJob) fora disso, não importa o que chamou
        // attempt() ou porque o shipment ainda não tinha status final.
        if ($shipment->order->status !== Order::STATUS_PAID) {
            Log::info('marketplace.label_fetch.skipped_order_not_paid', [
                'shipment_id' => $shipment->id,
                'order_id' => $shipment->order_id,
                'order_status' => $shipment->order->status,
            ]);

            return false;
        }

        $label = $this->manager->driver($shipment->channel)->fetchLabel($shipment->order);

        if (! $label['ready']) {
            return false;
        }

        $contents = $label['contents'];

        // Guarda o arquivo exatamente como o canal devolveu (zip da Shopee,
        // pdf do Mercado Livre), ANTES de qualquer descompactação/conversão
        // abaixo — pedido explícito 2026-08-06: o KoraSync arquiva esse
        // arquivo bruto numa pasta local (Vendas/Mês/Canal/Dia), nomeado
        // pelo código de rastreio. Detecta a extensão pela assinatura real
        // dos bytes, não pelo content_type do canal (já visto vindo inútil,
        // "application/force-download").
        $rawContents = $contents;
        $rawExtension = match (true) {
            str_starts_with($rawContents, "PK\x03\x04") => 'zip',
            str_starts_with($rawContents, '%PDF-') => 'pdf',
            default => 'bin',
        };
        $rawPath = "labels/{$shipment->order_id}/raw-{$shipment->id}.{$rawExtension}";
        Storage::disk('local')->put($rawPath, $rawContents);

        // Achado real 2026-08-07 (pedidos #180/#181/#182 travados na
        // impressão física): a Shopee devolve um ZIP (assinatura real
        // "PK\x03\x04", confirmado nos bytes) contendo um
        // "thermal_zpl_shipping_label.txt" — nunca um PDF direto, mesmo
        // pedindo shipping_document_type=THERMAL_AIR_WAYBILL.
        // content_type vinha "application/force-download" (inútil pra
        // decidir), então a checagem antiga (str_contains content_type,
        // 'pdf') sempre dava falso e o ZIP cru ia direto pro SumatraPDF do
        // KoraSync, que falhava sempre (não é um PDF válido). Descompacta
        // primeiro (se for zip), depois converte o ZPL extraído pra PDF via
        // LabelProcessingService::convertZplToPdf() (já existia, usado só
        // na tela de teste manual).
        //
        // A extração também sabe pegar um PDF de declaração de conteúdo
        // que às vezes vem junto no mesmo zip da Shopee (ver
        // extractShopeeZipContents()) — não usado no fluxo automático
        // desde a volta pro overlayDeclarationFooter() logo abaixo
        // (histórico do dia: composeSideBySideLabel() usava isso pra
        // mostrar a declaração real lado a lado, revertido de volta pro
        // overlay simples). Fica disponível pra quem quiser reativar.
        if (str_starts_with($contents, "PK\x03\x04")) {
            $contents = $this->extractShopeeZipContents($contents)['zpl'];
        }

        // A etiqueta real da Shopee começa com "~DG" (comando ZPL de
        // download de imagem — a etiqueta é um bitmap embutido, ver
        // LabelProcessingService) ANTES do bloco "^XA...^XZ", não direto
        // com "^XA" — checa a presença em vez de exigir como primeiro
        // caractere.
        if (str_contains($contents, '^XA')) {
            // ETIQUETA DO MERCADO LIVRE = AS 2 FOLHAS ORIGINAIS DO CANAL.
            //
            // Decisão do usuário em 2026-09-06, encerrando o assunto: "tem
            // que ser as 2 mesmo, de acordo com a documentação, não pode ser
            // 2 em 1". A tentativa de juntar etiqueta + DANFE numa folha
            // 10x15 (composeMercadoLivreCombinada, atrás da flag
            // ML_ETIQUETA_COMBINADA) foi revertida 3 vezes por deixar o
            // código de barras ilegível — a flag saiu junto com este
            // caminho pra não existir jeito de reativar sem querer. O
            // método continua em LabelProcessingService, sem chamador.
            $contents = $this->processor->convertZplToPdf($contents);
        }

        $isPdf = str_starts_with($contents, '%PDF-');

        // Reativado 2026-08-15, pedido explícito — mas só pra Shopee/TikTok
        // Shop (CHANNELS_WITH_DECLARATION abaixo), não geral como antes de
        // 8a5032d: motivo real é reduzir erro de QUANTIDADE errada enviada
        // (vários casos na implantação inicial), sobrepondo uma "declaração
        // de conteúdo" (SKU | QTD: NN) numa faixa fina no rodapé da própria
        // etiqueta térmica 10x15, pra quem embala conferir antes de fechar
        // a caixa — sem depender de olhar outra tela.
        //
        // HISTÓRICO (mesmo dia, 2026-08-15): overlay original colidiu com o
        // rodapé "DANFE SIMPLIFICADO" real da etiqueta do pedido #307
        // (achado na etiqueta física impressa) -> trocado por página extra
        // (appendDeclarationPage) pra nunca mais colidir -> pedido explícito
        // do usuário voltou atrás: página extra imprime 2 etiquetas físicas
        // por pedido, desperdício real de papel térmico. De volta a overlay
        // (overlayDeclarationFooter), agora só com SKU (sem nome do
        // produto, bem mais curto, reduz — não elimina — o risco de
        // colisão; ver docblock do método pro limite conhecido). Vírgula
        // separa produtos quando o pedido tem mais de um. Só é possível pra
        // etiqueta em PDF; se isso falhar por qualquer motivo, ainda
        // imprime a etiqueta crua em vez de travar o pedido por causa
        // disso.
        //
        // Pedido explícito 2026-08-17: entrega programada (scheduled_for)
        // entra na declaração mesmo fora de CHANNELS_WITH_DECLARATION (ver
        // comentário da constante) — a venda saiu, mas a etiqueta só sai
        // perto da data agendada, o que já é motivo suficiente pra reforçar
        // a conferência. Ganha também uma 2ª linha exclusiva desse caso,
        // "Pedido agendado dia dd/mm/yyyy | Pedido nº X", pra quem embala
        // identificar de cara que aquele pedido específico é um agendado
        // (útil sobretudo quando a etiqueta só libera dias depois da venda,
        // fácil de esquecer o contexto).
        $isScheduled = $shipment->scheduled_for !== null;

        // BUG REAL 2026-08-30 (achado no relato do usuário: a faixa SKU/QTD
        // do Mercado Livre estava saindo na 1ª página — a etiqueta de
        // verdade, colidindo com o layout dela) — $targetPage='last' pra
        // ML abaixo pressupõe que a etiqueta SEMPRE vem em 2 páginas
        // (etiqueta + DANFE simplificada), mas isso não é verdade pro Flex
        // (METHOD_FLEX/self_service, entrega própria do Mercado Livre): a
        // etiqueta dele é 1 página só, sem a 2ª de "DADOS ADICIONAIS" —
        // 'last' então resolve pra a ÚNICA página que existe, a etiqueta
        // real. Decisão do usuário: pra Flex, não estampa SKU/QTD nenhum
        // (nem 1ª nem 2ª página) — só continua saindo na Shopee (1ª
        // página, etiqueta única de verdade) e no Mercado Livre não-Flex
        // (2ª página real, DANFE simplificada).
        $isMercadoLivreFlex = $shipment->channel === MarketplaceAccount::CHANNEL_MERCADO_LIVRE
            && $shipment->shipping_method === ChannelShipment::METHOD_FLEX;

        if ($isPdf && ! $isMercadoLivreFlex && (in_array($shipment->channel, self::CHANNELS_WITH_DECLARATION, true) || $isScheduled)) {
            try {
                $declarationTokens = $shipment->order->items->map(function ($item) {
                    $sku = $item->product?->sku ?: $item->product_name;
                    $quantity = str_pad((string) $item->quantity, 2, '0', STR_PAD_LEFT);

                    return "{$sku} | QTD: {$quantity}";
                })->all();

                $scheduledLine = $isScheduled
                    ? sprintf('Pedido agendado dia %s | Pedido nº %d', $shipment->scheduled_for->format('d/m/Y'), $shipment->order_id)
                    : null;

                // REVERTIDO 2026-08-21 (mesmo dia, 2ª vez): composeSideBySideLabel()
                // (etiqueta original + declaração lado a lado numa página
                // deitada só) espremeu o código de barras do Mercado Livre
                // até ficar ilegível numa etiqueta física real — de novo,
                // mesmo depois da correção de largura nativa (ver histórico
                // completo no docblock de LabelProcessingService::
                // composeSideBySideLabel(), que continua existindo, só não
                // é mais chamado daqui). Pedido explícito do usuário: voltar
                // pro overlayDeclarationFooter() de sempre — NÃO redimensiona
                // nem divide a etiqueta original nenhum pixel, só desenha uma
                // faixa fina "SKU | QTD" por cima da própria etiqueta (ou de
                // uma página extra dela, ver $targetPage), então o código de
                // barras real nunca é tocado.
                //
                // Shopee (targetPage='first', default): etiqueta é 1 página
                // só, retrato, a faixa vai no rodapé dela mesma.
                //
                // Mercado Livre (targetPage='last'): a etiqueta real sempre
                // vem em 2 páginas (etiqueta + DANFE simplificada) — a faixa
                // vai na 2ª (área "DADOS ADICIONAIS", que já vem vazia),
                // NUNCA na 1ª (colidiria com o endereço, achado real
                // anterior). Resultado físico: 2 folhas de papel por pedido
                // do ML — aceito conscientemente pelo usuário, prioriza
                // código de barras legível sobre economia de papel.
                $targetPage = $shipment->channel === MarketplaceAccount::CHANNEL_MERCADO_LIVRE ? 'last' : 'first';

                $contents = $this->processor->overlayDeclarationFooter($contents, $declarationTokens, $scheduledLine, $targetPage);
            } catch (Throwable $exception) {
                Log::warning('marketplace.label_fetch.declaration_failed', ['shipment_id' => $shipment->id, 'message' => $exception->getMessage()]);
            }
        }

        $extension = $isPdf ? 'pdf' : 'bin';
        $path = "labels/{$shipment->order_id}/etiqueta-{$shipment->id}.{$extension}";
        Storage::disk('local')->put($path, $contents);

        // Achado real 2026-08-07 (pedido #183): tracking_code é resolvido só
        // uma vez, dentro de confirmShipping() — na Shopee o número de
        // rastreio pode não existir ainda nesse instante (é atribuído de
        // forma assíncrona depois do ship_order de verdade acontecer), o
        // que deixava o campo gravado vazio pra sempre mesmo depois da
        // etiqueta ficar pronta. Como a etiqueta só existe DEPOIS do
        // rastreio ter sido atribuído de verdade pelo canal, esse é o
        // ponto certo pra reconsultar — nunca bloqueia a etiqueta em si se
        // falhar (o KoraSync só perde o nome "por rastreio" do arquivo
        // arquivado, não a impressão).
        if (! $shipment->tracking_code) {
            $this->refreshTrackingCode($shipment);
        }

        $shipment->update([
            'status' => ChannelShipment::STATUS_LABEL_READY,
            'label_path' => $path,
            'raw_label_path' => $rawPath,
            'label_ready_at' => now(),
        ]);

        $this->timeline->record($shipment->order, OrderFulfillmentEvent::STEP_LABEL_GENERATED, OrderFulfillmentEvent::STATUS_SUCCESS, 'Etiqueta baixada do canal');

        // A etiqueta fica PRONTA aqui, mas não sai na impressora aqui —
        // ver queuePrint(). Regra 8 do briefing do Corea SYNC V3.
        $this->queuePrint($shipment, $path, $rawPath);

        return true;
    }

    /**
     * Cria o PrintJob — a única coisa que faz uma etiqueta sair de verdade
     * na impressora do galpão (o agente local imprime todo job em fila).
     *
     * BUG REAL 2026-09-06, relatado pelo usuário ("imprimiu a etiqueta da
     * Gabriela da Shopee, não está como eu pedi"): baixar a etiqueta e
     * IMPRIMIR a etiqueta eram a mesma linha de código dentro de attempt(),
     * então todo pedido novo saía impresso minutos depois de entrar —
     * pedido #1497 entrou 08:47 e o job #942 imprimiu 08:50, com o pedido
     * ainda PAID e packed_at NULL, ninguém tendo separado nada. Isso é
     * exatamente o que a regra 8 do briefing (2026-09-05) manda acabar:
     * "etiqueta não deve ser gerada quando o pedido entra na fila, nem por
     * cron automático sem separação física".
     *
     * MUDANÇA DE FLUXO 2026-09-07 (duas ordens do usuário no mesmo dia, e a
     * segunda depende da primeira):
     *
     * 1. A separação parou de imprimir — o galpão passou a imprimir as
     *    etiquetas ANTES e a baixa virou só a baixa.
     * 2. **A impressão automática voltou**: assim que o canal libera a
     *    etiqueta, ela vai pra impressora sozinha. Com a impressão
     *    acontecendo antes da separação de qualquer jeito, esperar clique
     *    virou só atraso — o operador quer o papel já na bandeja quando
     *    chegar pra separar.
     *
     * Por isso a exigência de packed_at, que existiu entre 06/09 e 07/09,
     * NÃO está mais aqui. O que segura a impressão continua sendo o que
     * segurava antes dela existir, e é o que importa de verdade: pedido tem
     * que estar PAGO (trava do incidente de 2026-08-12, que reimprimiu 11
     * etiquetas de pedidos velhos, uma delas cancelada), o canal tem que
     * ser um que imprime aqui, e etiqueta que já saiu nunca sai de novo.
     *
     * O botão "Gerar etiquetas em lote" (ver BatchLabelPrintService)
     * continua valendo como rede: é ele que pega o que ficou pra trás
     * quando a impressora estava fora do ar ou quando a etiqueta só foi
     * liberada de madrugada.
     *
     * A dedupe pelo último PrintJob do pedido mantém a idempotência de
     * sempre: retry de rede, uma segunda passada do poll ou o lote rodando
     * em cima não geram uma segunda etiqueta do mesmo pedido.
     *
     * @return bool true se a etiqueta entrou na fila de impressão agora.
     */
    public function queuePrint(ChannelShipment $shipment, ?string $path = null, ?string $rawPath = null): bool
    {
        return $this->enqueue($shipment, $path, $rawPath, automatico: true);
    }

    /**
     * A mesma impressão, pedida por uma PESSOA no botão "Gerar etiquetas em
     * lote" — e por isso sem o corte de data.
     *
     * O corte existe pra máquina não sair imprimindo o represamento antigo
     * sozinha; o botão é justamente a ferramenta pra dar conta desse
     * represamento, com alguém olhando. Todas as outras travas valem igual
     * pros dois caminhos.
     */
    public function queuePrintInBatch(ChannelShipment $shipment): bool
    {
        return $this->enqueue($shipment, null, null, automatico: false);
    }

    private function enqueue(ChannelShipment $shipment, ?string $path, ?string $rawPath, bool $automatico): bool
    {
        $order = $shipment->order;
        $path ??= $shipment->label_path;

        if (! $order || ! $path) {
            return false;
        }

        // A trava do TikTok/Shein (ver CANAIS_SEM_IMPRESSAO_NOSSA). Vale
        // pelo canal do ENVIO e pela origem do pedido: os dois já foram
        // vistos divergindo em pedido criado por ponte.
        if (in_array($shipment->channel, self::CANAIS_SEM_IMPRESSAO_NOSSA, true)
            || in_array($order->origin, self::CANAIS_SEM_IMPRESSAO_NOSSA, true)) {
            Log::info('marketplace.label_fetch.canal_nao_imprime_aqui', [
                'order_id' => $order->id,
                'channel' => $shipment->channel,
            ]);

            return false;
        }

        // Mesma trava do incidente 2026-08-12 (ver attempt()): pedido que
        // não está mais esperando pra ser embalado nunca imprime.
        if ($order->status !== Order::STATUS_PAID) {
            return false;
        }

        // "Somente pedidos a partir desse momento" — a condição que o
        // usuário pôs em cima da volta da impressão automática, 2026-09-07.
        //
        // Quando a automática voltou havia 61 pedidos represados esperando o
        // canal liberar etiqueta, alguns de dias antes. Sem este corte, cada
        // uma dessas etiquetas cairia sozinha na impressora conforme o canal
        // fosse liberando, no meio do dia, sem ninguém esperando por ela —
        // que é a forma exata do estrago de 2026-08-12.
        //
        // Vale só pro caminho automático: o botão de lote existe pra dar
        // conta justamente do que ficou atrás do corte.
        if ($automatico && ! $this->dentroDoCorteAutomatico($order)) {
            return false;
        }

        $ultimo = PrintJob::query()
            ->where('order_id', $shipment->order_id)
            ->where('is_thank_you', false)
            ->latest('id')
            ->first();

        // Já esperando a impressora: não empilha uma segunda etiqueta.
        if ($ultimo && in_array($ultimo->status, [PrintJob::STATUS_QUEUED, PrintJob::STATUS_CLAIMED], true)) {
            return true;
        }

        // Já saiu papel deste pedido: NUNCA sai uma segunda etiqueta
        // sozinha. Devolve false de propósito — o KoraSync consulta o
        // estado real e diz "etiqueta já impressa em 04/09 23:06" em vez de
        // gastar papel por conta própria. A 2ª via existe no botão de
        // reimprimir: é decisão de quem está na bancada, não do servidor.
        //
        // BUG REAL 2026-09-07 (relato do usuário: "tá saindo duplicado e
        // agora não sabe qual é"): a regra de 06/09 reimprimia sozinha
        // sempre que a impressão anterior fosse ANTERIOR ao packed_at, pra
        // cobrir as etiquetas que a impressão automática soltava de manhã e
        // se perdiam no dia. Só que #1419/#1422/#1437 (Flex do Mercado
        // Livre) tinham sido impressos em 04-05/09 pelo mesmo bug antigo, o
        // operador AINDA TINHA aquele papel, e separar hoje soltou uma
        // segunda etiqueta idêntica de cada um — duas do mesmo pedido na
        // bancada, sem saber qual valia.
        //
        // O servidor não tem como saber se o papel de 3 dias atrás ainda
        // existe; quem sabe é quem está com a caixa na mão. Então vale o
        // desempate que o comentário anterior já enunciava e a regra
        // contrariava: etiqueta a mais é papel jogado fora e confunde quem
        // embala; etiqueta a menos tem o botão de reimprimir do lado.
        if ($ultimo && $ultimo->status === PrintJob::STATUS_PRINTED) {
            return false;
        }

        // Nenhum job, ou o último FALHOU (impressora recusou, fila do
        // Windows travada). Falha antiga não pode condenar o pedido a nunca
        // mais imprimir: linha NOVA, porque o agente da loja guarda
        // localmente os ids que já viu e ignora um id repetido.
        PrintJob::create([
            'order_id' => $shipment->order_id,
            'channel' => $shipment->channel,
            'tracking_code' => $shipment->tracking_code,
            'label_path' => $path,
            'raw_label_path' => $rawPath ?? $shipment->raw_label_path,
            'is_thank_you' => false,
            'status' => PrintJob::STATUS_QUEUED,
        ]);

        return true;
    }

    /**
     * A venda entrou depois do momento em que a impressão automática foi
     * religada?
     *
     * Sem PRINT_AUTO_SINCE configurado a resposta é NÃO pra todo mundo: um
     * ambiente sem a data não pode decidir sozinho começar a imprimir o
     * histórico inteiro. Data inválida cai no mesmo lugar, e loga —
     * silêncio aqui viraria papel gasto que ninguém explica.
     */
    private function dentroDoCorteAutomatico(Order $order): bool
    {
        $corte = config('services.print_agent.auto_print_since');

        if (! $corte) {
            Log::info('marketplace.label_fetch.auto_print_desligada', [
                'order_id' => $order->id,
                'motivo' => 'PRINT_AUTO_SINCE não configurado',
            ]);

            return false;
        }

        try {
            $desde = \Illuminate\Support\Carbon::parse($corte);
        } catch (Throwable $exception) {
            Log::warning('marketplace.label_fetch.auto_print_corte_invalido', [
                'valor' => $corte,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        // ERRO REAL 2026-09-07, na mesma tarde em que a automática voltou:
        // gravei PRINT_AUTO_SINCE com a hora do `date` do servidor (UTC) e
        // o app roda em America/Sao_Paulo — o corte caiu 3h no FUTURO e
        // desligou a impressão automática de TUDO, calado. Só apareceu
        // quando uma venda da Shopee (#1602) não imprimiu e o usuário
        // cobrou. Corte no futuro é sempre configuração errada: ninguém
        // liga impressão automática pra daqui a pouco.
        if ($desde->isFuture()) {
            Log::warning('marketplace.label_fetch.corte_no_futuro', [
                'corte' => $desde->toDateTimeString(),
                'agora' => now()->toDateTimeString(),
                'timezone_do_app' => config('app.timezone'),
                'efeito' => 'impressão automática desligada pra todo mundo até essa hora',
            ]);

            // "Ligada desde X" com X no futuro é o mesmo que desligada —
            // e é sempre configuração errada, nunca intenção.
            return false;
        }

        // O QUE O CORTE COMPARA — mudou em 2026-09-07, no mesmo dia:
        //
        // Nasceu comparando a DATA DA VENDA, pra impressão automática não
        // despejar de uma vez as 86 etiquetas represadas. Só que isso
        // barrava também as vendas AGENDADAS do Mercado Livre: venda de dias
        // atrás cuja etiqueta o canal só libera na véspera da coleta —
        // exatamente o que o usuário pediu pra sair sozinho ("já deveriam
        // estar liberadas... emite, gera etiqueta e coloca na fila de
        // separação, isso quero auto").
        //
        // Agora o corte é sobre o MOMENTO em que a etiqueta chega: daqui pra
        // frente, etiqueta que o canal libera vai pra impressora sozinha,
        // não importa quando a venda entrou. E isso não despeja represamento
        // nenhum: quem já tem etiqueta baixada não passa mais por aqui (o
        // attempt() só roda pra quem ainda não tem), e etiqueta já impressa
        // nunca sai de novo. O botão "Gerar etiquetas em lote" continua
        // sendo como se resolve o que ficou pra trás, com alguém olhando.
        return true;
    }

    /**
     * @see attempt() pro motivo — chama confirmShipping() de novo só pra
     * pegar o tracking_code atualizado (idempotente em todos os drivers
     * reais: ML só consulta o shipment já existente, Shopee trata
     * ship_order redundante como não-erro desde a correção de 2026-08-07).
     * Nunca lança — falha aqui não pode derrubar a etiqueta já pronta.
     */
    private function refreshTrackingCode(ChannelShipment $shipment): void
    {
        try {
            $result = $this->manager->driver($shipment->channel)->confirmShipping($shipment->order);
        } catch (Throwable $exception) {
            Log::warning('marketplace.label_fetch.tracking_code_refresh_failed', ['shipment_id' => $shipment->id, 'message' => $exception->getMessage()]);

            return;
        }

        if (! empty($result['tracking_code'])) {
            $shipment->tracking_code = $result['tracking_code'];
        }
    }

    /**
     * O zip da Shopee costumava trazer um único arquivo de verdade dentro
     * (thermal_zpl_shipping_label.txt, confirmado ao vivo) — hoje sabemos
     * que também pode trazer um 2º arquivo, o PDF da declaração de
     * conteúdo (ver $alsoExtractDeclarationPdf abaixo). Pega o entry do ZPL
     * procurando pela assinatura, em vez de fixar um nome exato (varia por
     * conta/idioma) ou assumir "primeiro entry = certo".
     *
     * O zip do Mercado Livre (response_type=zpl2, ver MercadoLivreDriver::
     * fetchLabel(), bug real 2026-08-10) também passa por aqui — o zip DELE
     * vem com um PDF que NÃO é declaração nenhuma, é a PLP (folha de outro
     * propósito). Procura primeiro por um entry .txt contendo "^XA"
     * (assinatura real de ZPL); só cai pro índice 0 se não achar nenhum
     * .txt (mantém o comportamento antigo intacto pro zip de arquivo único
     * da Shopee).
     *
     * $alsoExtractDeclarationPdf (pedido explícito 2026-08-21, corrigindo
     * um chute anterior no mesmo dia — ver histórico removido de
     * ShopeeDriver::fetchContentDeclaration()): o zip que a Shopee devolve
     * pro pedido de etiqueta térmica (download_shipping_document,
     * THERMAL_AIR_WAYBILL, ver ShopeeDriver::fetchLabel()) já vem com DOIS
     * arquivos de verdade — o .txt do ZPL E um PDF da declaração de
     * conteúdo — confirmado pelo usuário baixando direto do painel da
     * Shopee. Nenhuma chamada de API extra é necessária.
     *
     * Fica atrás de um parâmetro (default false) em vez de virar
     * comportamento sempre-ligado porque este mesmo método também
     * descompacta o zip do Mercado Livre (response_type=zpl2, achado
     * 2026-08-10) — o zip DELE vem com um PDF que NÃO é declaração
     * nenhuma, é a PLP (folha de outro propósito, ver comentário no
     * chamador). Tratar qualquer PDF achado num zip como "a declaração"
     * sem essa distinção por canal geraria falso positivo grave pro ML.
     *
     * @return array{zpl: string, declaration_pdf: ?string}
     */
    private function extractShopeeZipContents(string $zipContents, bool $alsoExtractDeclarationPdf = false): array
    {
        $tempZipPath = tempnam(sys_get_temp_dir(), 'shopee_label_').'.zip';
        file_put_contents($tempZipPath, $zipContents);

        try {
            $zip = new ZipArchive();

            if ($zip->open($tempZipPath) !== true) {
                throw new RuntimeException('Não foi possível abrir o zip da etiqueta.');
            }

            if ($zip->numFiles < 1) {
                throw new RuntimeException('Zip da etiqueta veio vazio.');
            }

            $zplEntryName = null;
            $declarationPdf = null;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $candidateName = $zip->getNameIndex($i);
                $candidateContents = $zip->getFromName($candidateName);

                if ($candidateContents === false) {
                    continue;
                }

                if ($zplEntryName === null && str_ends_with(strtolower($candidateName), '.txt') && str_contains($candidateContents, '^XA')) {
                    $zplEntryName = $candidateName;
                }

                if ($alsoExtractDeclarationPdf && $declarationPdf === null && str_starts_with($candidateContents, '%PDF-')) {
                    $declarationPdf = $candidateContents;
                }
            }

            $zplEntryName ??= $zip->getNameIndex(0);
            $zpl = $zip->getFromName($zplEntryName);
            $zip->close();

            if ($zpl === false) {
                throw new RuntimeException("Não foi possível extrair \"{$zplEntryName}\" do zip da etiqueta.");
            }

            return ['zpl' => $zpl, 'declaration_pdf' => $declarationPdf];
        } finally {
            @unlink($tempZipPath);
        }
    }
}
