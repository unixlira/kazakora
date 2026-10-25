<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Analytics\Models\SiteVisit;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Funil da tela de finalização (pedido do Lira 2026-10-23), por visitante,
 * separado por aparelho: abriram, viram o formulário, focaram o 1º campo,
 * preencheram cada campo, CEP respondido, clicaram Finalizar, pedido criado.
 * Mais a mediana de tempo ativo e de rolagem de quem saiu sem pedido.
 *
 * Fora da conta, como no painel (SiteVisit::soDeFora): o visitante que
 * apareceu logado como equipe (User::STAFF_ROLES) no período e o que passou
 * de 300 eventos num dia (robô ou aba presa). Robô declarado nem é gravado.
 */
class FunilDoCheckout extends Command
{
    protected $signature = 'checkout:funil {--dias=7 : Quantos dias para trás (contando hoje)}';

    protected $description = 'Funil do checkout: quem abriu, rolou, focou, preencheu, clicou Finalizar e fez pedido';

    private const EVENTOS_DIA_ROBO = 300;

    public function handle(): int
    {
        $dias = max(1, (int) $this->option('dias'));
        $inicio = now()->subDays($dias - 1)->startOfDay();

        $eventos = CheckoutEvento::query()
            ->where('created_at', '>=', $inicio)
            ->get(['visitor_id', 'user_id', 'pagina_id', 'evento', 'campo', 'valor', 'rolagem', 'dispositivo', 'origem', 'created_at']);

        $visitantes = $this->soDeFora($eventos, $inicio)->groupBy('visitor_id')
            ->filter(fn (Collection $doVisitante) => $doVisitante->contains('evento', 'abriu'))
            ->map(fn (Collection $doVisitante) => $this->resumo($doVisitante));

        $this->info("Funil do checkout de {$inicio->format('d/m/Y')} a ".now()->format('d/m/Y').' (só gente de fora, por visitante)');

        if ($visitantes->isEmpty()) {
            $this->line('Ninguém abriu a finalização no período.');

            return self::SUCCESS;
        }

        $grupos = ['celular', 'computador', 'tablet'];
        $colunas = collect($grupos)
            ->mapWithKeys(fn ($g) => [$g => $visitantes->where('dispositivo', $g)])
            ->filter(fn (Collection $c) => $c->isNotEmpty())
            ->put('total', $visitantes);

        $etapas = [
            'Abriram' => fn ($v) => true,
            'Viram o formulário' => fn ($v) => $v->viuFormulario,
            'Rolaram 50%+' => fn ($v) => $v->rolagem >= 50,
            'Focaram 1º campo' => fn ($v) => $v->focos->isNotEmpty(),
        ];
        foreach (CheckoutEvento::CAMPOS as $campo) {
            $etapas["  preencheu {$campo}"] = fn ($v) => $v->preenchidos->contains($campo);
        }
        $etapas += [
            'CEP respondido (frete)' => fn ($v) => $v->cepRespondido,
            'Escolheram pagamento' => fn ($v) => $v->pagamentos->isNotEmpty(),
            'Clicaram Finalizar' => fn ($v) => $v->finalizou,
            'Viram erro' => fn ($v) => $v->erros->isNotEmpty(),
            'Pedido criado' => fn ($v) => $v->pedido,
        ];

        $linhas = [];
        foreach ($etapas as $nome => $regra) {
            $linha = [$nome];
            foreach ($colunas as $coluna) {
                $n = $coluna->filter($regra)->count();
                $linha[] = $n.' ('.round($n * 100 / $coluna->count()).'%)';
            }
            $linhas[] = $linha;
        }
        $this->table(['Etapa', ...$colunas->keys()], $linhas);

        $semPedido = fn (Collection $c) => $c->reject(fn ($v) => $v->pedido);
        $this->table(['Quem saiu sem pedido', ...$colunas->keys()], [
            ['Visitantes', ...$colunas->map(fn ($c) => $semPedido($c)->count())->values()],
            ['Mediana de tempo ativo (s)', ...$colunas->map(fn ($c) => $this->mediana($semPedido($c)->pluck('segundos')->filter(fn ($s) => $s !== null)))->values()],
            ['Mediana de rolagem (%)', ...$colunas->map(fn ($c) => $this->mediana($semPedido($c)->pluck('rolagem')))->values()],
            ['Sem digitar nada', ...$colunas->map(fn ($c) => $semPedido($c)->filter(fn ($v) => $v->preenchidos->isEmpty())->count())->values()],
        ]);

        $erros = $visitantes->flatMap(fn ($v) => $v->erros)->countBy()->sortDesc();
        if ($erros->isNotEmpty()) {
            $this->line('Erros mostrados (visitantes): '.$erros->map(fn ($n, $campo) => "{$campo} {$n}")->implode(', '));
        }
        $origens = $visitantes->countBy(fn ($v) => $v->origem ?? 'sem dado')->sortDesc();
        $this->line('Origem de quem abriu: '.$origens->map(fn ($n, $o) => "{$o} {$n}")->implode(', '));

        return self::SUCCESS;
    }

    /** Tira equipe e robô (mesma regra do painel de visitas). */
    private function soDeFora(Collection $eventos, $inicio): Collection
    {
        $equipe = User::query()->whereIn('role', User::STAFF_ROLES)->pluck('id')->all();

        $daEquipe = $eventos->filter(fn ($e) => $e->user_id !== null && in_array((int) $e->user_id, $equipe, true))->pluck('visitor_id');
        $visitasDaEquipe = $equipe === [] ? collect() : SiteVisit::query()
            ->where('created_at', '>=', $inicio)
            ->whereIn('user_id', $equipe)
            ->whereIn('visitor_id', $eventos->pluck('visitor_id')->unique()->all())
            ->distinct()->pluck('visitor_id');
        $robos = $eventos->countBy(fn ($e) => $e->visitor_id.'|'.$e->created_at->toDateString())
            ->filter(fn ($n) => $n > self::EVENTOS_DIA_ROBO)
            ->keys()->map(fn ($k) => explode('|', $k)[0]);

        $fora = $daEquipe->merge($visitasDaEquipe)->merge($robos)->unique()->all();

        return $eventos->whereNotIn('visitor_id', $fora);
    }

    private function resumo(Collection $e): object
    {
        $saidas = $e->where('evento', 'saiu');
        // Uma página pode mandar "saiu" mais de uma vez (trocou de aba e voltou): vale o maior de cada abertura.
        $segundos = $saidas->isEmpty() ? null : (int) $saidas->groupBy(fn ($s) => $s->pagina_id ?? '-')->sum(fn ($g) => $g->max('valor'));

        return (object) [
            'dispositivo' => $e->firstWhere('evento', 'abriu')->dispositivo ?? 'computador',
            'origem' => $e->where('evento', 'abriu')->pluck('origem')->filter()->first(),
            'viuFormulario' => $e->contains('evento', 'viu_formulario'),
            'rolagem' => (int) max($e->where('evento', 'rolagem')->max('valor') ?? 0, $saidas->max('rolagem') ?? 0),
            'focos' => $e->where('evento', 'foco')->pluck('campo')->unique()->values(),
            'preenchidos' => $e->where('evento', 'preencheu')->pluck('campo')->unique()->values(),
            'cepRespondido' => $e->contains('evento', 'cep_respondido'),
            'pagamentos' => $e->where('evento', 'pagamento')->pluck('campo')->unique()->values(),
            'finalizou' => $e->contains('evento', 'finalizar'),
            'erros' => $e->where('evento', 'erro')->pluck('campo')->unique()->values(),
            'pedido' => $e->contains('evento', CheckoutEvento::PEDIDO_CRIADO),
            'segundos' => $segundos,
        ];
    }

    private function mediana(Collection $valores): string
    {
        $v = $valores->map(fn ($x) => (float) $x)->sort()->values();
        if ($v->isEmpty()) {
            return '-';
        }
        $meio = intdiv($v->count(), 2);
        $m = $v->count() % 2 ? $v[$meio] : ($v[$meio - 1] + $v[$meio]) / 2;

        return (string) round($m);
    }
}
