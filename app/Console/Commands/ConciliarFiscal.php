<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Fiscal\Services\ConciliacaoFiscalService;
use App\Notifications\FiscalAlertNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Conciliação fiscal das 07:30 e das 19:00. Pedido do Lira em 12/11/2026:
 * "se foi cancelado ou devolvido o sistema tem que fazer automático, com
 * alerta".
 *
 * Sem opção, SÓ LÊ: confere venda sem nota, nota viva de pedido cancelado,
 * devolução parada, numeração e nota sem pedido, e abre/fecha os alertas
 * em Sistema › Alertas (ver ConciliacaoFiscalService).
 *
 * --executar (só na rodada das 07:30) também inutiliza na SEFAZ os números
 * da série sem nota há mais de 3 dias. Só no servidor de produção com
 * nfe.ambiente=producao; fora disso, não envia nada.
 */
class ConciliarFiscal extends Command
{
    protected $signature = 'fiscal:conciliar
        {--executar : Inutiliza na SEFAZ os números sem nota há mais de 3 dias (só produção)}
        {--dias=60 : Janela de pedidos e notas conferidos}
        {--limite=10 : Teto de faixas inutilizadas por rodada}';

    protected $description = 'Confere notas, cancelamentos, devoluções e numeração e alerta o que está fora do lugar (com --executar, inutiliza os buracos da série)';

    public function handle(ConciliacaoFiscalService $conciliacao): int
    {
        $inutilizacoes = [];

        if ($this->option('executar')) {
            if ($conciliacao->inutilizacaoLiberada()) {
                $inutilizacoes = $conciliacao->inutilizarBuracos(max(1, (int) $this->option('limite')));
            } else {
                $this->warn('Inutilização automática só no servidor de produção com nfe.ambiente=producao: nada foi enviado à SEFAZ.');
            }
        }

        // Confere depois de inutilizar: o resumo já sai sem as faixas resolvidas.
        $achados = $conciliacao->conferir(max(1, (int) $this->option('dias')));
        $conciliacao->alertar($achados, $inutilizacoes);

        foreach (ConciliacaoFiscalService::GRUPOS as $grupo => $def) {
            $itens = $achados[$grupo] ?? [];
            $this->line(sprintf('%-22s %d', $grupo, count($itens)));

            foreach (array_slice($itens, 0, 20) as $item) {
                $this->line('    '.$item['texto']);
            }
        }

        foreach ($inutilizacoes as $i) {
            $this->line("inutilização {$i['faixa']}: {$i['resultado']} ({$i['detalhe']})");
        }

        $feitas = array_values(array_filter($inutilizacoes, fn ($i) => $i['resultado'] === 'inutilizada'));

        if ($feitas !== []) {
            $texto = count($feitas).' faixa(s) da série inutilizada(s) sozinha(s) na SEFAZ: '
                .implode(', ', array_map(fn ($i) => "{$i['faixa']} ({$i['detalhe']})", $feitas)).'.';
            $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

            if ($admins->isNotEmpty()) {
                rescue(fn () => Notification::send($admins, new FiscalAlertNotification($texto, '/admin/notas-fiscais/fechamento')), report: false);
            }
        }

        return self::SUCCESS;
    }
}
