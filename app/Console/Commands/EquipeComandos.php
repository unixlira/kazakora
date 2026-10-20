<?php

namespace App\Console\Commands;

use App\Modules\Admin\Models\EquipeComando;
use Illuminate\Console\Command;

/**
 * Comandos extras ATIVOS da Equipe (pedido do Lira 2026-10-20): o Homer lê
 * pela SSH e injeta no agente. Sem membro, lista todos os membros.
 */
class EquipeComandos extends Command
{
    protected $signature = 'equipe:comandos {membro? : slug do membro (juliana, paulo, jonathan, giovanna, caue, diego, rafael, manuela)} {--json : saída em JSON}';

    protected $description = 'Lista os comandos extras ativos de cada membro da Equipe';

    public function handle(): int
    {
        $membros = EquipeComando::membros();
        $slug = $this->argument('membro');

        if ($slug !== null && ! isset($membros[$slug])) {
            $this->error("Membro desconhecido: {$slug}. Use: ".implode(', ', array_keys($membros)));

            return self::FAILURE;
        }

        $alvos = $slug !== null ? [$slug] : array_keys($membros);
        $comandos = EquipeComando::query()->ativos()->with('user:id,name')
            ->whereIn('membro', $alvos)->orderBy('id')->get()->groupBy('membro');

        $saida = collect($alvos)->map(fn (string $m) => [
            'membro' => $m,
            'nome' => $membros[$m]['nome'],
            'agente' => $membros[$m]['agente'] ?? null,
            'comandos' => ($comandos[$m] ?? collect())->map(fn (EquipeComando $c) => [
                'id' => $c->id,
                'texto' => $c->texto,
                'autor' => $c->user?->name,
                'criado_em' => $c->created_at?->timezone('America/Sao_Paulo')->toIso8601String(),
            ])->values()->all(),
        ])->values();

        if ($this->option('json')) {
            $this->line(json_encode($slug !== null ? $saida->first() : $saida->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($saida as $item) {
            $this->info("{$item['nome']} ({$item['membro']}): ".count($item['comandos']).' ativo(s)');
            foreach ($item['comandos'] as $c) {
                $this->line("  #{$c['id']} {$c['texto']}");
            }
        }

        return self::SUCCESS;
    }
}
