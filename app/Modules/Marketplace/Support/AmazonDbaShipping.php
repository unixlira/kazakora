<?php

namespace App\Modules\Marketplace\Support;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Notifications\LabelUnavailableNotification;
use App\Services\Amazon\AmazonClient;
use App\Services\Amazon\Exceptions\AmazonException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
 */
class AmazonDbaShipping
{
    /** Gravado em channel_shipments.shipping_method — é por ele que o resto do sistema reconhece o envio DBA. */
    public const METODO = 'Amazon DBA (Ponto de Envio)';

    private const API = '/easyShip/2022-03-23';

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

        [$pacote, $urlDocumentos] = $this->criarPacote($order, $amazonOrderId, $slot);

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

        $this->timeline->record(
            $order,
            OrderFulfillmentEvent::STEP_SHIPPING_CONFIRMED,
            OrderFulfillmentEvent::STATUS_SUCCESS,
            trim("Agendado no DBA da Amazon (Ponto de Envio): rastreio {$rastreio}".($janela ? ", levar {$janela}" : '').'.'),
        );

        if (! $temEtiqueta) {
            $motivo = "pacote DBA {$rastreio} agendado, mas a Amazon não entregou a etiqueta pela API. Imprima a etiqueta no Seller Central (pedido {$order->external_order_id}).";

            $this->timeline->record($order, OrderFulfillmentEvent::STEP_LABEL_GENERATED, OrderFulfillmentEvent::STATUS_FAILED, ucfirst($motivo));
            $this->avisarUmaVez($order, $motivo);
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
     * @param  array{weight_grams: int, height: ?float, width: ?float, length: ?float}  $medidas
     * @return array<string, mixed>
     */
    private function horarioDePontoDeEnvio(Order $order, string $amazonOrderId, array $medidas): array
    {
        $resposta = $this->client->post(self::API.'/timeSlot', [
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

        $slot = collect($resposta['timeSlots'] ?? [])
            ->filter(fn ($slot) => is_array($slot) && ! empty($slot['slotId']) && self::ehPontoDeEnvio($slot))
            ->sortBy(fn ($slot) => (string) ($slot['startTime'] ?? ''))
            ->first();

        if (! $slot) {
            throw new RuntimeException("Pedido #{$order->id}: a Amazon não ofereceu horário de Ponto de Envio (drop-off) no DBA. Coleta em casa não é usada.");
        }

        return $slot;
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

        $conteudo = $resposta->body();

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
