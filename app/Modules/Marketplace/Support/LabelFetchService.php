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
     * original 2026-08-15, escopo Shopee/TikTok. TikTok Shop ainda não tem
     * fetchLabel() implementado (ver TikTokShopDriver — integração
     * pendente de credencial de parceiro), então isso fica pronto e sem
     * efeito prático até esse driver existir.
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
    private const CHANNELS_WITH_DECLARATION = [
        MarketplaceAccount::CHANNEL_SHOPEE,
        MarketplaceAccount::CHANNEL_TIKTOK_SHOP,
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

        PrintJob::query()->firstOrCreate(
            ['order_id' => $shipment->order_id],
            [
                'channel' => $shipment->channel,
                'tracking_code' => $shipment->tracking_code,
                'label_path' => $path,
                'raw_label_path' => $rawPath,
                'status' => PrintJob::STATUS_QUEUED,
            ],
        );

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
