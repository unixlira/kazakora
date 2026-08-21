<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Drivers\ShopeeDriver;
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
     * dele sempre vem com uma DANFE simplificada numa 2ª página, e a
     * declaração precisa ir na etiqueta física de qualquer forma, agendada
     * ou não. Ver LabelProcessingService::composeSideBySideLabel() pro
     * layout novo (etiqueta original + declaração lado a lado numa única
     * etiqueta física, em vez de sobrepor por cima) — a 2ª página (DANFE)
     * não vai mais pro papel térmico, só continua arquivada intacta em
     * raw_label_path.
     *
     * Shopee saiu e VOLTOU pra essa lista no mesmo dia (histórico real,
     * sem apagar pra não repetir a volta e meia): tentativa 1 tirou Shopee
     * daqui achando que a declaração real dela era só um documento externo
     * ao Kazakora, sem nada a baixar; pedido explícito seguinte corrigiu
     * isso — a declaração É baixável via API própria da Shopee (ver
     * ShopeeDriver::fetchContentDeclaration()), só que é um PDF SEPARADO da
     * etiqueta térmica (não uma 2ª página do mesmo arquivo, como o ML) —
     * por isso usa $rightSide='external' com um 2º PDF em vez de 'danfe'.
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

        // Resolvido uma vez só e reaproveitado (ver uso mais abaixo pra
        // ShopeeDriver::fetchContentDeclaration()) — resolver de novo ali
        // chamava driver() 2x por tentativa à toa.
        $driver = $this->manager->driver($shipment->channel);

        $label = $driver->fetchLabel($shipment->order);

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
        if (str_starts_with($contents, "PK\x03\x04")) {
            $contents = $this->extractZplFromZip($contents);
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

        if ($isPdf && (in_array($shipment->channel, self::CHANNELS_WITH_DECLARATION, true) || $isScheduled)) {
            try {
                $declarationTokens = $shipment->order->items->map(function ($item) {
                    $sku = $item->product?->sku ?: $item->product_name;
                    $quantity = str_pad((string) $item->quantity, 2, '0', STR_PAD_LEFT);

                    return "{$sku} | QTD: {$quantity}";
                })->all();

                $scheduledLine = $isScheduled
                    ? sprintf('Pedido agendado dia %s | Pedido nº %d', $shipment->scheduled_for->format('d/m/Y'), $shipment->order_id)
                    : null;

                // Pedido explícito 2026-08-21: etiqueta original + metade
                // direita lado a lado numa etiqueta física só
                // (composeSideBySideLabel()), não mais sobreposta por cima
                // (overlayDeclarationFooter() — risco real de colisão, foi o
                // que atropelou o endereço numa etiqueta real do Mercado
                // Livre). Mercado Livre mostra a DANFE de verdade (2ª página
                // original, com a chave de acesso) na metade direita — a
                // declaração de SKU ainda entra, só que como faixa fina no
                // rodapé dessa metade (área "DADOS ADICIONAIS" que já vem
                // vazia na DANFE real).
                //
                // Shopee mostra a declaração de conteúdo REAL dela, baixada
                // separada (ShopeeDriver::fetchContentDeclaration() — best
                // effort, nunca lança, devolve null se falhar). Documento
                // externo já traz os produtos, então NÃO leva a faixa de
                // SKU/QTD por cima (ver composeSideBySideLabel(), 'external').
                // Se o fetch falhar por qualquer motivo, cai pro painel de
                // declaração desenhado localmente (mesmo fallback de
                // TikTok/canais sem documento nenhum pra mostrar).
                $rightSide = 'declaration';
                $rightPdfBytes = null;

                if ($shipment->channel === MarketplaceAccount::CHANNEL_MERCADO_LIVRE) {
                    $rightSide = 'danfe';
                } elseif ($shipment->channel === MarketplaceAccount::CHANNEL_SHOPEE) {
                    if ($driver instanceof ShopeeDriver) {
                        $rightPdfBytes = $driver->fetchContentDeclaration($shipment->order);

                        if ($rightPdfBytes !== null) {
                            $rightSide = 'external';
                        }
                    }
                }

                $contents = $this->processor->composeSideBySideLabel($contents, $declarationTokens, $scheduledLine, $rightSide, $rightPdfBytes);
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
     * O zip da Shopee tem um único arquivo de verdade dentro
     * (thermal_zpl_shipping_label.txt, confirmado ao vivo) — pega o
     * primeiro/único entry em vez de fixar esse nome exato, que pode variar
     * por conta/idioma sem aviso.
     *
     * O zip do Mercado Livre (response_type=zpl2, ver MercadoLivreDriver::
     * fetchLabel(), bug real 2026-08-10) já vem com DOIS arquivos — um PDF
     * da PLP (não é a etiqueta) e um .txt com o ZPL de verdade — então não
     * dá mais pra assumir "primeiro entry = certo" como antes. Procura
     * primeiro por um entry .txt contendo "^XA" (assinatura real de ZPL);
     * só cai pro índice 0 se não achar nenhum .txt (mantém o comportamento
     * antigo intacto pro zip de arquivo único da Shopee).
     */
    private function extractZplFromZip(string $zipContents): string
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

            $entryName = null;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $candidateName = $zip->getNameIndex($i);

                if (str_ends_with(strtolower($candidateName), '.txt')) {
                    $candidateContents = $zip->getFromName($candidateName);

                    if ($candidateContents !== false && str_contains($candidateContents, '^XA')) {
                        $entryName = $candidateName;

                        break;
                    }
                }
            }

            $entryName ??= $zip->getNameIndex(0);
            $extracted = $zip->getFromName($entryName);
            $zip->close();

            if ($extracted === false) {
                throw new RuntimeException("Não foi possível extrair \"{$entryName}\" do zip da etiqueta.");
            }

            return $extracted;
        } finally {
            @unlink($tempZipPath);
        }
    }
}
