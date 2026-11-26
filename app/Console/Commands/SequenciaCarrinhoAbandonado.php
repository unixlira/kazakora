<?php

namespace App\Console\Commands;

use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Cart\Support\Sequencia\CalendarioSequencia;
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
 * que estão na hora, das 9h às 20h, no máximo --limite por rodada. Desde
 * 26/11/2026: D+1 WhatsApp, D+3 WhatsApp, D+5 e-mail do Caderno KazaKora e
 * D+7 último WhatsApp; depois acabou (CalendarioSequencia).
 *
 * --simular faz tudo dentro de uma transação desfeita no fim: lista o que
 * mandaria agora, sem mandar nada nem gravar nada. --previa faz o mesmo e
 * lista TUDO o que falta para cada carrinho ativo, com data e canal.
 */
class SequenciaCarrinhoAbandonado extends Command
{
    protected $signature = 'loja:carrinho-abandonado-sequencia
        {--simular : Só lista o que mandaria, sem mandar nem gravar}
        {--previa : Lista tudo o que falta para cada carrinho ativo (data e canal), sem mandar nem gravar}
        {--limite= : Máximo de carrinhos com disparo nesta rodada (padrão: config loja.carrinho_sequencia_limite)}';

    protected $description = 'Importa os carrinhos abandonados e manda os disparos da sequência (WhatsApp e e-mail, sem desconto)';

    public function handle(ImportadorSequencia $importador, ParadasSequencia $paradas, DisparadorSequencia $disparador): int
    {
        $previa = (bool) $this->option('previa');
        $simular = (bool) $this->option('simular') || $previa;
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

            if ($previa) {
                $this->listarPrevia($disparador);
            } elseif ($simular) {
                $this->listar($vez, $disparador);
            } elseif (! config('loja.carrinho_sequencia_ativa')) {
                // Homolog/local: importa e para, mas nunca fala com o cliente.
                $this->warn("Envio desligado (CARRINHO_SEQUENCIA_ATIVA; só liga no kazakora.com): {$vez->count()} disparo(s) na hora, nada foi enviado.");
            } elseif (! CalendarioSequencia::dentroDaJanela(now())) {
                // Carrinho que entrou na fila com horário já vencido não sai fora das 9h–20h (achado 2026-11-23: saiu às 21h30).
                $this->info("Fora do horário de envio (9h às 20h): {$vez->count()} disparo(s) esperam a próxima janela.");
            } else {
                $this->mandar($vez, $paradas, $disparador);
            }
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
                    "{$p['numero']}º (D+{$p['dia']}) {$p['etapa']}",
                    $p['canais'] === [] ? 'nenhum (template vazio, espera)' : implode(' + ', $p['canais']),
                    $p['produto'],
                    $p['link'],
                ];
            })->all(),
        );
        $this->info("Mandaria {$vez->count()} disparo(s).");
    }

    private function listarPrevia(DisparadorSequencia $disparador): void
    {
        $linhas = [];
        Registro::query()->where('parado', false)->orderBy('id')->get()->each(function (Registro $r) use ($disparador, &$linhas) {
            foreach ($disparador->previaCompleta($r) as $p) {
                $linhas[] = [
                    $r->id,
                    trim(($r->nome ?: 'sem nome').' '.($r->telefone ? '(whats)' : '').($r->email ? ' (e-mail)' : '')),
                    "{$p['numero']}º D+{$p['dia']}",
                    $p['quando']->copy()->setTimezone(CalendarioSequencia::FUSO)->format('d/m H:i'),
                    $p['canal'] ?? '-',
                    $p['etapa'].($p['detalhe'] !== '' ? ' · '.$p['detalhe'] : ''),
                ];
            }
        });

        if ($linhas === []) {
            $this->line('Nenhum carrinho ativo na sequência.');

            return;
        }

        $this->table(['id', 'cliente', 'disparo', 'quando', 'canal', 'o que vai'], $linhas);
    }

    private function mandar($vez, ParadasSequencia $paradas, DisparadorSequencia $disparador): void
    {
        $resultado = ['enviado' => 0, 'sem_canal' => 0, 'falhou' => 0, 'adiado' => 0, 'pulado' => 0];

        foreach ($vez as $registro) {
            // Pode ter comprado entre a conferência e agora.
            if ($paradas->aplicar($registro)) {
                continue;
            }
            $resultado[$disparador->disparar($registro)]++;
        }

        $this->info("Disparos: {$resultado['enviado']} enviados, {$resultado['falhou']} com falha, {$resultado['sem_canal']} sem canal (esperando template), {$resultado['adiado']} adiados (já recebeu hoje), {$resultado['pulado']} pulados.");
    }
}
