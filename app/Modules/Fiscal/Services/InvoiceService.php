<?php

namespace App\Modules\Fiscal\Services;

use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Services\NFe\NFeCertificateService;
use App\Services\NFe\NFeDanfeService;
use App\Services\NFe\NFeWebserviceService;
use App\Services\NFe\NFeXmlBuilderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SimpleXMLElement;
use Throwable;

/**
 * Orquestra a emissão da NF-e de um pedido pago (Etapas 1-4 do plano) e o
 * cancelamento (Etapa 5). Nunca deve travar/interromper a confirmação do
 * pagamento em si — se algo falhar aqui, registra e segue (o pedido já está
 * pago de verdade; a nota pode ser emitida manualmente depois).
 */
class InvoiceService
{
    public function __construct(
        private readonly NFeXmlBuilderService $xmlBuilder,
        private readonly NFeCertificateService $certificateService,
        private readonly NFeWebserviceService $webservice,
        private readonly NFeDanfeService $danfeService,
    ) {
    }

    public function issue(Order $order): ?Invoice
    {
        if ($order->invoice) {
            return $order->invoice;
        }

        try {
            return DB::transaction(function () use ($order) {
                $numero = (Invoice::query()
                    ->where('serie', config('nfe.serie'))
                    ->where('ambiente', config('nfe.ambiente'))
                    ->lockForUpdate()
                    ->max('numero') ?? 0) + 1;

                ['xml' => $xml, 'chave' => $chave] = $this->xmlBuilder->build($order, $numero);

                $invoice = Invoice::create([
                    'order_id' => $order->id,
                    'status' => Invoice::STATUS_PENDING,
                    'ambiente' => config('nfe.ambiente'),
                    'serie' => config('nfe.serie'),
                    'numero' => $numero,
                    'chave_acesso' => $chave,
                ]);

                $xmlPath = "invoices/{$order->id}/nfe-{$chave}.xml";
                Storage::disk('local')->put($xmlPath, $xml);
                $invoice->update(['xml_path' => $xmlPath]);

                if (! $this->certificateService->isConfigured()) {
                    Log::channel('stripe')->info('nfe.issue.blocked_no_certificate', ['order_id' => $order->id, 'invoice_id' => $invoice->id]);

                    return $invoice;
                }

                $this->signAndSend($invoice, $xml);

                return $invoice->fresh();
            });
        } catch (Throwable $exception) {
            Log::error('nfe.issue.failed', ['order_id' => $order->id, 'message' => $exception->getMessage()]);

            return $order->invoice;
        }
    }

    private function signAndSend(Invoice $invoice, string $xml): void
    {
        $certificate = $this->certificateService->load();
        $signedXml = $this->webservice->sign($xml, $certificate);

        $invoice->update(['status' => Invoice::STATUS_SIGNED]);
        Storage::disk('local')->put($invoice->xml_path, $signedXml);

        $response = $this->webservice->enviarESincronizar($signedXml, $invoice->id, $certificate);
        $invoice->update(['status' => Invoice::STATUS_SENT]);

        $result = new SimpleXMLElement($response);
        $result->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $protNFe = $result->xpath('//n:protNFe/n:infProt') ?: $result->xpath('//protNFe/infProt');
        $infProt = $protNFe[0] ?? null;

        if (! $infProt) {
            $invoice->update(['status' => Invoice::STATUS_ERROR, 'motivo_rejeicao' => 'Resposta da SEFAZ sem protocolo reconhecível.']);

            return;
        }

        $cStat = (string) $infProt->cStat;
        $xMotivo = (string) $infProt->xMotivo;

        // 100 = autorizada; 110/301/302 = denegada; qualquer outro = rejeitada
        if ($cStat === '100') {
            $authorizedXml = str_replace('</NFe>', '', $xml)
                ."<protNFe versao=\"4.00\">{$infProt->asXML()}</protNFe>";
            // nfeProc real: sped-nfe expõe helper próprio pra isso, mantido simples aqui.
            $invoice->update([
                'status' => Invoice::STATUS_AUTHORIZED,
                'protocolo_autorizacao' => (string) $infProt->nProt,
                'autorizada_em' => now(),
            ]);
            Storage::disk('local')->put($invoice->xml_path, $signedXml);

            $danfePath = "invoices/{$invoice->order_id}/danfe-{$invoice->chave_acesso}.pdf";
            Storage::disk('local')->put($danfePath, $this->danfeService->generate($signedXml));
            $invoice->update(['danfe_path' => $danfePath]);
        } elseif (in_array($cStat, ['110', '301', '302'], true)) {
            $invoice->update(['status' => Invoice::STATUS_DENIED, 'motivo_rejeicao' => "{$cStat} - {$xMotivo}"]);
        } else {
            $invoice->update(['status' => Invoice::STATUS_REJECTED, 'motivo_rejeicao' => "{$cStat} - {$xMotivo}"]);
        }
    }

    /**
     * Etapa 5. Só cancela se a nota estiver autorizada e ainda dentro do
     * prazo de 24h da autorização (regra pedida explicitamente — não existe
     * essa checagem em nenhum outro lugar do projeto ainda, foi construída
     * aqui pela primeira vez).
     */
    public function cancel(Order $order, string $motivo): Invoice
    {
        $invoice = $order->invoice;

        if (! $invoice || $invoice->status !== Invoice::STATUS_AUTHORIZED) {
            throw new \RuntimeException('Não há uma NF-e autorizada para este pedido.');
        }

        if ($invoice->autorizada_em?->diffInHours(now()) >= 24) {
            throw new \RuntimeException('Prazo de 24h para cancelamento da NF-e expirado.');
        }

        $certificate = $this->certificateService->load();
        $response = $this->webservice->cancelar($invoice->chave_acesso, $motivo, $invoice->protocolo_autorizacao, $certificate);

        $result = new SimpleXMLElement($response);
        $result->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $retEvento = $result->xpath('//n:infEvento') ?: $result->xpath('//infEvento');
        $infEvento = $retEvento[0] ?? null;

        if ($infEvento && (string) $infEvento->cStat === '135') {
            $invoice->update([
                'status' => Invoice::STATUS_CANCELLED,
                'protocolo_cancelamento' => (string) $infEvento->nProt,
                'motivo_cancelamento' => $motivo,
                'cancelada_em' => now(),
            ]);
        } else {
            throw new \RuntimeException('SEFAZ não confirmou o cancelamento: '.($infEvento?->xMotivo ?? 'resposta inesperada'));
        }

        return $invoice->fresh();
    }
}
