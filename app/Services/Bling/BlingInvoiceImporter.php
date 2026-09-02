<?php

namespace App\Services\Bling;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Models\Invoice;
use App\Services\Bling\Exceptions\BlingException;
use App\Services\NFe\NFeDanfeService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Traz pra dentro do Kazakora a NF-e que o BLING emitiu (pedido explícito
 * 2026-09-02: "o kazakora tem que ter a nota que o bling gerou tbm... deixar
 * disponível pdf e xml").
 *
 * Contexto: desde 02/09 a nota dos pedidos do TikTok Shop é emitida pelo
 * Bling, não por nós (ver services.bling.invoice_issuer_channels e o
 * comentário em GenerateInvoiceJob) — é o único jeito do XML chegar ao
 * TikTok e a etiqueta liberar. Sem este importador, o pedido ficaria sem
 * registro nenhum de nota deste lado: histórico, financeiro e as telas de
 * XML/DANFE apontariam pra nada.
 *
 * O vínculo é o campo `notaFiscal.id` do próprio pedido de venda do Bling
 * (confirmado ao vivo no pedido 26759086886: vem `{"id":0}` enquanto não há
 * nota, e passa a trazer o id quando a nota é gerada). Por isso a
 * sincronização é dirigida pelo PEDIDO, não pelo evento de nota — o webhook
 * `invoice` não diz a qual pedido a nota pertence.
 *
 * Guarda XML e DANFE nos MESMOS caminhos das notas emitidas por nós
 * (invoices/{order}/nfe-{chave}.xml e danfe-{chave}.pdf, disco local), pra
 * as telas e rotas de download existentes funcionarem sem nenhuma exceção
 * pra "nota do Bling". O DANFE é gerado do XML autorizado com o nosso
 * próprio NFeDanfeService — não depende de PDF do Bling.
 */
class BlingInvoiceImporter
{
    /**
     * Situações da NF-e no Bling (a numeração é dele, não da SEFAZ):
     * 1 pendente · 2 cancelada · 3 aguardando recibo · 4 rejeitada
     * 5 autorizada · 6 emitida DANFE · 7 registrada · 8 aguardando
     * protocolo · 9 denegada · 10 consulta situação · 11 bloqueada.
     */
    private const SITUACAO_PARA_STATUS = [
        1 => Invoice::STATUS_PENDING,
        2 => Invoice::STATUS_CANCELLED,
        3 => Invoice::STATUS_SENT,
        4 => Invoice::STATUS_REJECTED,
        5 => Invoice::STATUS_AUTHORIZED,
        6 => Invoice::STATUS_AUTHORIZED,
        7 => Invoice::STATUS_AUTHORIZED,
        8 => Invoice::STATUS_SENT,
        9 => Invoice::STATUS_DENIED,
        10 => Invoice::STATUS_SENT,
        11 => Invoice::STATUS_ERROR,
    ];

    public function __construct(
        private readonly BlingClient $client,
        private readonly BlingOrderService $orders,
        private readonly NFeDanfeService $danfe,
        private readonly OrderFulfillmentTimeline $timeline,
    ) {
    }

    /**
     * Nunca lança: é chamado de dentro do fluxo de webhook/poll, e falha em
     * trazer a nota não pode derrubar a importação do pedido.
     */
    public function syncForOrder(Order $order): ?Invoice
    {
        try {
            return $this->sync($order);
        } catch (Throwable $exception) {
            Log::warning('bling.invoice.sync_failed', [
                'order_id' => $order->id,
                'external_order_id' => $order->external_order_id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function sync(Order $order): ?Invoice
    {
        if (! $order->external_order_id) {
            return null;
        }

        $blingOrder = $this->orders->findByOrderNumber($order->external_order_id);
        $nfeId = (int) ($blingOrder['notaFiscal']['id'] ?? 0);

        // 0 = pedido ainda sem nota no Bling. Não é erro: a emissão lá é
        // assíncrona, e o próximo evento/varredura tenta de novo.
        if ($nfeId <= 0) {
            return null;
        }

        $nota = $this->fetchNota($nfeId);

        if ($nota === null) {
            return null;
        }

        // Nota gerada pelo Bling nasce PENDENTE (situação 1) — ele gera a
        // partir do pedido, mas não envia à SEFAZ. Sem esse envio não há
        // chave de acesso, não há XML, o TikTok não recebe nada e a
        // etiqueta nunca libera. Ver services.bling.auto_send_nfe pro
        // porquê de isso ser uma decisão explícita e não o padrão.
        if ((int) ($nota['situacao'] ?? 0) === 1 && config('services.bling.auto_send_nfe')) {
            $nota = $this->enviarParaSefaz($nfeId) ?? $nota;
        }

        $situacao = (int) ($nota['situacao'] ?? 0);
        $chave = preg_replace('/\D/', '', (string) ($nota['chaveAcesso'] ?? ''));
        $status = self::SITUACAO_PARA_STATUS[$situacao] ?? Invoice::STATUS_PENDING;

        $invoice = Invoice::query()->firstOrNew(['order_id' => $order->id]);

        $invoice->fill([
            'origem' => Invoice::ORIGEM_PEDIDO,
            'status' => $status,
            'ambiente' => Invoice::AMBIENTE_PRODUCAO,
            'serie' => isset($nota['serie']) ? (int) $nota['serie'] : $invoice->serie,
            'numero' => isset($nota['numero']) ? (int) $nota['numero'] : $invoice->numero,
            'valor_total' => $nota['valorNota'] ?? $nota['valor'] ?? $order->total,
            'chave_acesso' => $chave ?: $invoice->chave_acesso,
            'motivo_rejeicao' => $status === Invoice::STATUS_REJECTED ? ($nota['observacoes'] ?? 'Rejeitada no Bling — ver detalhe lá.') : null,
        ]);

        if ($status === Invoice::STATUS_AUTHORIZED && ! $invoice->autorizada_em) {
            $invoice->autorizada_em = now();
        }

        $invoice->save();

        if ($status === Invoice::STATUS_AUTHORIZED) {
            $this->storeDocuments($invoice, $chave, $nota);
        }

        $this->timeline->record(
            $order,
            OrderFulfillmentEvent::STEP_INVOICE_ISSUED,
            $status === Invoice::STATUS_AUTHORIZED ? OrderFulfillmentEvent::STATUS_SUCCESS : OrderFulfillmentEvent::STATUS_FAILED,
            "NF-e emitida pelo Bling (situação {$situacao}), nº {$invoice->numero}".($chave !== '' ? ", chave {$chave}" : ''),
        );

        return $invoice;
    }

    /**
     * POST /nfe/{id}/enviar — o que efetivamente emite na SEFAZ. Devolve a
     * nota reconsultada (já com situação/chave novas) ou null se o envio
     * falhar; nunca lança, porque isto roda dentro do fluxo do webhook.
     *
     * @return array<string, mixed>|null
     */
    private function enviarParaSefaz(int $nfeId): ?array
    {
        try {
            $this->client->post("nfe/{$nfeId}/enviar");
        } catch (BlingException $exception) {
            Log::warning('bling.invoice.send_failed', [
                'nfe_id' => $nfeId,
                'error' => $exception->getMessage(),
                'resposta' => $exception->context ?? null,
            ]);

            return null;
        }

        Log::info('bling.invoice.sent_to_sefaz', ['nfe_id' => $nfeId]);

        return $this->fetchNota($nfeId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchNota(int $nfeId): ?array
    {
        try {
            $resposta = $this->client->get("nfe/{$nfeId}");
        } catch (BlingException $exception) {
            Log::warning('bling.invoice.fetch_failed', ['nfe_id' => $nfeId, 'error' => $exception->getMessage()]);

            return null;
        }

        $nota = $resposta['data'] ?? null;

        // Primeira nota real que passar por aqui vai ao log inteira de
        // propósito: os nomes de campo desta rota não estão na referência
        // pública acessível (o site do Bling só serve a casca da SPA fora
        // do navegador), então o mapeamento acima foi feito pelos nomes
        // documentados no artefato do projeto — vale conferir contra o
        // payload de verdade na primeira vez, em vez de assumir.
        Log::info('bling.invoice.fetched', ['nfe_id' => $nfeId, 'payload' => $nota]);

        return is_array($nota) ? $nota : null;
    }

    /**
     * XML e DANFE nos mesmos caminhos das notas emitidas por nós.
     *
     * Campos confirmados no payload REAL de GET /nfe/{id} (nota 26759176098,
     * 2026-09-02): a própria nota traz `xml`, `linkDanfe` e `linkPDF` —
     * não precisa da rota /nfe/documento/{chave}, que fica só de reserva
     * pro caso de `xml` vir vazio numa nota já autorizada. O PDF vem do
     * `linkPDF` do Bling (é o DANFE oficial daquela nota); gerar o nosso a
     * partir do XML é o plano B, e só funciona se o XML for um nfeProc
     * completo, com protocolo.
     *
     * Best-effort: nota sem arquivo disponível continua registrada
     * (número, série, chave, situação), só sem XML/PDF.
     *
     * @param  array<string, mixed>  $nota
     */
    private function storeDocuments(Invoice $invoice, string $chave, array $nota): void
    {
        $nome = $chave !== '' ? $chave : ('bling-'.($nota['id'] ?? $invoice->id));

        $xml = $this->resolveConteudo($nota['xml'] ?? null) ?? ($chave !== '' ? $this->fetchXml($chave) : null);
        $atualizacao = [];

        if ($xml !== null) {
            $xmlPath = "invoices/{$invoice->order_id}/nfe-{$nome}.xml";
            Storage::disk('local')->put($xmlPath, $xml);
            $atualizacao['xml_path'] = $xmlPath;
        }

        $pdf = $this->baixarLink($nota['linkPDF'] ?? null);

        if ($pdf === null && $xml !== null) {
            try {
                $pdf = $this->danfe->generate($xml);
            } catch (Throwable $exception) {
                Log::warning('bling.invoice.danfe_failed', ['chave' => $chave, 'error' => $exception->getMessage()]);
            }
        }

        if ($pdf !== null) {
            $danfePath = "invoices/{$invoice->order_id}/danfe-{$nome}.pdf";
            Storage::disk('local')->put($danfePath, $pdf);
            $atualizacao['danfe_path'] = $danfePath;
        }

        if ($atualizacao !== []) {
            $invoice->update($atualizacao);
        }
    }

    /**
     * O campo pode vir como o conteúdo em si, em base64, ou como link — o
     * Bling usa link em etiqueta e DANFE, então não dá pra assumir.
     */
    private function resolveConteudo(mixed $valor): ?string
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        if (str_starts_with($valor, 'http')) {
            return $this->baixarLink($valor);
        }

        return $this->decodeIfBase64($valor);
    }

    private function baixarLink(mixed $link): ?string
    {
        if (! is_string($link) || ! str_starts_with($link, 'http')) {
            return null;
        }

        try {
            $resposta = Http::timeout(25)->get($link);
        } catch (Throwable $exception) {
            Log::warning('bling.invoice.download_failed', ['link' => $link, 'error' => $exception->getMessage()]);

            return null;
        }

        return $resposta->successful() ? $resposta->body() : null;
    }

    private function fetchXml(string $chave): ?string
    {
        try {
            $resposta = $this->client->get("nfe/documento/{$chave}");
        } catch (BlingException $exception) {
            Log::warning('bling.invoice.xml_failed', ['chave' => $chave, 'error' => $exception->getMessage()]);

            return null;
        }

        $dados = $resposta['data'] ?? $resposta;

        // A rota pode devolver o XML direto, em base64, ou um link pra
        // baixar (é o padrão do Bling pra etiqueta — ver
        // BlingOrderService::fetchLabel()). Cobre os três sem depender de
        // qual é, porque a referência não está acessível pra confirmar.
        if (is_string($dados)) {
            return $this->decodeIfBase64($dados);
        }

        if (is_array($dados)) {
            if (isset($dados['xml']) && is_string($dados['xml'])) {
                return $this->decodeIfBase64($dados['xml']);
            }

            $link = $dados['link'] ?? $dados['url'] ?? null;

            if (is_string($link) && $link !== '') {
                $arquivo = Http::timeout(20)->get($link);

                return $arquivo->successful() ? $arquivo->body() : null;
            }
        }

        Log::warning('bling.invoice.xml_shape_unknown', ['chave' => $chave, 'resposta' => $resposta]);

        return null;
    }

    private function decodeIfBase64(string $conteudo): string
    {
        if (str_contains($conteudo, '<')) {
            return $conteudo;
        }

        $decodificado = base64_decode($conteudo, true);

        return $decodificado !== false && str_contains($decodificado, '<') ? $decodificado : $conteudo;
    }
}
