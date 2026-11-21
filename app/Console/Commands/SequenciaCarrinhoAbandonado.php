<?php

namespace App\Console\Commands;

use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Cart\Support\Sequencia\DisparadorSequencia;
use App\Modules\Cart\Support\Sequencia\ImportadorSequencia;
use App\Modules\Cart\Support\Sequencia\ParadasSequencia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sequência de carrinho abandonado (pedido do Lira 21/11/2026), de 10 em 10
 * minutos: (a) traz os carrinhos abertos do checkout e da sessão para
 * adm_abandoned_carts, (b) para quem comprou, pediu para parar, saiu das
 * promoções, não tem contato ou acabou a sequência e (c) manda os disparos
 * que estão na hora (D+1, D+3, D+5, D+7, D+20, D+30, das 9h às 20h), no
 * máximo --limite por rodada. Sem desconto nenhum.
 *
 * --simular faz tudo dentro de uma transação desfeita no fim: lista o que
 * mandaria, sem mandar nada nem gravar nada.
 */
class SequenciaCarrinhoAbandonado extends Command
{
    protected $signature = 'loja:carrinho-abandonado-sequencia
        {--simular : Só lista o que mandaria, sem mandar nem gravar}
        {--limite= : Máximo de carrinhos com disparo nesta rodada (padrão: config loja.carrinho_sequencia_limite)}';

    protected $description = 'Importa os carrinhos abandonados e manda os disparos da sequência (WhatsApp e e-mail, sem desconto)';

    public function handle(ImportadorSequencia $importador, ParadasSequencia $paradas, DisparadorSequencia $disparador): int
    {
        $simular = (bool) $this->option('simular');
        $limite = max(1, (int) ($this->option('limite') ?: config('loja.carrinho_sequencia_limite', 50)));

        if ($simular) {
            DB::beginTransaction();
        }

        try {
            $importados = $importador->importar();
            $this->info("Importados: {$importados['novos']} novos, {$importados['atualizados']} atualizados.");

            $parados = $this->pararQuemDeve($paradas);
            $this->info('Parados nesta rodada: '.($parados === [] ? '0' : collect($parados)->map(fn ($n, $m) => "{$m}={$n}")->implode(', ')));

            $vez = Registro::query()
                ->where('parado', false)
                ->whereNotNull('proximo_disparo_em')
                ->where('proximo_disparo_em', '<=', now())
                ->orderBy('proximo_disparo_em')
                ->limit($limite)
                ->get();

            $simular ? $this->listar($vez, $disparador) : $this->mandar($vez, $paradas, $disparador);
        } finally {
            if ($simular) {
                DB::rollBack();
                $this->comment('Simulação: nada foi enviado nem gravado.');
            }
        }

        return self::SUCCESS;
    }

    /** @return array<string, int> motivo => quantos */
    private function pararQuemDeve(ParadasSequencia $paradas): array
    {
        $contagem = [];

        Registro::query()->where('parado', false)->chunkById(200, function ($registros) use ($paradas, &$contagem) {
            foreach ($registros as $registro) {
                if ($motivo = $paradas->aplicar($registro)) {
                    $contagem[$motivo] = ($contagem[$motivo] ?? 0) + 1;
                }
            }
        });

        return $contagem;
    }

    private function listar($vez, DisparadorSequencia $disparador): void
    {
        if ($vez->isEmpty()) {
            $this->line('Nenhum disparo na hora.');

            return;
        }

        $this->table(
            ['id', 'loja', 'cliente', 'disparo', 'canais', 'produto', 'link'],
            $vez->map(function (Registro $r) use ($disparador) {
                $p = $disparador->previa($r);

                return [
                    $r->id,
                    $r->site,
                    trim(($r->nome ?: 'sem nome').' '.($r->telefone ? '(whats)' : '').($r->email ? ' (e-mail)' : '')),
                    "{$p['numero']}º (D+{$p['dia']})",
                    $p['canais'] === [] ? 'nenhum (template vazio, espera)' : implode(' + ', $p['canais']),
                    $p['produto'],
                    $p['link'],
                ];
            })->all(),
        );
        $this->info("Mandaria {$vez->count()} disparo(s).");
    }

    private function mandar($vez, ParadasSequencia $paradas, DisparadorSequencia $disparador): void
    {
        $resultado = ['enviado' => 0, 'sem_canal' => 0, 'falhou' => 0];

        foreach ($vez as $registro) {
            // Pode ter comprado entre a conferência e agora.
            if ($paradas->aplicar($registro)) {
                continue;
            }
            $resultado[$disparador->disparar($registro)]++;
        }

        $this->info("Disparos: {$resultado['enviado']} enviados, {$resultado['falhou']} com falha, {$resultado['sem_canal']} sem canal (esperando template).");
    }
}
