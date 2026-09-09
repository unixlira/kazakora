<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\ChannelInvoiceSubmission;
use App\Modules\Marketplace\Support\ChannelInvoiceSubmissionService;
use App\Services\NFe\NFeCertificateService;
use App\Services\NFe\NFeDanfeService;
use App\Services\NFe\NFeWebserviceService;
use App\Services\NFe\NFeXmlBuilderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use NFePHP\Common\Certificate;
use NFePHP\NFe\Complements;
use SimpleXMLElement;
use Throwable;

/**
 * Reconstrói o XML (nfeProc) de uma nota JÁ AUTORIZADA cujo arquivo sumiu do
 * storage.
 *
 * Motivo real (2026-09-09): o restore de backup de 2026-09-08 devolveu o
 * banco atualizado mas os arquivos de `storage/app/private/invoices/` no
 * estado do dia 06 — 107 notas autorizadas ficaram sem XML no disco. Sem o
 * arquivo, `MercadoLivreDriver::submitInvoice()` recebia `null` de
 * `Storage::get()` e morria com TypeError, e 34 envios do Mercado Livre
 * ficaram travados em `ready_to_ship/invoice_pending` — etiqueta bloqueada
 * pelo canal esperando uma nota que existe, está autorizada, e só não
 * chegava lá.
 *
 * ## Por que dá pra reconstruir sem falsificar nada
 *
 * A SEFAZ **não devolve ao emitente o XML da própria nota** (DistDFe só
 * entrega o documento completo pro destinatário; pro emitente vem o resumo
 * `resNFe`, ver NFeDistribuicaoService). O que ela devolve na consulta por
 * chave é o protocolo — e dentro dele o **`digVal`**, o hash SHA-1 do
 * `infNFe` que foi autorizado.
 *
 * Então: remonta o XML com o MESMO builder e os MESMOS dados do pedido,
 * recoloca o `cNF`/`cDV` (que estão dentro da própria chave guardada) e
 * procura o `dhEmi` original segundo a segundo em volta do `created_at` da
 * nota. Assina cada candidato e compara o `DigestValue` com o `digVal` da
 * SEFAZ. **Só grava quando bate byte a byte** — digest igual significa que o
 * documento remontado é idêntico ao que foi autorizado, não uma versão nova.
 * Não batendo, o comando pula e reclama: alguma coisa do pedido mudou desde
 * a emissão e aí a reconstrução seria outro documento.
 *
 * `Complements::toAuthorize()` ainda confere a chave e o digest uma segunda
 * vez ao juntar NFe + protNFe, então há duas travas independentes.
 *
 * Consulta é throttled de propósito: a SEFAZ-SP bloqueia o CNPJ por ~1h com
 * cStat 656 ("Consumo Indevido") quando leva requisição demais, e a rodada
 * para sozinha se isso acontecer (ver InvoiceService e o incidente de
 * 2026-09-07).
 */
class RecoverMissingInvoiceXml extends Command
{
    private const CSTAT_CONSUMO_INDEVIDO = '656';

    protected $signature = 'nfe:recuperar-xml
        {--pedido=* : Só estes pedidos (id local)}
        {--canal= : Só pedidos deste canal (ex: mercado_livre)}
        {--limite=40 : Teto de notas por rodada}
        {--janela=300 : Quantos segundos em volta do created_at procurar o dhEmi original}
        {--pausa=1200 : Milissegundos de espera entre consultas à SEFAZ}
        {--enviar : Depois de recuperar, reenvia a nota pro canal (síncrono)}
        {--seco : Só lista o que seria recuperado, sem consultar a SEFAZ nem gravar}';

    protected $description = 'Reconstrói o XML de notas já autorizadas cujo arquivo sumiu do storage, conferindo o digest contra a SEFAZ';

    public function handle(
        NFeCertificateService $certificateService,
        NFeWebserviceService $webservice,
        NFeXmlBuilderService $xmlBuilder,
        NFeDanfeService $danfeService,
    ): int {
        $pedidos = array_filter((array) $this->option('pedido'));

        $candidatas = Invoice::query()
            ->with('order')
            ->where('status', Invoice::STATUS_AUTHORIZED)
            ->whereNotNull('chave_acesso')
            ->when($pedidos, fn ($query) => $query->whereIn('order_id', $pedidos))
            ->when($this->option('canal'), fn ($query, $canal) => $query
                ->whereHas('order', fn ($q) => $q->where('origin', $canal)))
            ->orderBy('id')
            ->get()
            ->filter(fn (Invoice $invoice) => ! $this->xmlExiste($invoice))
            ->values();

        if ($candidatas->isEmpty()) {
            $this->info('Nenhuma nota autorizada com XML faltando.');

            return self::SUCCESS;
        }

        $limite = max(1, (int) $this->option('limite'));
        $total = $candidatas->count();

        if ($total > $limite) {
            $this->warn("{$total} notas sem XML — acima do teto de {$limite} por rodada. Tratando as {$limite} mais antigas.");
            $candidatas = $candidatas->take($limite);
        }

        $this->info("Notas a recuperar: {$candidatas->count()}");

        if ($this->option('seco')) {
            foreach ($candidatas as $invoice) {
                $this->line("  #{$invoice->order_id} — NF {$invoice->serie}/{$invoice->numero} — {$invoice->chave_acesso}");
            }

            $this->comment('Modo seco: nada foi consultado nem gravado.');

            return self::SUCCESS;
        }

        $certificate = $certificateService->load();
        $recuperadas = 0;
        $falhas = 0;

        foreach ($candidatas as $indice => $invoice) {
            $order = $invoice->order;
            $this->line("  #{$invoice->order_id} — NF {$invoice->serie}/{$invoice->numero}");

            if (! $order) {
                $this->warn('     -> nota sem pedido local (veio da sincronização SEFAZ) — não dá pra remontar.');
                $falhas++;

                continue;
            }

            if ($indice > 0) {
                usleep(max(0, (int) $this->option('pausa')) * 1000);
            }

            try {
                $resposta = $webservice->consultarChave($invoice->chave_acesso, $certificate);
            } catch (Throwable $exception) {
                $this->warn('     -> SEFAZ não respondeu: '.substr($exception->getMessage(), 0, 120));
                $falhas++;

                continue;
            }

            $consulta = new SimpleXMLElement($resposta);
            $consulta->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');

            $cStatConsulta = (string) ($consulta->xpath('//n:retConsSitNFe/n:cStat')[0] ?? $consulta->xpath('//cStat')[0] ?? '');

            if ($cStatConsulta === self::CSTAT_CONSUMO_INDEVIDO) {
                $this->warn('     -> SEFAZ bloqueou por consumo indevido (656). Parando a rodada aqui.');

                break;
            }

            $infProt = $consulta->xpath('//n:protNFe/n:infProt')[0] ?? $consulta->xpath('//protNFe/infProt')[0] ?? null;

            if (! $infProt || (string) $infProt->cStat !== '100') {
                $this->warn("     -> SEFAZ não devolveu protocolo de autorização (cStat {$cStatConsulta}).");
                $falhas++;

                continue;
            }

            $digestSefaz = (string) $infProt->digVal;
            $assinado = $this->remontarEAssinar($invoice, $order, $digestSefaz, $certificate, $webservice, $xmlBuilder);

            if (! $assinado) {
                $this->warn('     -> nenhum XML remontado bateu com o digest da SEFAZ — o pedido mudou desde a emissão. Pulando.');
                $falhas++;

                continue;
            }

            $nfeProc = Complements::toAuthorize($assinado, $resposta);
            $xmlPath = $invoice->xml_path ?: "invoices/{$invoice->order_id}/nfe-{$invoice->chave_acesso}.xml";
            Storage::disk('local')->put($xmlPath, $nfeProc);

            $atualizacao = ['xml_path' => $xmlPath];

            try {
                $danfePath = "invoices/{$invoice->order_id}/danfe-{$invoice->chave_acesso}.pdf";
                Storage::disk('local')->put($danfePath, $danfeService->generate($nfeProc));
                $atualizacao['danfe_path'] = $danfePath;
            } catch (Throwable $exception) {
                // DANFE é subproduto: a nota e o XML já estão salvos, e é o
                // XML que destrava o canal. Não desfaz nada por causa do PDF.
                $this->warn('     -> XML recuperado, mas o DANFE falhou: '.substr($exception->getMessage(), 0, 90));
            }

            $invoice->update($atualizacao);
            $recuperadas++;
            $this->info('     -> XML recuperado e conferido contra o protocolo da SEFAZ.');

            Log::channel('stripe')->info('nfe.xml_recuperado', [
                'invoice_id' => $invoice->id,
                'order_id' => $invoice->order_id,
                'chave' => $invoice->chave_acesso,
                'protocolo' => (string) $infProt->nProt,
            ]);

            if ($this->option('enviar')) {
                $this->enviarAoCanal($order);
            }
        }

        $this->info(PHP_EOL."Concluído: {$recuperadas} recuperada(s), {$falhas} sem recuperar.");

        return self::SUCCESS;
    }

    private function xmlExiste(Invoice $invoice): bool
    {
        return (bool) $invoice->xml_path && Storage::disk('local')->exists($invoice->xml_path);
    }

    /**
     * Remonta o XML com os dados do pedido, recoloca o cNF/cDV da chave
     * original e varre o dhEmi segundo a segundo até o digest bater com o
     * que a SEFAZ autorizou. Devolve o XML assinado ou null.
     */
    private function remontarEAssinar(
        Invoice $invoice,
        Order $order,
        string $digestSefaz,
        Certificate $certificate,
        NFeWebserviceService $webservice,
        NFeXmlBuilderService $xmlBuilder,
    ): ?string {
        try {
            ['xml' => $xml] = $xmlBuilder->build($order, (int) $invoice->numero);
        } catch (Throwable $exception) {
            $this->warn('     -> não deu pra remontar o XML: '.substr($exception->getMessage(), 0, 120));

            return null;
        }

        $chave = $invoice->chave_acesso;

        // cNF (código numérico aleatório) e cDV (dígito verificador) são
        // sorteados pela sped-nfe a cada build — mas os dois estão dentro da
        // chave que ficou guardada, então voltam de lá em vez de virarem
        // outro documento.
        $xml = preg_replace('#<cNF>\d+</cNF>#', '<cNF>'.substr($chave, 35, 8).'</cNF>', $xml, 1);
        $xml = preg_replace('#<cDV>\d+</cDV>#', '<cDV>'.substr($chave, 43, 1).'</cDV>', $xml, 1);
        $xml = preg_replace('#Id="NFe\d{44}"#', 'Id="NFe'.$chave.'"', $xml, 1);

        // O dhEmi é o único campo que não sobrou em lugar nenhum: a chave só
        // guarda AAMM. No caminho normal ele cai no mesmo segundo do
        // created_at da nota (o build acontece dentro da transação que cria a
        // linha). Mas nota que foi RENUMERADA (rejeição 539, ver
        // InvoiceService::reserveNewNumber()) teve o XML remontado na hora da
        // renumeração, dias depois do created_at — pedido #1480, criado em
        // 05/09 e autorizado em 07/09. Por isso a busca tenta os dois
        // âncoras: quando o XML é o da primeira montagem, bate no created_at;
        // quando é o da remontagem, bate perto da autorização.
        $janela = max(0, (int) $this->option('janela'));
        $ancoras = collect([$invoice->created_at, $invoice->autorizada_em])
            ->filter()
            ->map(fn ($data) => Carbon::parse($data, config('app.timezone')))
            ->unique(fn (Carbon $data) => $data->timestamp);

        foreach ($ancoras as $base) {
            foreach ($this->offsets($janela) as $offset) {
                $candidato = preg_replace(
                    '#<dhEmi>[^<]+</dhEmi>#',
                    '<dhEmi>'.$base->copy()->addSeconds($offset)->format('Y-m-d\TH:i:sP').'</dhEmi>',
                    $xml,
                    1,
                );

                try {
                    $assinado = $webservice->sign($candidato, $certificate);
                } catch (Throwable $exception) {
                    $this->warn('     -> assinatura falhou: '.substr($exception->getMessage(), 0, 120));

                    return null;
                }

                if (preg_match('#<DigestValue>([^<]+)</DigestValue>#', $assinado, $match) && $match[1] === $digestSefaz) {
                    return $assinado;
                }
            }
        }

        return null;
    }

    /**
     * 0, -1, +1, -2, +2... — o acerto quase sempre está no próprio segundo do
     * created_at, então não faz sentido varrer a janela inteira em ordem.
     *
     * @return \Generator<int, int>
     */
    private function offsets(int $janela): \Generator
    {
        yield 0;

        for ($passo = 1; $passo <= $janela; $passo++) {
            yield -$passo;
            yield $passo;
        }
    }

    /**
     * Vai direto no serviço, não pelo SubmitInvoiceToChannelJob: o job só
     * "falha" quando dá erro técnico — recusa do canal ele registra e engole
     * (de propósito, ver ChannelInvoiceSubmissionService), e aqui o que
     * interessa é justamente ver a resposta do canal na hora. Quem dispara o
     * ConfirmChannelShippingJob (que é o que faz a etiqueta aparecer) é o
     * próprio serviço quando o envio é aceito.
     */
    private function enviarAoCanal(Order $order): void
    {
        try {
            $submissao = app(ChannelInvoiceSubmissionService::class)->submit($order->fresh());
        } catch (Throwable $exception) {
            $this->warn('     -> falha técnica ao enviar pro canal: '.substr($exception->getMessage(), 0, 140));

            return;
        }

        if ($submissao->status === ChannelInvoiceSubmission::STATUS_ERROR) {
            $this->warn('     -> canal recusou a nota: '.substr((string) $submissao->error_message, 0, 140));

            return;
        }

        $this->info("     -> nota enviada pro canal ({$submissao->status}).");
    }
}
