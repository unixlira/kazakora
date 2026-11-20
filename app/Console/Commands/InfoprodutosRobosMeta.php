<?php

namespace App\Console\Commands;

use App\Http\Middleware\TrackSiteVisit;
use App\Modules\Infoprodutos\Models\InfoprodutoEvento;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Marca as sessões antigas do robô de revisão de anúncios da Meta
 * (2026-11-20). A coleta não grava IP, então o passado sai do log de acesso
 * do nginx: cada POST /api-infoprodutos/evento aceito (204) tem o IP e o
 * segundo, e a coleta grava os eventos com esse mesmo segundo. Segundo em
 * que SÓ a rede da Meta (TrackSiteVisit::REDES_DA_META) mandou eventos =
 * as sessões gravadas nele são do robô, e a sessão inteira é marcada.
 * Segundo com gente e robô juntos fica de fora (não dá para separar).
 *
 * O log do nginx só é legível pelo root: copie as linhas antes, ex.
 *   zgrep -h 'POST /api-infoprodutos/evento' /var/log/nginx/access.log* > /tmp/coleta.log
 *   php artisan infoprodutos:robos-meta --log=/tmp/coleta.log
 */
class InfoprodutosRobosMeta extends Command
{
    protected $signature = 'infoprodutos:robos-meta {--log=* : linhas do access.log do nginx (aceita .gz)} {--simular : só conta, não grava}';

    protected $description = 'Marca as sessões antigas do robô de revisão de anúncios da Meta pelo log do nginx';

    public function handle(): int
    {
        $segundos = [];
        foreach ((array) $this->option('log') as $arquivo) {
            $f = @fopen((str_ends_with($arquivo, '.gz') ? 'compress.zlib://' : '').$arquivo, 'r');
            if (! $f) {
                $this->error("Não consegui ler {$arquivo}.");

                return self::FAILURE;
            }
            while (($linha = fgets($f)) !== false) {
                if (! preg_match('#^(\S+) \S+ \S+ \[([^\]]+)\] "POST /api-infoprodutos/evento[^"]*" 204 #', $linha, $m)) {
                    continue;
                }
                $quando = Carbon::createFromFormat('d/M/Y:H:i:s O', $m[2])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
                $meta = IpUtils::checkIp($m[1], TrackSiteVisit::REDES_DA_META);
                $segundos[$quando][$meta ? 'meta' : 'gente'] = ($segundos[$quando][$meta ? 'meta' : 'gente'] ?? 0) + 1;
            }
            fclose($f);
        }

        $soMeta = array_keys(array_filter($segundos, fn ($s) => ($s['meta'] ?? 0) > 0 && ($s['gente'] ?? 0) === 0));
        $misturados = count(array_filter($segundos, fn ($s) => ($s['meta'] ?? 0) > 0 && ($s['gente'] ?? 0) > 0));

        $sessoes = collect($soMeta)->chunk(500)->flatMap(fn ($lote) => InfoprodutoEvento::query()
            ->whereIn('created_at', $lote->all())->whereNull('robo')->distinct()->pluck('sessao'))->unique()->values();

        $porProduto = $sessoes->isEmpty() ? collect() : InfoprodutoEvento::query()->where('tipo', 'pageview')
            ->whereIn('sessao', $sessoes->all())->selectRaw('produto, DATE(created_at) as dia, COUNT(DISTINCT sessao) as n')
            ->groupBy('produto', 'dia')->orderBy('dia')->get();
        foreach ($porProduto as $l) {
            $this->line("{$l->dia} {$l->produto}: {$l->n} sessões");
        }

        $linhas = 0;
        if (! $this->option('simular')) {
            foreach ($sessoes->chunk(500) as $lote) {
                $linhas += InfoprodutoEvento::query()->whereIn('sessao', $lote->all())->whereNull('robo')
                    ->update(['robo' => InfoprodutoEvento::ROBO_META]);
            }
        }

        $this->info(sprintf('%d segundos só da Meta (%d misturados com gente, fora); %d sessões%s, %d linhas.',
            count($soMeta), $misturados, $sessoes->count(), $this->option('simular') ? ' (simulação)' : ' marcadas', $linhas));

        return self::SUCCESS;
    }
}
