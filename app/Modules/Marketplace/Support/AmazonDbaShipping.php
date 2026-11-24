<?php

namespace App\Modules\Marketplace\Support;

use App\Models\User;
use App\Modules\Admin\Support\AlertasAdmin;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Exceptions\AgendamentoDbaManualException;
use App\Modules\Marketplace\Jobs\AguardarAgendamentoDbaJob;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Operacional\Mail\AgendarDbaNoSellerCentral;
use App\Notifications\LabelUnavailableNotification;
use App\Services\Amazon\AmazonClient;
use App\Services\Amazon\Exceptions\AmazonException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Envio da Amazon pelo DBA (Delivery by Amazon / Easy Ship), levado pela
 * loja ao Ponto de Envio. Pedido do Lira 03/11/2026: "Quero levar num
 * ponto de coleta da Amazon. Não quero que seja enviado pelo correio e não
 * quero fazer o full fulfillment" — "mude o KazaKora para fazer o processo
 * automático sem Correios". Pedido Amazon NUNCA gera pré-postagem dos
 * Correios nem confirma envio "Correios" na Amazon.
 *
 *   NF-e autorizada pelo KazaKora
 *   → enviada à Amazon (AmazonDriver::submitInvoice(), Delivery by Amazon
 *     API, programType EasyShip = DBA no Brasil)
 *   → Amazon aceita a nota (getInvoiceStatus "Accepted")
 *   → horário de Ponto de Envio (drop-off) escolhido em listHandoverSlots
 *   → pacote agendado (Easy Ship) com rastreio TBR
 *   → etiqueta da Amazon em PDF → impressão automática (LabelFetchService)
 *
 * Coleta em casa (PICKUP) nunca é escolhida. Sem horário de drop-off, ou
 * qualquer falha, o envio fica em erro com o motivo na linha do tempo e o
 * job tenta de novo (ConfirmChannelShippingJob); ao esgotar, vai pro sino.
 *
 * Idempotente: pacote que já existe na Amazon (agendado aqui antes ou à mão
 * no Seller Central) é reaproveitado, nunca agendado de novo.
 *
 * Etiqueta (24/11/2026, pedido #4474): quando o pacote existe mas o PDF não
 * veio no agendamento (Seller Central, ou createScheduledPackage simples), a
 * etiqueta é pedida pelo feed POST_EASYSHIP_DOCUMENTS → relatório →
 * documento (buscarEtiqueta(), chamado pelo CheckShipmentLabelJob via
 * AmazonDriver::fetchLabel()). Cada chamada avança um passo e volta; nada
 * de esperar parado dentro do worker.
 *
 * Pedido em que a Amazon responde "The operation is not supported for the
 * given order" no listHandoverSlots: tenta o createScheduledPackageBulk sem
 * horário (a Amazon escolhe o primeiro horário possível). Se nem isso,
 * alerta no sino + e-mail para agendar no Seller Central e
 * AguardarAgendamentoDbaJob confere a cada 30 min até o prazo de envio.
 */
class AmazonDbaShipping
{
    /** Gravado em channel_shipments.shipping_method — é por ele que o resto do sistema reconhece o envio DBA. */
    public const METODO = 'Amazon DBA (Ponto de Envio)';

    private const API = '/easyShip/2022-03-23';

    private const FEED_TIPO = 'POST_EASYSHIP_DOCUMENTS';

    /** Intervalo mínimo entre dois passos do feed da etiqueta para o mesmo pedido. */
    public const FEED_INTERVALO_SEGUNDOS = 30;

    /** Feeds de etiqueta pedidos por pedido, por dia, antes de desistir e avisar. */
    private const FEED_MAX_TENTATIVAS = 3;

    /** Feed ou relatório parado em processamento por mais que isso é pedido de novo. */
    private const FEED_PRAZO_MINUTOS = 30;

    public function __construct(
        private readonly AmazonClient $client,
        private readonly PackageDataResolver $pacotes,
        private readonly OrderFulfillmentTimeline $timeline,
    ) {}

    public static function ehDba(?ChannelShipment $shipment): bool
    {
        return $shipment !== null && str_starts_with((string) $shipment->shipping_method, 'Amazon DBA');
    }

    /** Onde fica a etiqueta baixada da Amazon até o LabelFetchService pegar. */
    public static function caminhoEtiqueta(Order $order): string
    {
        return "labels/{$order->id}/amazon-dba.pdf";
    }

    /**
     * @return array{external_shipment_id: ?string, tracking_code: ?string, shipping_method: string, status: string}
     */
    public function agendar(Order $order): array
    {
        return Cache::lock("amazon:dba:{$order->id}", 180)->block(60, fn () => $this->agendarSemConcorrencia($order));
    }

    /** PDF da etiqueta já baixada, ou null se ainda não há. */
    public function etiqueta(Order $order): ?string
    {
        $caminho = self::caminhoEtiqueta($order);

        return Storage::disk('local')->exists($caminho) ? Storage::disk('local')->get($caminho) : null;
    }

    /** O pedido já tem pacote agendado na Amazon (por nós ou no Seller Central)? */
    public function pacoteAgendado(Order $order): bool
    {
        return $this->pacoteNaAmazon((string) $order->external_order_id) !== null;
    }

    /**
     * Etiqueta pronta, ou um passo a mais no feed POST_EASYSHIP_DOCUMENTS
     * (null enquanto a Amazon processa). Feita para ser chamada de novo e de
     * novo pelo CheckShipmentLabelJob: no máximo um passo a cada
     * FEED_INTERVALO_SEGUNDOS por pedido, e nunca lança.
     */
    public function buscarEtiqueta(Order $order): ?string
    {
        if (($pdf = $this->etiqueta($order)) !== null) {
            return $pdf;
        }

        if (! $order->external_order_id || ! Cache::add("amazon:dba:feed-etiqueta:passo:{$order->id}", true, self::FEED_INTERVALO_SEGUNDOS)) {
            return null;
        }

        try {
            return Cache::lock("amazon:dba:feed-etiqueta:trava:{$order->id}", 120)
                ->get(fn () => $this->avancarFeedDaEtiqueta($order));
        } catch (Throwable $exception) {
            Log::channel('amazon')->warning('amazon.dba.feed_etiqueta_falhou', ['order_id' => $order->id, 'erro' => $exception->getMessage()]);

            return null;
        }
    }

    private function agendarSemConcorrencia(Order $order): array
    {
        $amazonOrderId = (string) $order->external_order_id;

        // Já agendado (por nós numa tentativa anterior ou à mão no Seller
        // Central): só lê o que está lá.
        if ($pacote = $this->pacoteNaAmazon($amazonOrderId)) {
            return $this->resultado($order, $pacote, null);
        }

        $invoice = $order->invoice()->first();

        if (! $invoice || $invoice->status !== Invoice::STATUS_AUTHORIZED || ! $invoice->chave_acesso) {
            throw new RuntimeException("Pedido #{$order->id}: NF-e ainda não autorizada — o agendamento no DBA da Amazon espera a nota.");
        }

        $statusNota = $this->statusDaNotaNaAmazon($amazonOrderId);

        if ($statusNota !== 'Accepted') {
            throw new RuntimeException("Pedido #{$order->id}: NF-e ainda não aceita pela Amazon (situação: {$statusNota}). O agendamento no DBA espera a Amazon aceitar a nota.");
        }

        $medidas = $this->pacotes->forOrder($order, sempreComMedidas: true);

        $slot = $this->horarioDePontoDeEnvio($order, $amazonOrderId, $medidas);

        [$pacote, $urlDocumentos] = $slot === null
            ? $this->agendarSemHorario($order, $amazonOrderId)
            : $this->criarPacote($order, $amazonOrderId, $slot);

        $etiqueta = null;

        if ($urlDocumentos) {
            try {
                $etiqueta = $this->baixarEtiqueta($urlDocumentos);
            } catch (Throwable $exception) {
                Log::channel('amazon')->warning('amazon.dba.etiqueta_nao_baixada', ['order_id' => $order->id, 'erro' => $exception->getMessage()]);
            }
        }

        // createScheduledPackage nem sempre devolve o rastreio na hora.
        if (empty($pacote['trackingDetails']['trackingId'])) {
            $pacote = $this->pacoteNaAmazon($amazonOrderId) ?? $pacote;
        }

        return $this->resultado($order, $pacote, $etiqueta);
    }

    /**
     * @param  array<string, mixed>  $pacote
     * @return array{external_shipment_id: ?string, tracking_code: ?string, shipping_method: string, status: string}
     */
    private function resultado(Order $order, array $pacote, ?string $etiqueta): array
    {
        $rastreio = $pacote['trackingDetails']['trackingId'] ?? null;
        $packageId = $pacote['scheduledPackageId']['packageId'] ?? null;
        $janela = $this->janela($pacote['packageTimeSlot'] ?? []);

        if ($etiqueta !== null) {
            Storage::disk('local')->put(self::caminhoEtiqueta($order), $etiqueta);
        }

        $temEtiqueta = $etiqueta !== null || Storage::disk('local')->exists(self::caminhoEtiqueta($order));

        // Agendado (aqui ou no Seller Central): o pedido de agendamento manual, se houve, acabou.
        rescue(fn () => app(AlertasAdmin::class)->resolver(AlertasAdmin::AMAZON_DBA_AGENDAMENTO, "pedido:{$order->id}"), report: false);

        $this->timeline->record(
            $order,
            OrderFulfillmentEvent::STEP_SHIPPING_CONFIRMED,
            OrderFulfillmentEvent::STATUS_SUCCESS,
            trim("Agendado no DBA da Amazon (Ponto de Envio): rastreio {$rastreio}".($janela ? ", levar {$janela}" : '').'.'),
        );

        // Sem PDF no agendamento: o CheckShipmentLabelJob pede a etiqueta
        // pelo feed POST_EASYSHIP_DOCUMENTS (buscarEtiqueta()). O aviso para
        // imprimir no Seller Central só sai se o feed também falhar.
        if (! $temEtiqueta) {
            $this->timeline->record(
                $order,
                OrderFulfillmentEvent::STEP_LABEL_GENERATED,
                OrderFulfillmentEvent::STATUS_PENDING,
                "Pacote DBA {$rastreio} agendado sem a etiqueta em PDF: pedindo à Amazon pelo feed de documentos do Easy Ship.",
            );
        }

        return [
            'external_shipment_id' => $packageId,
            'tracking_code' => $rastreio,
            'shipping_method' => self::METODO,
            'status' => 'confirmed',
        ];
    }

    /** @return array<string, mixed>|null */
    private function pacoteNaAmazon(string $amazonOrderId): ?array
    {
        try {
            $pacote = $this->client->get(self::API.'/package', [
                'amazonOrderId' => $amazonOrderId,
                'marketplaceId' => config('services.amazon.marketplace_id'),
            ]);
        } catch (AmazonException $exception) {
            // 4xx = pedido ainda sem pacote. 5xx/rede sobe: não agenda às
            // cegas sem saber se já existe pacote.
            if ($exception->getCode() >= 400 && $exception->getCode() < 500) {
                return null;
            }

            throw $exception;
        }

        return empty($pacote['scheduledPackageId']) && empty($pacote['trackingDetails']) ? null : $pacote;
    }

    private function statusDaNotaNaAmazon(string $amazonOrderId): string
    {
        $resposta = $this->client->get('/delivery/2022-07-01/invoice/status', [
            'marketplaceId' => config('services.amazon.marketplace_id'),
            'programType' => 'EasyShip',
            'orderId' => $amazonOrderId,
            'invoiceType' => 'Outbound',
        ]);

        return (string) ($resposta['orderInvoicingStatus'] ?? $resposta['invoiceStatus'] ?? 'NotFound');
    }

    /**
     * Só Ponto de Envio (drop-off). A conta do Lira é "DBA postagem": o
     * slot de drop-off da Amazon Brasil vem com "3PDO" no slotId (visto no
     * pedido 701-9739703-8713050, 03/11: "AMZL_BR_MFN_PICKUP_3PDO") e às
     * vezes sem handoverMethod. Coleta em casa nunca.
     *
     * Escolhe o drop-off MAIS CEDO (ordenado por startTime).
     *
     * null = a Amazon não lista horários para este pedido pela API ("The
     * operation is not supported for the given order", pedido #4474,
     * 24/11/2026, "Std EZ Regional"); quem chama tenta agendar sem horário.
     *
     * @param  array{weight_grams: int, height: ?float, width: ?float, length: ?float}  $medidas
     * @return array<string, mixed>|null
     */
    private function horarioDePontoDeEnvio(Order $order, string $amazonOrderId, array $medidas): ?array
    {
        try {
            $resposta = $this->pedirHorarios($amazonOrderId, $medidas);
        } catch (AmazonException $exception) {
            if (self::naoSuportado($exception)) {
                Log::channel('amazon')->warning('amazon.dba.horarios_nao_suportados', ['order_id' => $order->id, 'erro' => $exception->getMessage()]);

                return null;
            }

            throw $exception;
        }

        $slot = collect($resposta['timeSlots'] ?? [])
            ->filter(fn ($slot) => is_array($slot) && ! empty($slot['slotId']) && self::ehPontoDeEnvio($slot))
            ->sortBy(fn ($slot) => (string) ($slot['startTime'] ?? ''))
            ->first();

        if (! $slot) {
            throw new RuntimeException("Pedido #{$order->id}: a Amazon não ofereceu horário de Ponto de Envio (drop-off) no DBA. Coleta em casa não é usada.");
        }

        return $slot;
    }

    public static function naoSuportado(Throwable $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'not supported');
    }

    /**
     * @param  array{weight_grams: int, height: ?float, width: ?float, length: ?float}  $medidas
     * @return array<string, mixed>
     */
    private function pedirHorarios(string $amazonOrderId, array $medidas): array
    {
        return $this->client->post(self::API.'/timeSlot', [
            'marketplaceId' => config('services.amazon.marketplace_id'),
            'amazonOrderId' => $amazonOrderId,
            'packageDimensions' => [
                'length' => (float) $medidas['length'],
                'width' => (float) $medidas['width'],
                'height' => (float) $medidas['height'],
                'unit' => 'cm',
            ],
            'packageWeight' => ['value' => (float) $medidas['weight_grams'], 'unit' => 'grams'],
        ]);
    }

    /**
     * Sem lista de horários pela API: o createScheduledPackageBulk sem
     * packageDetails agenda no primeiro horário possível (doc do Easy Ship
     * 2022-03-23: "if the time slot is not provided, Amazon will then pick
     * the earliest time slot possible"). A conta é DBA postagem (só Ponto
     * de Envio); se mesmo assim a Amazon marcar coleta, avisa para trocar.
     * Se a Amazon recusar também, pede o agendamento no Seller Central.
     *
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function agendarSemHorario(Order $order, string $amazonOrderId): array
    {
        $motivo = 'a Amazon não lista horários de Ponto de Envio deste pedido pela API (operation is not supported)';
        $lote = [];

        try {
            $lote = $this->client->post(self::API.'/packages/bulk', [
                'marketplaceId' => config('services.amazon.marketplace_id'),
                'orderScheduleDetailsList' => [['amazonOrderId' => $amazonOrderId]],
                'labelFormat' => 'PDF',
            ]);
        } catch (AmazonException $exception) {
            Log::channel('amazon')->warning('amazon.dba.lote_sem_horario_recusado', ['order_id' => $order->id, 'erro' => $exception->getMessage()]);

            if ($pacote = $this->pacoteNaAmazon($amazonOrderId)) {
                return [$pacote, null];
            }

            $this->pedirAgendamentoManual($order, "{$motivo}, e o agendamento automático também foi recusado ({$exception->getMessage()})");
        }

        $recusado = collect($lote['rejectedOrders'] ?? [])->firstWhere('amazonOrderId', $amazonOrderId);
        $pacote = collect($lote['scheduledPackages'] ?? [])
            ->first(fn ($p) => ($p['scheduledPackageId']['amazonOrderId'] ?? null) === $amazonOrderId)
            ?? $this->pacoteNaAmazon($amazonOrderId);

        if (! $pacote) {
            $erro = $recusado['error']['message'] ?? 'a Amazon não devolveu o pacote';

            $this->pedirAgendamentoManual($order, "{$motivo}, e o agendamento automático também foi recusado ({$erro})");
        }

        $horario = $pacote['packageTimeSlot'] ?? [];

        if (strtoupper((string) ($horario['handoverMethod'] ?? '')) === 'PICKUP' && ! self::ehPontoDeEnvio($horario)) {
            rescue(fn () => app(AlertasAdmin::class)->disparar(
                AlertasAdmin::AMAZON_DBA_AGENDAMENTO,
                "coleta:{$order->id}",
                "Amazon agendou COLETA no pedido #{$order->id}",
                "Sem horários pela API, a Amazon agendou o pedido {$amazonOrderId} sozinha e escolheu coleta em casa. Troque para Ponto de Envio no Seller Central.",
                $this->linkDoPedido($order),
            ), report: false);
        }

        return [$pacote, $lote['printableDocumentsUrl'] ?? null];
    }

    /**
     * Nada agendou pela API: sino + e-mail ("agende no Seller Central") e
     * AguardarAgendamentoDbaJob conferindo a cada 30 min até o prazo. Lança
     * AgendamentoDbaManualException, que o ConfirmChannelShippingJob não
     * repete (quem segue é o AguardarAgendamentoDbaJob).
     */
    private function pedirAgendamentoManual(Order $order, string $motivo): never
    {
        $prazo = $this->prazoDeEnvio((string) $order->external_order_id);
        $prazoTexto = $prazo->clone()->setTimezone(config('app.timezone'))->format('d/m/Y H:i');
        $mensagem = "Agende no Seller Central: pedido {$order->external_order_id} (#{$order->id}), Ponto de Envio na data mais próxima, prazo {$prazoTexto}. Motivo: {$motivo}. O sistema confere a cada 30 min e baixa a etiqueta sozinho quando o pacote aparecer.";

        $this->timeline->record($order, OrderFulfillmentEvent::STEP_SHIPPING_CONFIRMED, OrderFulfillmentEvent::STATUS_PENDING, $mensagem);

        rescue(fn () => app(AlertasAdmin::class)->disparar(
            AlertasAdmin::AMAZON_DBA_AGENDAMENTO,
            "pedido:{$order->id}",
            "Agende no Seller Central: pedido Amazon #{$order->id}",
            $mensagem,
            $this->linkDoPedido($order),
        ), report: false);

        $this->mandarEmailUmaVez($order, $prazoTexto, $motivo);

        AguardarAgendamentoDbaJob::dispatch($order->id, $prazo->toImmutable())
            ->delay(now()->addMinutes(AguardarAgendamentoDbaJob::INTERVALO_MINUTOS));

        throw new AgendamentoDbaManualException("Pedido #{$order->id}: agende no Seller Central (Ponto de Envio, prazo {$prazoTexto}). Motivo: {$motivo}.");
    }

    /** LatestShipDate do pedido na Amazon; sem ele, 24h a partir de agora. */
    private function prazoDeEnvio(string $amazonOrderId): Carbon
    {
        try {
            $pedido = $this->client->get('/orders/v0/orders/'.$amazonOrderId);
            $data = $pedido['payload']['LatestShipDate'] ?? null;

            if ($data) {
                return Carbon::parse($data);
            }
        } catch (Throwable $exception) {
            Log::channel('amazon')->warning('amazon.dba.prazo_sem_resposta', ['amazon_order_id' => $amazonOrderId, 'erro' => $exception->getMessage()]);
        }

        return now()->addDay();
    }

    private function mandarEmailUmaVez(Order $order, string $prazo, string $motivo): void
    {
        $cfg = config('services.alerta_coleta_email');

        if (! ($cfg['ativo'] ?? false) || ! ($cfg['para'] ?? null)) {
            return;
        }

        $chave = "amazon:dba:email-agendar:{$order->id}";

        if (! Cache::add($chave, true, now()->addDays(3))) {
            return;
        }

        try {
            Mail::to($cfg['para'])->send(new AgendarDbaNoSellerCentral(
                (string) $order->external_order_id,
                $order->id,
                $prazo,
                $motivo,
                $this->linkDoPedido($order),
            ));
        } catch (Throwable $exception) {
            Cache::forget($chave);
            report($exception);
        }
    }

    private function linkDoPedido(Order $order): ?string
    {
        return rescue(fn () => route('admin.pedidos.exibir', $order), null, false);
    }

    /** @param  array<string, mixed>  $slot */
    public static function ehPontoDeEnvio(array $slot): bool
    {
        if (strtoupper((string) ($slot['handoverMethod'] ?? '')) === 'DROPOFF') {
            return true;
        }

        $id = (string) ($slot['slotId'] ?? '');
        $dentro = str_contains($id, '.') ? substr($id, strpos($id, '.') + 1) : $id;
        $decodificado = (string) base64_decode(strtr($dentro, '-_', '+/').str_repeat('=', (4 - strlen($dentro) % 4) % 4), false);

        return str_contains(strtoupper($decodificado.' '.$id), 'PDO');
    }

    /**
     * Agenda em lote (1 pedido) porque é o caminho do Easy Ship que devolve
     * a etiqueta pronta (printableDocumentsUrl, PDF). Se a Amazon recusar o
     * lote como operação, agenda pelo createScheduledPackage simples e a
     * etiqueta fica para o Seller Central (avisado no sino).
     *
     * @param  array<string, mixed>  $slot
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function criarPacote(Order $order, string $amazonOrderId, array $slot): array
    {
        $detalhes = [
            'packageTimeSlot' => array_filter([
                'slotId' => $slot['slotId'],
                'startTime' => $slot['startTime'] ?? null,
                'endTime' => $slot['endTime'] ?? null,
                'handoverMethod' => $slot['handoverMethod'] ?? null,
            ]),
            'packageIdentifier' => "KZ-{$order->id}",
        ];

        try {
            $lote = $this->client->post(self::API.'/packages/bulk', [
                'marketplaceId' => config('services.amazon.marketplace_id'),
                'orderScheduleDetailsList' => [['amazonOrderId' => $amazonOrderId, 'packageDetails' => $detalhes]],
                'labelFormat' => 'PDF',
            ]);
        } catch (AmazonException $exception) {
            Log::channel('amazon')->warning('amazon.dba.lote_recusado', ['order_id' => $order->id, 'erro' => $exception->getMessage()]);

            // Pode ter agendado mesmo com erro na resposta.
            if ($pacote = $this->pacoteNaAmazon($amazonOrderId)) {
                return [$pacote, null];
            }

            $pacote = $this->client->post(self::API.'/package', [
                'amazonOrderId' => $amazonOrderId,
                'marketplaceId' => config('services.amazon.marketplace_id'),
                'packageDetails' => $detalhes,
            ]);

            return [$pacote, null];
        }

        $recusado = collect($lote['rejectedOrders'] ?? [])->firstWhere('amazonOrderId', $amazonOrderId);

        if ($recusado) {
            $erro = $recusado['error']['message'] ?? 'motivo não informado';

            throw new RuntimeException("Pedido #{$order->id}: a Amazon recusou o agendamento no DBA ({$erro}).");
        }

        $pacote = collect($lote['scheduledPackages'] ?? [])
            ->first(fn ($p) => ($p['scheduledPackageId']['amazonOrderId'] ?? null) === $amazonOrderId)
            ?? ($lote['scheduledPackages'][0] ?? null);

        if (! $pacote) {
            $pacote = $this->pacoteNaAmazon($amazonOrderId);
        }

        if (! $pacote) {
            throw new RuntimeException("Pedido #{$order->id}: a Amazon não devolveu o pacote agendado no DBA.");
        }

        return [$pacote, $lote['printableDocumentsUrl'] ?? null];
    }

    /** O link do lote é um ZIP com a(s) etiqueta(s) em PDF; aceita PDF direto também. */
    private function baixarEtiqueta(string $url): ?string
    {
        $resposta = Http::timeout(60)->get($url);

        if ($resposta->failed()) {
            throw new RuntimeException("Falha ao baixar os documentos do DBA (HTTP {$resposta->status()}).");
        }

        return $this->extrairPdf($resposta->body());
    }

    /** PDF direto ou ZIP com PDF(s) dentro (etiqueta primeiro). */
    private function extrairPdf(string $conteudo): ?string
    {
        if (str_starts_with($conteudo, '%PDF-')) {
            return $conteudo;
        }

        if (! str_starts_with($conteudo, "PK\x03\x04")) {
            throw new RuntimeException('Documento do DBA em formato desconhecido.');
        }

        $arquivo = tempnam(sys_get_temp_dir(), 'dba');
        file_put_contents($arquivo, $conteudo);

        try {
            $zip = new ZipArchive;

            if ($zip->open($arquivo) !== true) {
                throw new RuntimeException('ZIP do DBA não abriu.');
            }

            $pdfs = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $dados = $zip->getFromIndex($i);

                if (is_string($dados) && str_starts_with($dados, '%PDF-')) {
                    $pdfs[(string) $zip->getNameIndex($i)] = $dados;
                }
            }

            $zip->close();
        } finally {
            @unlink($arquivo);
        }

        if ($pdfs === []) {
            return null;
        }

        // Etiqueta primeiro se o nome disser; senão o primeiro PDF.
        foreach ($pdfs as $nome => $dados) {
            if (preg_match('/label|etiqueta/i', $nome)) {
                return $dados;
            }
        }

        return reset($pdfs);
    }

    /**
     * Um passo do feed da etiqueta (porte do script que tirou a etiqueta do
     * pedido #4104 à mão, 12/11/2026): envia o feed → lê o resultado do feed
     * (DocumentReportReferenceID) → lê o relatório → baixa o documento com
     * Restricted Data Token. O estado fica no cache entre as chamadas.
     */
    private function avancarFeedDaEtiqueta(Order $order): ?string
    {
        $chave = "amazon:dba:feed-etiqueta:{$order->id}";
        $estado = (array) Cache::get($chave, []);
        $amazonOrderId = (string) $order->external_order_id;

        if (! empty($estado['desde']) && now()->timestamp - (int) $estado['desde'] > self::FEED_PRAZO_MINUTOS * 60) {
            Log::channel('amazon')->warning('amazon.dba.feed_etiqueta_parado', ['order_id' => $order->id, 'estado' => $estado]);
            $estado = [];
            Cache::forget($chave);
        }

        if (empty($estado['feedId'])) {
            $tentativas = (int) Cache::get("{$chave}:tentativas", 0);

            if ($tentativas >= self::FEED_MAX_TENTATIVAS) {
                $this->avisarUmaVez($order, "a Amazon não entregou a etiqueta do pacote DBA nem pelo feed de documentos ({$tentativas} tentativas). Imprima a etiqueta no Seller Central (pedido {$amazonOrderId}).");

                return null;
            }

            Cache::put("{$chave}:tentativas", $tentativas + 1, now()->addDay());
            Cache::put($chave, ['feedId' => $this->enviarFeedDaEtiqueta($amazonOrderId), 'desde' => now()->timestamp], now()->addDay());

            return null;
        }

        if (empty($estado['reportId'])) {
            $feed = $this->client->get('/feeds/2021-06-30/feeds/'.$estado['feedId']);
            $situacao = (string) ($feed['processingStatus'] ?? '');

            if (in_array($situacao, ['IN_QUEUE', 'IN_PROGRESS'], true)) {
                return null;
            }

            $relatorio = null;

            if ($situacao === 'DONE' && ! empty($feed['resultFeedDocumentId'])) {
                $resultado = $this->baixarDocumentoDaAmazon('/feeds/2021-06-30/documents/'.$feed['resultFeedDocumentId'], restrito: false);

                if (preg_match('/<DocumentReportReferenceID>\s*(\d+)\s*</', $resultado, $m)) {
                    $relatorio = $m[1];
                } else {
                    Log::channel('amazon')->warning('amazon.dba.feed_etiqueta_sem_relatorio', ['order_id' => $order->id, 'resultado' => mb_substr((string) preg_replace('/\s+/', ' ', $resultado), 0, 600)]);
                }
            }

            if ($relatorio === null) {
                Log::channel('amazon')->warning('amazon.dba.feed_etiqueta_recusado', ['order_id' => $order->id, 'situacao' => $situacao]);
                Cache::forget($chave);

                return null;
            }

            $estado['reportId'] = $relatorio;
            Cache::put($chave, $estado, now()->addDay());
        }

        $relatorio = $this->client->get('/reports/2021-06-30/reports/'.$estado['reportId']);
        $situacao = (string) ($relatorio['processingStatus'] ?? '');

        if (in_array($situacao, ['IN_QUEUE', 'IN_PROGRESS'], true)) {
            return null;
        }

        if ($situacao !== 'DONE' || empty($relatorio['reportDocumentId'])) {
            Log::channel('amazon')->warning('amazon.dba.relatorio_etiqueta_recusado', ['order_id' => $order->id, 'situacao' => $situacao]);
            Cache::forget($chave);

            return null;
        }

        $pdf = $this->extrairPdf($this->baixarDocumentoDaAmazon('/reports/2021-06-30/documents/'.$relatorio['reportDocumentId'], restrito: true));
        Cache::forget($chave);

        if ($pdf === null) {
            Log::channel('amazon')->warning('amazon.dba.documento_sem_pdf', ['order_id' => $order->id]);

            return null;
        }

        Storage::disk('local')->put(self::caminhoEtiqueta($order), $pdf);
        Cache::forget("{$chave}:tentativas");

        $this->timeline->record($order, OrderFulfillmentEvent::STEP_LABEL_GENERATED, OrderFulfillmentEvent::STATUS_SUCCESS, 'Etiqueta do DBA baixada da Amazon pelo feed de documentos do Easy Ship.');

        return $pdf;
    }

    private function enviarFeedDaEtiqueta(string $amazonOrderId): string
    {
        $vendedor = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_AMAZON)->value('seller_id')
            ?: config('services.amazon.bootstrap_seller_id');

        if (! $vendedor) {
            throw new RuntimeException('Conta da Amazon sem seller_id: o feed da etiqueta precisa do MerchantIdentifier.');
        }

        $e = fn (string $valor) => htmlspecialchars($valor, ENT_XML1);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<AmazonEnvelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="amzn-envelope.xsd">'
            .'<Header><DocumentVersion>1.01</DocumentVersion><MerchantIdentifier>'.$e((string) $vendedor).'</MerchantIdentifier></Header>'
            .'<MessageType>EasyShipDocument</MessageType>'
            .'<Message><MessageID>1</MessageID><EasyShipDocument><AmazonOrderID>'.$e($amazonOrderId).'</AmazonOrderID><DocumentType>ShippingLabel</DocumentType></EasyShipDocument></Message>'
            .'</AmazonEnvelope>';
        $tipo = 'text/xml; charset=UTF-8';

        $documento = $this->client->createFeedDocument($tipo);
        $this->client->uploadFeedDocument($documento['url'], $xml, $tipo);

        $feed = $this->client->post('/feeds/2021-06-30/feeds', [
            'feedType' => self::FEED_TIPO,
            'marketplaceIds' => [config('services.amazon.marketplace_id')],
            'inputFeedDocumentId' => $documento['feedDocumentId'],
        ]);

        if (empty($feed['feedId'])) {
            throw new RuntimeException('A Amazon não devolveu o feedId do pedido da etiqueta.');
        }

        return (string) $feed['feedId'];
    }

    /** Documento de feed/relatório (URL pré-assinada; GZIP quando a Amazon diz). */
    private function baixarDocumentoDaAmazon(string $caminho, bool $restrito): string
    {
        $documento = null;

        if ($restrito) {
            try {
                $documento = $this->client->restrictedGet($caminho, []);
            } catch (AmazonException $exception) {
                Log::channel('amazon')->info('amazon.dba.documento_sem_rdt', ['erro' => $exception->getMessage()]);
            }
        }

        $documento ??= $this->client->get($caminho);

        if (empty($documento['url'])) {
            throw new RuntimeException('A Amazon não devolveu a URL do documento.');
        }

        $resposta = Http::timeout(60)->get($documento['url']);

        if ($resposta->failed()) {
            throw new RuntimeException("Falha ao baixar o documento da Amazon (HTTP {$resposta->status()}).");
        }

        $conteudo = $resposta->body();

        if (strtoupper((string) ($documento['compressionAlgorithm'] ?? '')) === 'GZIP') {
            $conteudo = (string) gzdecode($conteudo);
        }

        return $conteudo;
    }

    /** @param  array<string, mixed>  $slot */
    private function janela(array $slot): ?string
    {
        if (empty($slot['startTime'])) {
            return null;
        }

        $inicio = Carbon::parse($slot['startTime'])->setTimezone(config('app.timezone'));
        $fim = empty($slot['endTime']) ? null : Carbon::parse($slot['endTime'])->setTimezone(config('app.timezone'));

        return $inicio->format('d/m H:i').($fim ? ' às '.$fim->format('H:i') : '');
    }

    private function avisarUmaVez(Order $order, string $motivo): void
    {
        if (! Cache::add("amazon:dba:aviso-etiqueta:{$order->id}", true, now()->addDays(3))) {
            return;
        }

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new LabelUnavailableNotification($order, $motivo));
        }
    }
}
