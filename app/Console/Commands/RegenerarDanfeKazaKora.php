<?php

namespace App\Console\Commands;

use App\Modules\Fiscal\Models\Invoice;
use App\Services\NFe\NFeDanfeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Troca o PDF guardado das notas de uma série pelo DANFE da KazaKora, gerado
 * do XML autorizado que já está no disco.
 *
 * Motivo (22/10/2026): as notas da série 3 (emitidas pelo Bling) guardavam o
 * PDF do Bling, com outro layout e sem o logo; o Lira pediu o mesmo DANFE da
 * série 2. O importador já gera o nosso para as notas novas
 * (BlingInvoiceImporter::storeDocuments); este comando acerta as antigas.
 *
 * O PDF anterior não é apagado: vai para `lixeira/danfe-bling/` no mesmo
 * disco. Nota sem XML no disco é só listada (não há de onde gerar).
 */
class RegenerarDanfeKazaKora extends Command
{
    protected $signature = 'nfe:danfe-kazakora
        {--serie=3 : Série das notas}
        {--pedido=* : Só estes pedidos (id local)}
        {--seco : Só conta o que seria refeito, sem gravar}';

    protected $description = 'Refaz o PDF das notas de uma série com o DANFE da KazaKora (o anterior vai para a lixeira)';

    public function handle(NFeDanfeService $danfe): int
    {
        $disco = Storage::disk('local');
        $seco = (bool) $this->option('seco');
        $refeitas = 0;
        $semXml = [];
        $falhas = [];

        Invoice::query()
            ->where('serie', (int) $this->option('serie'))
            ->where('status', Invoice::STATUS_AUTHORIZED)
            ->when($this->option('pedido'), fn ($query, $pedidos) => $query->whereIn('order_id', $pedidos))
            ->orderBy('id')
            ->each(function (Invoice $invoice) use ($disco, $danfe, $seco, &$refeitas, &$semXml, &$falhas) {
                if (! $invoice->xml_path || ! $disco->exists($invoice->xml_path)) {
                    $semXml[] = $invoice;

                    return;
                }

                if ($seco) {
                    $refeitas++;

                    return;
                }

                try {
                    $pdf = $danfe->generate($disco->get($invoice->xml_path));
                } catch (Throwable $exception) {
                    $falhas[] = "nota {$invoice->numero} (pedido {$invoice->order_id}): {$exception->getMessage()}";

                    return;
                }

                $nome = $invoice->chave_acesso ?: 'bling-'.$invoice->id;
                $novo = "invoices/{$invoice->order_id}/danfe-{$nome}.pdf";

                // Rodar de novo não pode trocar o PDF do Bling da lixeira pelo
                // nosso: se a lixeira já tem a cópia, o arquivo atual é nosso.
                $lixeira = 'lixeira/danfe-bling/'.$invoice->danfe_path;

                if ($invoice->danfe_path && $disco->exists($invoice->danfe_path) && ! $disco->exists($lixeira)) {
                    $disco->move($invoice->danfe_path, $lixeira);
                }

                $disco->put($novo, $pdf);
                $invoice->update(['danfe_path' => $novo]);
                $refeitas++;
            });

        $this->info(($seco ? 'Seriam refeitas' : 'Refeitas').": {$refeitas}");

        if ($semXml !== []) {
            $this->warn('Sem XML no disco ('.count($semXml).'), PDF mantido: '.collect($semXml)->map(fn (Invoice $i) => "{$i->numero} (pedido {$i->order_id}".($i->danfe_path ? ', tem PDF' : '').')')->implode(', '));
        }

        foreach ($falhas as $falha) {
            $this->error("Falhou: {$falha}");
        }

        return $falhas === [] ? self::SUCCESS : self::FAILURE;
    }
}
