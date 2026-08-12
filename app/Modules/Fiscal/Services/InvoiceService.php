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

        // Até 2026-08-12, Mercado Livre era tratado como "emite a própria
        // NF-e pro vendedor" (confirmado ao vivo em 2026-08-02 — chave de
        // acesso real, mesmo CNPJ/CPF do pedido, DANFE deles) e Kazakora
        // pulava a emissão própria pra não duplicar. Mudança explícita do
        // usuário 2026-08-12: emitir por aqui também pro Mercado Livre,
        // mesmo esquema já usado pra Shopee — ver Invoice::STATUS_EXTERNAL
        // pro histórico de pedidos antigos que ainda usam esse status.

        if ($order->invoice && in_array($order->invoice->status, [
            Invoice::STATUS_AUTHORIZED,
            Invoice::STATUS_DENIED,
        ], true)) {
            // Só AUTHORIZED e DENIED são resposta definitiva de verdade.
            // AUTHORIZED: já emitida, reemitir duplicaria a nota.
            // DENIED (cStat 110/301/302 — normalmente irregularidade de
            // CNPJ/IE do emissor): SEFAZ queima esse número, reenviar o
            // mesmo dado nunca muda o resultado — precisa de intervenção
            // manual (regularizar cadastro), não de retry automático.
            //
            // BUG REAL 2026-08-10 (pedido #215): REJECTED estava nesse
            // mesmo grupo até aqui — mas rejeição (qualquer outro cStat,
            // ex: 778 "NCM inexistente") é um erro de DADO, corrigível: a
            // SEFAZ nunca chegou a registrar essa chave, o número
            // reservado nunca foi consumido de verdade. Tratar como
            // terminal fazia o retry que já existe em
            // ProductFiscalController::retryStuckInvoices() (corrigir o
            // cadastro do produto e redisparar) nunca ter efeito nenhum —
            // ficava rejeitada pra sempre mesmo depois do dado corrigido.
            return $order->invoice;
        }

        // Pedido antigo que ficou marcado como STATUS_EXTERNAL (Mercado
        // Livre, antes da mudança 2026-08-12 acima) nunca teve XML/numero
        // real reservado — trata como se não existisse nenhuma nota ainda,
        // mas reaproveitando a MESMA linha (order_id é unique) em vez de
        // tentar criar uma nova, que violaria a constraint.
        if ($order->invoice && $order->invoice->status === Invoice::STATUS_EXTERNAL) {
            $invoice = $this->convertExternalToPending($order->invoice, $order);
        } else {
            $invoice = $order->invoice ?? $this->createPendingInvoice($order);
        }

        // Achado real 2026-08-08 (pedido #188): a nota ficou pendente com
        // um XML montado a partir de dado fiscal incompleto (retorno de um
        // canal sem CST de PIS/COFINS preenchido); consertar o cadastro do
        // produto e mandar tentar de novo (ProductFiscalController) não
        // adiantava nada — createPendingInvoice() só roda a primeira vez
        // por pedido (de propósito, pra nunca pular número de NF-e), então
        // toda retentativa seguinte reaproveitava o MESMO XML antigo, já
        // gravado em disco, sem nunca reconstruir com o dado corrigido.
        // Enquanto ainda está pending (nunca chegou a ser assinada/enviada
        // — status muda pra 'signed' assim que signAndSend() começa) OU já
        // foi rejeitada (SEFAZ nunca registrou essa chave de verdade, ver
        // comentário acima), reconstruir com o mesmo número já reservado é
        // seguro: a chave pode mudar (tem componente aleatório), mas como
        // a SEFAZ nunca autorizou a chave antiga, não sobra nada pra
        // invalidar.
        if (in_array($invoice->status, [Invoice::STATUS_PENDING, Invoice::STATUS_REJECTED], true)) {
            $invoice = $this->rebuildPendingInvoice($invoice, $order);
        }

        if (! $this->certificateService->isConfigured()) {
            Log::channel('stripe')->info('nfe.issue.blocked_no_certificate', ['order_id' => $order->id, 'invoice_id' => $invoice->id]);

            return $invoice;
        }

        $this->signAndSend($invoice);

        return $invoice->fresh();
    }

    /**
     * Reconstrói o XML de uma nota que reservou número mas nunca chegou a
     * ser assinada/enviada (ver comentário em issue()) — mesmo número,
     * possivelmente chave nova (tem componente aleatório no XML da
     * biblioteca), arquivo antigo sobrescrito.
     */
    private function rebuildPendingInvoice(Invoice $invoice, Order $order): Invoice
    {
        ['xml' => $xml, 'chave' => $chave] = $this->xmlBuilder->build($order, $invoice->numero);

        if ($invoice->xml_path && $invoice->chave_acesso !== $chave) {
            Storage::disk('local')->delete($invoice->xml_path);
        }

        $xmlPath = "invoices/{$order->id}/nfe-{$chave}.xml";
        Storage::disk('local')->put($xmlPath, $xml);
        $invoice->update(['chave_acesso' => $chave, 'xml_path' => $xmlPath]);

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

    /**
     * Converte uma linha antiga marcada STATUS_EXTERNAL (serie=0,
     * numero=order_id — nunca era uma NF-e real, ver comentário em
     * issue()) numa nota pendente de verdade: reserva um numero real na
     * série configurada e monta o XML, igual createPendingInvoice(), mas
     * fazendo UPDATE na mesma linha em vez de criar outra (order_id é
     * unique).
     */
    private function convertExternalToPending(Invoice $invoice, Order $order): Invoice
    {
        return DB::transaction(function () use ($invoice, $order) {
            $localMax = Invoice::query()
                ->where('serie', config('nfe.serie'))
                ->where('ambiente', config('nfe.ambiente'))
                ->lockForUpdate()
                ->max('numero') ?? 0;

            $numero = max($localMax, (int) config('nfe.numero_inicial')) + 1;

            ['xml' => $xml, 'chave' => $chave] = $this->xmlBuilder->build($order, $numero);

            $xmlPath = "invoices/{$order->id}/nfe-{$chave}.xml";
            Storage::disk('local')->put($xmlPath, $xml);

            $invoice->update([
                'status' => Invoice::STATUS_PENDING,
                'ambiente' => config('nfe.ambiente'),
                'serie' => config('nfe.serie'),
                'numero' => $numero,
                'valor_total' => $order->total,
                'chave_acesso' => $chave,
                'xml_path' => $xmlPath,
            ]);

            return $invoice->fresh();
        });
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
            // Achado real 2026-08-07: o XML gravado depois de autorizada
            // era o mesmo signedXml de antes (só o NFe assinado, sem o
            // protocolo de autorização) — a Shopee rejeitava o upload
            // ("Please upload a valid Invoice XML file.") porque isso não é
            // o documento fiscal completo. O padrão (e o que qualquer
            // consumidor externo espera) é o "nfeProc": NFe + protNFe da
            // SEFAZ combinados num XML só — Complements::toAuthorize() é o
            // helper da própria sped-nfe pra montar isso, não precisa
            // construir na mão.
            $nfeProcXml = \NFePHP\NFe\Complements::toAuthorize($signedXml, $response);

            $invoice->update([
                'status' => Invoice::STATUS_AUTHORIZED,
                'protocolo_autorizacao' => (string) $infProt->nProt,
                'autorizada_em' => now(),
                // Limpa o motivo de uma rejeição anterior, se essa nota já
                // tinha sido tentada e falhado antes de agora autorizar —
                // mesma classe de bug (mensagem de erro velha sobrevivendo
                // a um sucesso posterior) já achada e corrigida em
                // ChannelShippingService::confirm() 2026-08-08.
                'motivo_rejeicao' => null,
            ]);
            Storage::disk('local')->put($invoice->xml_path, $nfeProcXml);

            $danfePath = "invoices/{$invoice->order_id}/danfe-{$invoice->chave_acesso}.pdf";
            Storage::disk('local')->put($danfePath, $this->danfeService->generate($nfeProcXml));
            $invoice->update(['danfe_path' => $danfePath]);
        } elseif (in_array($cStat, ['110', '301', '302'], true)) {
            $invoice->update(['status' => Invoice::STATUS_DENIED, 'motivo_rejeicao' => "{$cStat} - {$xMotivo}"]);
        } else {
            $invoice->update(['status' => Invoice::STATUS_REJECTED, 'motivo_rejeicao' => "{$cStat} - {$xMotivo}"]);
        }
    }

    /**
     * Etapa 5, pedido de um Order do sistema. Mantido com essa assinatura
     * (não recebe Invoice direto) pra não mexer nos dois chamadores já
     * existentes/testados em produção (InvoiceController, OrderController)
     * — só delega pro core em cancelInvoice() logo abaixo, que também serve
     * nota órfã trazida da sincronização SEFAZ (sem Order, ver
     * NFeDistribuicaoService/InvoiceController::cancelStandalone()).
     */
    public function cancel(Order $order, string $motivo): Invoice
    {
        $order->loadMissing('invoice');

        if (! $order->invoice) {
            throw new RuntimeException('Não há uma NF-e autorizada para este pedido.');
        }

        return $this->cancelInvoice($order->invoice, $motivo);
    }

    /**
     * Core do cancelamento (Etapa 5) — só cancela se a nota estiver
     * autorizada e ainda dentro do prazo de 24h da autorização. Funciona
     * tanto pra Invoice ligada a um Order (fluxo normal) quanto pra Invoice
     * órfã (origem='sefaz', sem order_id — trazida pela sincronização de
     * Distribuição DFe), já que nada aqui depende de Order.
     */
    public function cancelInvoice(Invoice $invoice, string $motivo): Invoice
    {
        if ($invoice->status !== Invoice::STATUS_AUTHORIZED) {
            throw new RuntimeException('Não há uma NF-e autorizada para cancelar.');
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
