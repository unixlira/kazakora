<?php

namespace App\Modules\Fiscal\Services;

use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Services\NFe\NFeCertificateService;
use App\Services\NFe\NFeDanfeService;
use App\Services\NFe\NFeWebserviceService;
use App\Services\NFe\NFeXmlBuilderService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SimpleXMLElement;

/**
 * Orquestra a emissão da NF-e de um pedido pago (Etapas 1-4 do plano) e o
 * cancelamento (Etapa 5).
 *
 * issue() agora É chamado de dentro de um job de fila (GenerateInvoiceJob) e
 * portanto LANÇA exceção em falha técnica (conexão, SOAP, certificado,
 * resposta ilegível da SEFAZ) — é assim que o retry/backoff nativo do
 * Laravel funciona. Uma resposta definitiva da SEFAZ (autorizada, rejeitada
 * ou denegada) nunca lança: já é um resultado terminal, tentar de novo com
 * os mesmos dados não muda o resultado. Falta de certificado configurado
 * também não lança (não é algo que um retry de minutos resolve).
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

    public function issue(Order $order): Invoice
    {
        $order->loadMissing('invoice');

        // Mercado Livre emite a própria NF-e pro vendedor (confirmado ao
        // vivo 2026-08-02 — chave de acesso real, mesmo CNPJ/CPF do
        // pedido, DANFE com o texto/layout deles, não o nosso). Tentar
        // emitir aqui também duplicaria a nota fiscal da mesma venda —
        // problema real de conformidade, não só redundância. Se outro
        // canal também tiver emissão própria confirmada, adicionar aqui.
        if ($order->origin === Order::ORIGIN_MERCADO_LIVRE) {
            return $order->invoice ?? Invoice::create([
                'order_id' => $order->id,
                'status' => Invoice::STATUS_EXTERNAL,
                'ambiente' => config('nfe.ambiente'),
                // Série 0 nunca é usada pra número real de NF-e (config
                // nfe.serie começa em 1) — reservada só pra essas linhas
                // "não emitimos, o canal emitiu". numero=order_id garante
                // unicidade sem precisar reservar sequência real (índice
                // único é (serie, numero, ambiente)).
                'serie' => 0,
                'numero' => $order->id,
                'valor_total' => $order->total,
            ]);
        }

        if ($order->invoice && in_array($order->invoice->status, [
            Invoice::STATUS_AUTHORIZED,
            Invoice::STATUS_REJECTED,
            Invoice::STATUS_DENIED,
        ], true)) {
            // Já existe uma resposta definitiva da SEFAZ (ou já foi
            // autorizada) — idempotente, não há nada novo a fazer.
            return $order->invoice;
        }

        $invoice = $order->invoice ?? $this->createPendingInvoice($order);

        if (! $this->certificateService->isConfigured()) {
            Log::channel('stripe')->info('nfe.issue.blocked_no_certificate', ['order_id' => $order->id, 'invoice_id' => $invoice->id]);

            return $invoice;
        }

        $this->signAndSend($invoice);

        return $invoice->fresh();
    }

    /**
     * Cria a linha pendente (numero/chave reservados) e guarda o XML ainda
     * não assinado. Feito uma única vez por pedido — retries reaproveitam a
     * MESMA linha (mesmo numero), nunca reservam um novo numero de NF-e.
     */
    private function createPendingInvoice(Order $order): Invoice
    {
        try {
            return DB::transaction(function () use ($order) {
                $localMax = Invoice::query()
                    ->where('serie', config('nfe.serie'))
                    ->where('ambiente', config('nfe.ambiente'))
                    ->lockForUpdate()
                    ->max('numero') ?? 0;

                // max(local, numero_inicial) — ver comentário em config/nfe.php.
                $numero = max($localMax, (int) config('nfe.numero_inicial')) + 1;

                ['xml' => $xml, 'chave' => $chave] = $this->xmlBuilder->build($order, $numero);

                $invoice = Invoice::create([
                    'order_id' => $order->id,
                    'status' => Invoice::STATUS_PENDING,
                    'ambiente' => config('nfe.ambiente'),
                    'serie' => config('nfe.serie'),
                    'numero' => $numero,
                    // NFeXmlBuilderService::build() usa $order->total como vNF
                    // (valor total da NF-e) — guardamos o mesmo valor aqui pra
                    // poder somar/consultar sem precisar reabrir o XML.
                    'valor_total' => $order->total,
                    'chave_acesso' => $chave,
                ]);

                $xmlPath = "invoices/{$order->id}/nfe-{$chave}.xml";
                Storage::disk('local')->put($xmlPath, $xml);
                $invoice->update(['xml_path' => $xmlPath]);

                return $invoice;
            });
        } catch (QueryException $exception) {
            // Corrida rara: duas execuções concorrentes pro mesmo pedido
            // (ex: retry manual cruzando com o automático). unique(order_id)
            // barra a segunda no banco — em vez de quebrar, reaproveita a
            // linha que a primeira já criou.
            $existing = $order->fresh()->invoice;

            if (! $existing) {
                throw $exception;
            }

            return $existing;
        }
    }

    private function signAndSend(Invoice $invoice): void
    {
        $xml = Storage::disk('local')->get($invoice->xml_path);
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
            // Resposta sem protocolo reconhecível — pode ser uma falha
            // transitória de comunicação/parsing, vale tentar de novo.
            throw new RuntimeException('Resposta da SEFAZ sem protocolo reconhecível.');
        }

        $cStat = (string) $infProt->cStat;
        $xMotivo = (string) $infProt->xMotivo;

        // 100 = autorizada; 110/301/302 = denegada; qualquer outro = rejeitada
        if ($cStat === '100') {
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
     * prazo de 24h da autorização.
     */
    public function cancel(Order $order, string $motivo): Invoice
    {
        $invoice = $order->invoice;

        if (! $invoice || $invoice->status !== Invoice::STATUS_AUTHORIZED) {
            throw new RuntimeException('Não há uma NF-e autorizada para este pedido.');
        }

        if ($invoice->autorizada_em?->diffInHours(now()) >= 24) {
            throw new RuntimeException('Prazo de 24h para cancelamento da NF-e expirado.');
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
            throw new RuntimeException('SEFAZ não confirmou o cancelamento: '.($infEvento?->xMotivo ?? 'resposta inesperada'));
        }

        return $invoice->fresh();
    }
}
