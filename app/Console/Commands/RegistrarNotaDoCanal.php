<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\HistoricoDeNotas;
use App\Services\NFe\NFeCertificateService;
use App\Services\NFe\NFeDanfeService;
use App\Services\NFe\NFeWebserviceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use NFePHP\NFe\Complements;
use SimpleXMLElement;
use Throwable;

/**
 * Registra no pedido a NF-e que o TikTok emitiu pela própria plataforma
 * (série 6, nosso CNPJ). Pedido do Lira em 2026-11-03: "se foi gerada lá no
 * TikTok, mantém lá... atualiza o backend para enxergar a nota". Caso
 * #3779: Bling 3-655 em dobro com a TikTok 6-568; a 3-655 foi cancelada e o
 * pedido passa a mostrar a 6-568.
 *
 * Confere a chave na SEFAZ antes de gravar (só autorizada entra). A nota
 * cancelada que estava no registro vai antes para o histórico do pedido
 * (HistoricoDeNotas). Pedido com outra nota AUTORIZADA não é mexido:
 * primeiro cancela a do dobro.
 */
class RegistrarNotaDoCanal extends Command
{
    protected $signature = 'nfe:nota-do-canal
        {pedido : ID do pedido no KazaKora}
        {chave : Chave de acesso da NF-e emitida pelo TikTok}
        {--valor= : Valor da nota (quando não houver XML)}
        {--xml= : Caminho do XML da nota baixado do TikTok}
        {--aplicar : Grava; sem isto só mostra o que faria}';

    protected $description = 'Registra no pedido a NF-e emitida pelo TikTok (série 6), conferida na SEFAZ';

    public function handle(NFeCertificateService $certificados, NFeWebserviceService $sefaz, HistoricoDeNotas $historico, OrderFulfillmentTimeline $timeline, NFeDanfeService $danfe): int
    {
        $pedido = Order::find((int) $this->argument('pedido'));
        $chave = preg_replace('/\D/', '', (string) $this->argument('chave'));

        if (! $pedido) {
            return $this->falha('Pedido não encontrado.');
        }

        if ($pedido->origin !== Order::ORIGIN_TIKTOK_SHOP) {
            return $this->falha("Pedido #{$pedido->id} é do canal {$pedido->origin}, não do TikTok.");
        }

        if (strlen($chave) !== 44) {
            return $this->falha('Chave precisa ter 44 dígitos.');
        }

        $cnpj = preg_replace('/\D/', '', (string) Company::query()->value('cnpj'));

        if ($cnpj !== '' && substr($chave, 6, 14) !== $cnpj) {
            return $this->falha('A chave não é do nosso CNPJ.');
        }

        $serie = (int) substr($chave, 22, 3);
        $numero = (int) substr($chave, 25, 9);

        $xml = null;

        if ($caminho = $this->option('xml')) {
            if (! is_file($caminho) || ! str_contains($xml = (string) file_get_contents($caminho), $chave)) {
                return $this->falha('XML não encontrado ou de outra chave.');
            }
        }

        $valor = $this->option('valor') !== null ? (float) str_replace(',', '.', (string) $this->option('valor')) : $this->valorDoXml($xml);

        if ($valor === null) {
            return $this->falha('Informe --valor ou --xml: o valor da nota não sai da consulta da SEFAZ.');
        }

        try {
            $resposta = $sefaz->consultarChave($chave, $certificados->load());
            $retorno = new SimpleXMLElement($resposta);
        } catch (Throwable $e) {
            return $this->falha('Consulta à SEFAZ falhou: '.$e->getMessage());
        }

        $retorno->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $infProt = $retorno->xpath('//n:protNFe/n:infProt')[0] ?? null;
        $cStat = (string) ($retorno->xpath('//n:retConsSitNFe/n:cStat')[0] ?? '');

        if (! in_array($cStat, ['100', '150'], true) || ! $infProt) {
            return $this->falha("SEFAZ não confirma a nota autorizada (cStat {$cStat}).");
        }

        $atual = $pedido->invoice()->first();

        if ($atual && $atual->chave_acesso === $chave) {
            $this->info("Pedido #{$pedido->id} já está com a NF-e {$serie}-{$numero}.");

            return self::SUCCESS;
        }

        if ($atual && ! in_array($atual->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_PENDING, Invoice::STATUS_REJECTED, Invoice::STATUS_ERROR], true)) {
            return $this->falha("Pedido #{$pedido->id} tem a NF-e {$atual->serie}-{$atual->numero} ({$atual->status}). Cancele a nota em dobro antes.");
        }

        $this->line("Pedido #{$pedido->id}: NF-e {$serie}-{$numero} do TikTok, R$ ".number_format($valor, 2, ',', '.').", protocolo {$infProt->nProt}"
            .($atual ? "; a {$atual->serie}-{$atual->numero} ({$atual->status}) vai para o histórico" : ''));

        if (! $this->option('aplicar')) {
            $this->warn('Simulação: nada gravado (use --aplicar).');

            return self::SUCCESS;
        }

        if ($atual?->status === Invoice::STATUS_CANCELLED) {
            $historico->registrarCancelada($atual);
        }

        $nota = $atual ?? new Invoice(['order_id' => $pedido->id]);
        $nota->forceFill([
            'origem' => Invoice::ORIGEM_PEDIDO,
            'emitida_por' => Invoice::EMITIDA_TIKTOK,
            'status' => Invoice::STATUS_AUTHORIZED,
            'ambiente' => Invoice::AMBIENTE_PRODUCAO,
            'serie' => $serie,
            'numero' => $numero,
            'valor_total' => $valor,
            'chave_acesso' => $chave,
            'protocolo_autorizacao' => (string) $infProt->nProt,
            'autorizada_em' => rescue(fn () => Carbon::parse((string) $infProt->dhRecbto)->timezone(config('app.timezone')), now(), false),
            'motivo_rejeicao' => null,
            'xml_path' => null,
            'danfe_path' => null,
            'protocolo_cancelamento' => null,
            'motivo_cancelamento' => null,
            'cancelada_em' => null,
            'cancelamento_extemporaneo' => false,
            'xml_cancelamento_path' => null,
        ])->save();

        if ($xml !== null) {
            if (! str_contains($xml, '<protNFe')) {
                $xml = rescue(fn () => Complements::toAuthorize($xml, $resposta), $xml, false);
            }

            $nota->xml_path = "invoices/{$pedido->id}/nfe-{$chave}.xml";
            Storage::disk('local')->put($nota->xml_path, $xml);

            if ($pdf = rescue(fn () => $danfe->generate($xml, $pedido), null, false)) {
                $nota->danfe_path = "invoices/{$pedido->id}/danfe-{$chave}.pdf";
                Storage::disk('local')->put($nota->danfe_path, $pdf);
            }

            $nota->save();
        }

        $timeline->record($pedido, OrderFulfillmentEvent::STEP_INVOICE_ISSUED, OrderFulfillmentEvent::STATUS_SUCCESS,
            "NF-e {$serie}-{$numero} emitida pela plataforma do TikTok registrada no pedido (chave {$chave}, protocolo {$infProt->nProt}, conferida na SEFAZ).",
            ['emitida_por' => Invoice::EMITIDA_TIKTOK, 'chave' => $chave]);

        $this->info('Gravado.');

        return self::SUCCESS;
    }

    private function valorDoXml(?string $xml): ?float
    {
        if ($xml === null || ! preg_match('/<vNF>([\d.]+)<\/vNF>/', $xml, $m)) {
            return null;
        }

        return (float) $m[1];
    }

    private function falha(string $mensagem): int
    {
        $this->error($mensagem);

        return self::FAILURE;
    }
}
