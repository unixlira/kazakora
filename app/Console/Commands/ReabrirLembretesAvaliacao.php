<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Support\ModalAvaliacao;
use Illuminate\Console\Command;

/**
 * Modal "Avalie sua compra" (pedido do Lira 2026-10-29): 15 dias depois do
 * "Não avaliar agora" o produto volta para a fila e o modal abre de novo no
 * próximo login. O ciclo repete até o cliente avaliar.
 *
 * O modal já respeita o prazo sozinho (adiado_ate no passado não esconde
 * nada); esta rotina limpa o adiamento vencido e marca reaberto_em, para o
 * registro de quando o lembrete voltou.
 */
class ReabrirLembretesAvaliacao extends Command
{
    protected $signature = 'avaliacoes:reabrir-lembretes {--dry-run : Só conta, sem alterar}';

    protected $description = 'Devolve ao modal de avaliação os produtos com "Não avaliar agora" há '.ModalAvaliacao::DIAS_ADIAMENTO.' dias ou mais';

    public function handle(ModalAvaliacao $modal): int
    {
        if ($this->option('dry-run')) {
            $this->info('Simulação: '.$modal->adiamentosVencidos()->count().' lembretes voltariam ao modal. Nada foi alterado.');

            return self::SUCCESS;
        }

        $this->info($modal->reabrirVencidos().' lembretes voltaram ao modal.');

        return self::SUCCESS;
    }
}
