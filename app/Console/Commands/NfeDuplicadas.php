<?php

namespace App\Console\Commands;

use App\Modules\Fiscal\Models\Invoice;
use App\Services\NFe\NFeCertificateService;
use App\Services\NFe\NFeWebserviceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Duplicidade de NF-e (2026-10-11): pedido com DUAS notas autorizadas para
 * a mesma venda (a nossa série 2 de setembro e a do Bling série 3 enviada
 * hoje). Confere cada chave direto na SEFAZ e, com --cancelar, cancela a
 * MAIS NOVA — só dentro das 24h (cancelamento normal, sem multa). A mais
 * antiga nunca é tocada. Cancelamento autorizado pelo Lira em 2026-10-11.
 *
 * Fonte: os XMLs autorizados guardados em storage/app/invoices/{pedido}/.
 */
class NfeDuplicadas extends Command
{
    protected $signature = 'nfe:duplicadas
        {pedidos?* : Só estes pedidos (padrão: todos)}
        {--cancelar : Cancela na SEFAZ a nota mais nova de cada duplicidade (dentro de 24h)}';

    protected $description = 'Acha pedidos com duas NF-e autorizadas e cancela a mais nova (dentro de 24h)';

    public function handle(NFeCertificateService $certificados, NFeWebserviceService $sefaz): int
    {
        $disco = Storage::disk('local');
        $certificado = $certificados->load();
        $cancelar = (bool) $this->option('cancelar');
        $filtro = array_map('intval', (array) $this->argument('pedidos'));
        $resumo = ['duplicidades' => 0, 'canceladas' => 0, 'fora_do_prazo' => 0, 'ja_resolvidas' => 0, 'erros' => 0];

        foreach ($disco->directories('invoices') as $pasta) {
            $pedido = (int) basename($pasta);
            if ($filtro && ! in_array($pedido, $filtro, true)) {
                continue;
            }

            $notas = $this->notasAutorizadas($pasta);
            if (count($notas) < 2) {
                continue;
            }

            // Confere na SEFAZ quais continuam autorizadas (cancelada volta 101).
            $vivas = [];
            foreach ($notas as $nota) {
                try {
                    $st = $this->cStats($sefaz->consultarChave($nota['chave'], $certificado));
                } catch (Throwable $e) {
                    $this->error("#{$pedido} consulta {$nota['chave']}: ".mb_substr($e->getMessage(), 0, 120));
                    $resumo['erros']++;

                    continue 2;
                }
                if (in_array('100', $st, true) && ! in_array('101', $st, true) && ! in_array('135', $st, true)) {
                    $vivas[] = $nota;
                }
                usleep(300_000);
            }

            if (count($vivas) < 2) {
                $resumo['ja_resolvidas']++;

                continue;
            }

            $resumo['duplicidades']++;
            usort($vivas, fn ($a, $b) => $a['autorizada_em'] <=> $b['autorizada_em']);
            $manter = $vivas[0];

            foreach (array_slice($vivas, 1) as $nota) {
                $linha = "#{$pedido}: mantém {$manter['chave']} ({$manter['autorizada_em']->format('d/m H:i')}), duplicada {$nota['chave']} ({$nota['autorizada_em']->format('d/m H:i')})";

                if ($nota['autorizada_em']->lt(now()->subHours(24))) {
                    $this->warn("{$linha} — FORA das 24h, não cancela aqui");
                    $resumo['fora_do_prazo']++;

                    continue;
                }

                if (! $cancelar) {
                    $this->line("{$linha} — cancelaria");

                    continue;
                }

                $motivo = "NF-e emitida em duplicidade para a mesma venda; a venda ja possui a NF-e chave {$manter['chave']}.";

                try {
                    ['request' => $req, 'response' => $res] = $sefaz->cancelarComEvento($nota['chave'], $motivo, $nota['protocolo'], $certificado);
                } catch (Throwable $e) {
                    $this->error("{$linha} — erro ao cancelar: ".mb_substr($e->getMessage(), 0, 160));
                    $resumo['erros']++;

                    continue;
                }

                if (! array_intersect(['135', '155'], $this->cStats($res))) {
                    preg_match_all('/<xMotivo>([^<]+)<\/xMotivo>/', $res, $mot);
                    $this->error("{$linha} — SEFAZ não cancelou: ".(end($mot[1]) ?: 'sem motivo'));
                    $resumo['erros']++;

                    continue;
                }

                $evento = rescue(fn () => \NFePHP\NFe\Complements::toAuthorize($req, $res), $res, report: false);
                $disco->put("{$pasta}/cancelamento-{$nota['chave']}.xml", $evento);
                $this->apontarParaANotaQueFicou($pedido, $nota['chave'], $manter, $pasta);

                $this->info("{$linha} — CANCELADA");
                $resumo['canceladas']++;
                usleep(500_000);
            }
        }

        $this->newLine();
        foreach ($resumo as $k => $v) {
            $this->line(str_pad($k, 15).": {$v}");
        }

        return self::SUCCESS;
    }

    /** @return array<string, array{chave: string, protocolo: string, autorizada_em: Carbon}> */
    private function notasAutorizadas(string $pasta): array
    {
        $disco = Storage::disk('local');
        $notas = [];

        foreach ($disco->files($pasta) as $arquivo) {
            if (! preg_match('/nfe-(\d{44})\.xml$/', $arquivo, $m)) {
                continue;
            }
            $xml = $disco->get($arquivo);
            if (! str_contains($xml, '<cStat>100</cStat>') || ! preg_match('/<nProt>(\d+)<\/nProt>/', $xml, $prot)) {
                continue;
            }
            preg_match('/<dhRecbto>([^<]+)<\/dhRecbto>/', $xml, $recebido);
            $notas[$m[1]] = ['chave' => $m[1], 'protocolo' => $prot[1], 'autorizada_em' => Carbon::parse($recebido[1] ?? '1970-01-01')];
        }

        return $notas;
    }

    /** @return list<string> */
    private function cStats(string $xml): array
    {
        preg_match_all('/<cStat>(\d+)<\/cStat>/', $xml, $st);

        return $st[1];
    }

    private function apontarParaANotaQueFicou(int $pedido, string $cancelada, array $manter, string $pasta): void
    {
        $invoice = Invoice::query()->where('order_id', $pedido)->first();

        if ($invoice && $invoice->chave_acesso === $cancelada) {
            $invoice->update([
                'chave_acesso' => $manter['chave'],
                'serie' => (int) substr($manter['chave'], 22, 3),
                'numero' => (int) substr($manter['chave'], 25, 9),
                'protocolo_autorizacao' => $manter['protocolo'],
                'status' => Invoice::STATUS_AUTHORIZED,
                'xml_path' => "{$pasta}/nfe-{$manter['chave']}.xml",
            ]);
        }
    }
}
