<?php

namespace App\Console\Commands;

use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Analytics\Support\VisitaIgnorada;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Limpa o histórico de visitas (pedido do Lira em 26/11/2026: "limpa isso"):
 * marca site_visits.ignorada nas visitas antigas de robôs e do dono/equipe,
 * pelos mesmos critérios da gravação (VisitaIgnorada). Nada é apagado: a
 * linha só sai da contagem. Antes de marcar, grava em
 * storage/app/private/visitas-limpar/ a lista id => motivo, para desfazer
 * com --desfazer=<arquivo>.
 *
 * No histórico a regra olha o que foi gravado: o navegador só existe nas
 * visitas com OK de cookies, então o robô sem OK sai pela rede (Meta,
 * Google, nossos servidores) ou pelo volume (mais de 300 páginas num dia).
 * Casa da equipe: a mesma rede (/64 do IPv6 ou IPv4) de uma visita da
 * equipe no mesmo dia, no dia anterior ou no seguinte.
 *
 *   php artisan visitas:limpar --dry-run   # só conta, por motivo
 *   php artisan visitas:limpar             # marca
 */
class LimparVisitas extends Command
{
    protected $signature = 'visitas:limpar
        {--dry-run : Só mostra quantas linhas sairiam da contagem, por motivo}
        {--dias=30 : Período recente mostrado ao lado do total}
        {--desfazer= : Arquivo JSON de uma limpeza anterior: tira a marca daquelas linhas}';

    protected $description = 'Tira da contagem as visitas antigas de robôs e do dono/equipe (marca site_visits.ignorada; não apaga)';

    private const LOTE = 2000;

    public function handle(): int
    {
        if ($arquivo = $this->option('desfazer')) {
            return $this->desfazer((string) $arquivo);
        }

        $dryRun = (bool) $this->option('dry-run');
        $recente = now()->subDays(max(1, (int) $this->option('dias')))->startOfDay();

        [$motivos, $linhas] = $this->classificar();

        $base = fn () => SiteVisit::query()->withoutGlobalScope(SiteVisit::SO_QUE_CONTA);
        $total = $base()->count();
        $totalRecente = $base()->where('created_at', '>=', $recente)->count();
        $jaMarcadas = $base()->whereNotNull('ignorada')->count();
        $jaMarcadasRecente = $base()->whereNotNull('ignorada')->where('created_at', '>=', $recente)->count();

        $rows = [];
        $sai = $saiRecente = 0;
        foreach (VisitaIgnorada::MOTIVOS as $motivo => $rotulo) {
            $ids = array_keys($motivos, $motivo, true);
            $n = count($ids);
            $nRecente = count(array_filter($ids, fn ($id) => $linhas[$id] >= $recente->timestamp));
            $sai += $n;
            $saiRecente += $nRecente;
            $rows[] = [$rotulo, $n, $nRecente];
        }
        $rows[] = ['Já marcadas antes', $jaMarcadas, $jaMarcadasRecente];
        $rows[] = ['Ficam contando', $total - $jaMarcadas - $sai, $totalRecente - $jaMarcadasRecente - $saiRecente];

        $this->info(($dryRun ? '[simulação] ' : '')."Visitas: {$total} no total, {$totalRecente} desde {$recente->format('d/m/Y')}.");
        $this->table(['Motivo', 'Total', 'Desde '.$recente->format('d/m')], $rows);

        if ($dryRun || $motivos === []) {
            $this->line($dryRun ? 'Nada foi alterado (--dry-run).' : 'Nada para marcar.');

            return self::SUCCESS;
        }

        // Disco local = storage/app/private.
        $relativo = 'visitas-limpar/'.now()->format('Y-m-d_His').'.json';
        Storage::disk('local')->put($relativo, json_encode($motivos));
        $caminho = 'private/'.$relativo;
        $this->line('Lista para desfazer: storage/app/'.$caminho);

        $marcadas = 0;
        foreach (array_keys(VisitaIgnorada::MOTIVOS) as $motivo) {
            foreach (array_chunk(array_keys($motivos, $motivo, true), self::LOTE) as $lote) {
                $marcadas += $base()->whereIn('id', $lote)->whereNull('ignorada')->update(['ignorada' => $motivo]);
            }
        }
        VisitaIgnorada::esquecer();

        $this->info("Marcadas: {$marcadas}. Para desfazer: php artisan visitas:limpar --desfazer=storage/app/{$caminho}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, int>} id => motivo; id => timestamp (de todas as linhas ainda sem marca)
     */
    private function classificar(): array
    {
        $equipe = VisitaIgnorada::usuariosDaEquipe();
        $base = fn () => SiteVisit::query()->withoutGlobalScope(SiteVisit::SO_QUE_CONTA)->toBase()->whereNull('ignorada');

        // Navegadores da equipe: em que alguém da equipe entrou, em qualquer data.
        $navegadores = $equipe === [] ? [] : SiteVisit::query()->withoutGlobalScope(SiteVisit::SO_QUE_CONTA)
            ->whereIn('user_id', $equipe)->whereNotNull('visitor_id')->where('visitor_id', '!=', 'anonimo')
            ->distinct()->pluck('visitor_id')->map(fn ($v) => (string) $v)->flip()->all();

        $linhas = [];
        $redesDaEquipe = [];
        $base()->select(['id', 'site', 'visitor_id', 'user_id', 'ip', 'user_agent', 'created_at', 'ignorada'])
            ->orderBy('id')
            ->chunk(self::LOTE, function ($lote) use (&$linhas, &$redesDaEquipe, $equipe, $navegadores) {
                foreach ($lote as $l) {
                    $dia = substr((string) $l->created_at, 0, 10);
                    $daEquipe = ($l->user_id !== null && in_array((int) $l->user_id, $equipe, true))
                        || ($l->site === SiteVisit::SITE_LOJA && isset($navegadores[(string) $l->visitor_id]));
                    $rede = VisitaIgnorada::rede($l->ip);
                    $linhas[$l->id] = [$l, $dia, $daEquipe, $rede];
                    if ($daEquipe && $rede !== null) {
                        $redesDaEquipe[$rede.'|'.$dia] = true;
                    }
                }
            });

        // Também as visitas da equipe já marcadas (gravadas depois de 26/11).
        SiteVisit::query()->withoutGlobalScope(SiteVisit::SO_QUE_CONTA)->toBase()
            ->where('ignorada', VisitaIgnorada::EQUIPE)->whereNotNull('ip')
            ->selectRaw('DISTINCT ip, DATE(created_at) as dia')->get()
            ->each(function ($l) use (&$redesDaEquipe) {
                if ($rede = VisitaIgnorada::rede($l->ip)) {
                    $redesDaEquipe[$rede.'|'.substr((string) $l->dia, 0, 10)] = true;
                }
            });

        $motivos = [];
        $porVisitanteDia = [];
        foreach ($linhas as $id => [$l, $dia, $daEquipe, $rede]) {
            $motivo = match (true) {
                ($l->user_agent !== null && VisitaIgnorada::navegadorDeRobo($l->user_agent)) || VisitaIgnorada::redeDeRobo($l->ip) => VisitaIgnorada::ROBO,
                $daEquipe => VisitaIgnorada::EQUIPE,
                VisitaIgnorada::ipDaLista($l->ip) => VisitaIgnorada::IP_IGNORADO,
                $rede !== null && $this->casaNoDia($redesDaEquipe, $rede, $dia) => VisitaIgnorada::CASA_EQUIPE,
                default => null,
            };
            if ($motivo !== null) {
                $motivos[$id] = $motivo;
            } else {
                $porVisitanteDia[$l->site.'|'.$dia.'|'.$l->visitor_id.'|'.$l->ip][] = $id;
            }
        }

        // Robô por volume: o mesmo visitante (cookie + IP) com mais de 300 páginas num dia.
        foreach ($porVisitanteDia as $ids) {
            if (count($ids) > SiteVisit::VISITAS_DIA_ROBO) {
                foreach ($ids as $id) {
                    $motivos[$id] = VisitaIgnorada::ROBO_VOLUME;
                }
            }
        }

        ksort($motivos);

        return [$motivos, array_map(fn ($linha) => Carbon::parse($linha[0]->created_at)->timestamp, $linhas)];
    }

    /** A rede foi da equipe no dia, no anterior ou no seguinte. */
    private function casaNoDia(array $redesDaEquipe, string $rede, string $dia): bool
    {
        $d = Carbon::createFromFormat('!Y-m-d', $dia);

        foreach ([$d, $d->copy()->subDay(), $d->copy()->addDay()] as $data) {
            if (isset($redesDaEquipe[$rede.'|'.$data->format('Y-m-d')])) {
                return true;
            }
        }

        return false;
    }

    private function desfazer(string $arquivo): int
    {
        $caminho = str_starts_with($arquivo, '/') ? $arquivo : base_path($arquivo);
        if (! is_file($caminho)) {
            $this->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }
        $motivos = json_decode((string) file_get_contents($caminho), true);
        if (! is_array($motivos)) {
            $this->error('Arquivo inválido.');

            return self::FAILURE;
        }

        $voltaram = 0;
        foreach (array_chunk(array_map('intval', array_keys($motivos)), self::LOTE) as $lote) {
            $voltaram += SiteVisit::query()->withoutGlobalScope(SiteVisit::SO_QUE_CONTA)->whereIn('id', $lote)->update(['ignorada' => null]);
        }
        VisitaIgnorada::esquecer();
        $this->info("Voltaram a contar: {$voltaram}.");

        return self::SUCCESS;
    }
}
