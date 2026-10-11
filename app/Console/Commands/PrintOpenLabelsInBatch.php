<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Support\BatchLabelPrintService;
use Illuminate\Console\Command;

/**
 * A versão de terminal do botão "Gerar etiquetas em lote" (2026-09-07).
 *
 * Toda a decisão mora no BatchLabelPrintService — aqui é só a casca, pra
 * dar pra disparar o lote por SSH quando o KoraSync não estiver à mão (foi
 * exatamente assim que o primeiro lote saiu, no dia em que o fluxo mudou).
 *
 * NÃO é agendado e não deve ser: imprimir é sempre decisão de gente. Ver
 * routes/console.php — nada de impressão automática mora lá.
 */
class PrintOpenLabelsInBatch extends Command
{
    protected $signature = 'etiquetas:lote {--seco : Só mostra o que sairia, sem mandar nada pra impressora}';

    protected $description = 'Manda pra impressora todas as etiquetas já disponíveis de pedidos da Shopee e do Mercado Livre que ainda faltam separar';

    public function handle(BatchLabelPrintService $service): int
    {
        if ($this->option('seco')) {
            $this->warn('Modo seco: nada foi pra impressora.');
        }

        $resultado = $this->option('seco') ? $service->preview() : $service->run();

        $this->info("Candidatos (pagos, não separados, Shopee/ML): {$resultado['total_candidatos']}");

        $this->line('');
        $this->info('Foram pra impressora: '.count($resultado['enfileiradas']));
        foreach ($resultado['enfileiradas'] as $item) {
            $this->line("  #{$item['order_id']} ({$item['canal']})");
        }

        if ($resultado['ja_impressas']) {
            $this->line('');
            $this->comment('Já tinham sido impressas antes — não saem de novo (use Reimprimir se o papel se perdeu): '.count($resultado['ja_impressas']));
            foreach ($resultado['ja_impressas'] as $item) {
                $this->line("  #{$item['order_id']} ({$item['canal']}) — impressa em ".($item['impressa_em'] ?? 'data não registrada'));
            }
        }

        if ($resultado['sem_etiqueta']) {
            $this->line('');
            $this->comment('O canal ainda não liberou a etiqueta: '.count($resultado['sem_etiqueta']));
            foreach ($resultado['sem_etiqueta'] as $item) {
                $this->line("  #{$item['order_id']} ({$item['canal']})");
            }
        }

        return self::SUCCESS;
    }
}
