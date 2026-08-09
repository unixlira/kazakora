<?php

namespace App\Services\NFe;

use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\Invoice;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Pedido explícito 2026-08-09: "buscar todas [as notas] no SEFAZ" — usa o
 * webservice NFeDistribuicaoDFe (Tools::sefazDistDFe) pra trazer o histórico
 * completo de notas emitidas sob o CNPJ direto da SEFAZ, não só o que este
 * sistema já tem local. Isso existe porque já se sabe que há números
 * "perdidos" (ver comentário em config/nfe.php sobre os números 1/2 de
 * julho/2026, de sessão/teste anterior que nunca deixou Invoice local).
 *
 * Consulta é incremental por NSU (Número Sequencial Único) — Company::
 * nfe_ultimo_nsu guarda o cursor, cada chamada só pede o que é novo desde a
 * última sincronização. A resposta traz "resNFe" (resumo — chave, valor,
 * destinatário, situação), não o XML completo original; por isso uma nota
 * importada por aqui vira uma Invoice com metadados mas SEM xml_path/
 * danfe_path (não há como reconstruir os dois sem re-consultar a nota por
 * chave, fora do escopo desta sincronização).
 */
class NFeDistribuicaoService
{
    public function __construct(
        private readonly NFeCertificateService $certificateService,
        private readonly NFeToolsFactory $toolsFactory,
    ) {
    }

    /**
     * @return array{imported: int, updated: int, ultimo_nsu: int, mensagem: string}
     */
    public function sync(): array
    {
        $company = Company::query()->firstOrFail();
        $certificate = $this->certificateService->load();
        $tools = $this->toolsFactory->make($certificate);

        $imported = 0;
        $updated = 0;
        $ultNSU = (int) $company->nfe_ultimo_nsu;

        // A SEFAZ devolve no máximo ~50 documentos por chamada — repete até
        // não sobrar mais nada novo (ultNSU alcança maxNSU) ou até um limite
        // de segurança pra nunca ficar num loop indefinido numa conta com
        // muito histórico acumulado de uma vez (primeira sincronização).
        for ($page = 0; $page < 200; $page++) {
            $response = $tools->sefazDistDFe($ultNSU);
            $result = new SimpleXMLElement($response);
            $result->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');

            $cStat = (string) ($this->firstXpath($result, '//n:cStat') ?? $this->firstXpath($result, '//cStat') ?? '');

            // 137 = "Nenhum documento localizado" — cursor já está em dia,
            // nada de novo desde a última sincronização. Não é erro.
            if ($cStat === '137') {
                break;
            }

            if ($cStat !== '138') {
                $motivo = (string) ($this->firstXpath($result, '//n:xMotivo') ?? $this->firstXpath($result, '//xMotivo') ?? 'resposta não reconhecida');

                throw new RuntimeException("SEFAZ recusou a consulta de Distribuição DFe: {$cStat} - {$motivo}");
            }

            $novoUltNSU = (int) ($this->firstXpath($result, '//n:ultNSU') ?? $this->firstXpath($result, '//ultNSU') ?? $ultNSU);
            $maxNSU = (int) ($this->firstXpath($result, '//n:maxNSU') ?? $this->firstXpath($result, '//maxNSU') ?? $novoUltNSU);

            $docZips = $result->xpath('//n:docZip') ?: $result->xpath('//docZip');

            foreach ($docZips ?: [] as $docZip) {
                $schema = (string) $docZip->attributes()->schema;
                $conteudo = @gzdecode(base64_decode((string) $docZip));

                if ($conteudo === false) {
                    continue;
                }

                if (str_starts_with($schema, 'resNFe')) {
                    $novo = $this->importResumo($conteudo, $company, (int) $docZip->attributes()->NSU);

                    if ($novo === 'imported') {
                        $imported++;
                    } elseif ($novo === 'updated') {
                        $updated++;
                    }
                }

                // resEvento (cancelamentos/outros eventos) fica pra uma
                // próxima passada — a informação de cancelamento de uma nota
                // que a gente mesmo emitiu já chega pelo cancelamento normal
                // (InvoiceService::cancelInvoice()), então não é bloqueante
                // pro pedido de hoje ("buscar todas as notas").
            }

            $ultNSU = $novoUltNSU;
            $company->update(['nfe_ultimo_nsu' => $ultNSU]);

            if ($ultNSU >= $maxNSU) {
                break;
            }
        }

        return [
            'imported' => $imported,
            'updated' => $updated,
            'ultimo_nsu' => $ultNSU,
            'mensagem' => $imported === 0 && $updated === 0
                ? 'Nenhuma nota nova encontrada na SEFAZ.'
                : "{$imported} nota(s) nova(s) importada(s), {$updated} atualizada(s).",
        ];
    }

    private function firstXpath(SimpleXMLElement $node, string $path): ?string
    {
        $result = $node->xpath($path);

        return $result && isset($result[0]) ? (string) $result[0] : null;
    }

    /**
     * @return 'imported'|'updated'|'skipped'
     */
    private function importResumo(string $xml, Company $company, int $nsu): string
    {
        try {
            $resumo = new SimpleXMLElement($xml);
        } catch (Throwable) {
            return 'skipped';
        }

        $chave = (string) $resumo->chNFe;

        if (strlen($chave) !== 44) {
            return 'skipped';
        }

        $emitenteCnpj = substr($chave, 6, 14);

        // Distribuição DFe também traz notas em que a empresa é
        // DESTINATÁRIA (compra de fornecedor), não só as que ela emitiu —
        // fora do escopo de "notas de venda emitidas" desta tela, ignora.
        if ($emitenteCnpj !== preg_replace('/\D/', '', (string) $company->cnpj)) {
            return 'skipped';
        }

        $status = match ((string) $resumo->cSitNFe) {
            '1' => Invoice::STATUS_AUTHORIZED,
            '2' => Invoice::STATUS_CANCELLED,
            '3' => Invoice::STATUS_DENIED,
            default => Invoice::STATUS_AUTHORIZED,
        };

        $existing = Invoice::query()->where('chave_acesso', $chave)->first();

        if ($existing) {
            // Já existe local (fluxo normal ou sincronização anterior) —
            // só atualiza status se a SEFAZ sabe de algo mais recente (ex.:
            // cancelada por fora deste sistema, nunca aconteceria via
            // cancelInvoice() daqui, mas pode ter sido cancelada
            // manualmente no site da SEFAZ/Sped).
            if ($existing->status !== $status && in_array($status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_DENIED], true)) {
                $existing->update(['status' => $status]);

                return 'updated';
            }

            return 'skipped';
        }

        $documento = (string) ($resumo->CNPJ ?? $resumo->CPF ?? '');

        Invoice::create([
            'order_id' => null,
            'origem' => Invoice::ORIGEM_SEFAZ,
            'nsu' => $nsu,
            'destinatario_nome' => (string) $resumo->xNome ?: null,
            'destinatario_documento' => $documento ?: null,
            'status' => $status,
            'ambiente' => config('nfe.ambiente'),
            'serie' => (int) substr($chave, 22, 3),
            'numero' => (int) substr($chave, 25, 9),
            'valor_total' => (float) $resumo->vNF,
            'chave_acesso' => $chave,
            'protocolo_autorizacao' => (string) $resumo->nProt ?: null,
            'autorizada_em' => (string) $resumo->dhEmi ?: null,
        ]);

        return 'imported';
    }
}
