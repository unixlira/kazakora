<?php

namespace App\Console\Commands;

use App\Modules\Infoprodutos\Models\InfoprodutoEvento;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Totais diários dos infoprodutos e das páginas de produto medidas
 * (infoproduto_diarios) e limpeza dos eventos brutos com mais de 90 dias
 * (config infoprodutos.retencao_dias). Todo dia de madrugada; refaz os
 * últimos dias (--dias) para pegar evento que chegou atrasado.
 */
class InfoprodutosConsolidar extends Command
{
    protected $signature = 'infoprodutos:consolidar {--dias=3 : dias para trás que são refeitos}';

    protected $description = 'Consolida os eventos dos infoprodutos por dia e apaga os brutos antigos';

    public function handle(): int
    {
        $dias = max(1, (int) $this->option('dias'));
        for ($i = $dias; $i >= 1; $i--) {
            $dia = now()->startOfDay()->subDays($i);
            $n = self::consolidarDia($dia);
            $this->line("{$dia->toDateString()}: {$n} linhas");
        }

        $limite = now()->startOfDay()->subDays((int) config('infoprodutos.retencao_dias', 90));
        $apagados = 0;
        do {
            $lote = InfoprodutoEvento::query()->where('created_at', '<', $limite)->limit(5000)->delete();
            $apagados += $lote;
        } while ($lote > 0);
        $this->info("Eventos brutos apagados (antes de {$limite->toDateString()}): {$apagados}");

        return self::SUCCESS;
    }

    /** Refaz os totais de um dia. Devolve quantas linhas gravou. */
    public static function consolidarDia(Carbon $dia): int
    {
        $de = $dia->copy()->startOfDay();
        $ate = $dia->copy()->endOfDay();

        $pageviews = InfoprodutoEvento::query()->where('tipo', 'pageview')->dePessoas()
            ->whereBetween('created_at', [$de, $ate])->orderBy('id')
            ->get(['sessao', 'produto', 'utm_source', 'utm_campaign', 'utm_content', 'pais'])
            ->unique(fn ($e) => $e->sessao.'|'.$e->produto);

        $eventos = InfoprodutoEvento::query()->whereIn('tipo', ['scroll', 'play', 'video', 'preview', 'buy', 'exit'])
            ->whereBetween('created_at', [$de, $ate->copy()->addHours(6)])
            ->whereIn('sessao', $pageviews->pluck('sessao')->unique()->all() ?: ['-'])
            ->get(['sessao', 'produto', 'tipo', 'valor'])
            ->groupBy(fn ($e) => $e->sessao.'|'.$e->produto);

        $linhas = [];
        foreach ($pageviews as $pv) {
            $evs = $eventos[$pv->sessao.'|'.$pv->produto] ?? collect();
            $chave = implode('|', [$pv->produto, $pv->utm_source ?? '', $pv->utm_campaign ?? '', $pv->utm_content ?? '', $pv->pais ?? '']);
            $l = $linhas[$chave] ?? ['data' => $de->toDateString(), 'produto' => $pv->produto, 'utm_source' => mb_substr((string) $pv->utm_source, 0, 150),
                'utm_campaign' => mb_substr((string) $pv->utm_campaign, 0, 150), 'utm_content' => mb_substr((string) $pv->utm_content, 0, 150), 'pais' => (string) $pv->pais,
                'visitas' => 0, 'rolou_50' => 0, 'play' => 0, 'video_fim' => 0, 'previa' => 0, 'clique_compra' => 0, 'segundos' => 0];
            $l['visitas']++;
            $l['rolou_50'] += (int) ($evs->where('tipo', 'scroll')->max(fn ($e) => (int) $e->valor) >= 50);
            $l['play'] += (int) $evs->contains('tipo', 'play');
            $l['video_fim'] += (int) $evs->contains(fn ($e) => $e->tipo === 'video' && (int) $e->valor === 100);
            $l['previa'] += (int) $evs->contains('tipo', 'preview');
            $l['clique_compra'] += (int) $evs->contains('tipo', 'buy');
            $l['segundos'] += (int) $evs->where('tipo', 'exit')->max(fn ($e) => (int) $e->valor);
            $linhas[$chave] = $l;
        }

        DB::transaction(function () use ($de, $linhas) {
            DB::table('infoproduto_diarios')->where('data', $de->toDateString())->delete();
            $agora = now();
            foreach (array_chunk(array_values($linhas), 500) as $lote) {
                DB::table('infoproduto_diarios')->insert(array_map(fn ($l) => $l + ['created_at' => $agora, 'updated_at' => $agora], $lote));
            }
        });

        return count($linhas);
    }
}
