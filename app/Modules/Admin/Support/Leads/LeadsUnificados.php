<?php

namespace App\Modules\Admin\Support\Leads;

use App\Models\User;
use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Cart\Support\RecuperacaoCarrinhoWhatsApp;
use App\Modules\Cart\Support\Sequencia\ImportadorSequencia;
use App\Modules\Checkout\Models\Lead;
use App\Modules\Checkout\Models\LeadCheckout;
use App\Modules\Checkout\Models\Order;
use App\Modules\Cart\Support\Sequencia\ParadasSequencia;
use App\Modules\WhatsApp\Support\WhatsAppPhone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lista única de Leads (plano do Lira 25/11/2026): junta as três telas de
 * hoje sem repetir a mesma sessão de checkout.
 *
 * Como as linhas se casam (sempre dentro da mesma loja):
 * 1. A linha-mãe é o lead digitado (tabela `leads`), que nasce ao abrir o checkout.
 * 2. O carrinho abandonado (`lead_checkout`, nasce em "Ir para Entrega") entra
 *    no lead da MESMA sessão (session_id). Sem sessão igual: o do mesmo pedido
 *    (order_id) e, por último, o do mesmo visitante (visitor_id) com horários
 *    que se cruzam (até 3 h). Se a sessão tem dois leads (comprou e voltou),
 *    fica com o de horário mais perto. Um lead recebe um carrinho só; carrinho
 *    sem par vira linha própria ("c{id}").
 * 3. O registro da sequência (`adm_abandoned_carts`) entra na linha do
 *    carrinho de origem (origem = lead_checkout, origem_id). Origem cart_snapshot
 *    ou carrinho já apagado: a linha mais recente do mesmo e-mail ou usuário
 *    ainda sem sequência; sem nenhuma, linha própria ("s{id}").
 * 4. Sessões diferentes da MESMA PESSOA (mesma loja e mesmo e-mail, CPF ou
 *    telefone normalizado, inclusive de forma encadeada) viram uma linha só
 *    (PessoaLead, decisão do Lira em 25/11/2026), com "N sessões".
 *
 * Os volumes são pequenos (os leads somem em 90 dias, LimparLeadsDoCheckout),
 * então a junção é feita em memória, numa consulta por tabela.
 */
class LeadsUnificados
{
    /** Janela do casamento por visitante (o mesmo FluxoDoCheckout::HORAS_ANTES). */
    private const HORAS_VISITANTE = 3;

    public function __construct(
        private readonly LeadStatusResolver $resolver,
        private readonly RecuperacaoCarrinhoWhatsApp $recuperacao,
    ) {
    }

    /** @return Collection<int, PessoaLead> uma por pessoa, mais recente primeiro */
    public function linhas(?string $site = null): Collection
    {
        $sessoes = $this->sessoes($site);
        $usuarios = $this->usuarios($sessoes);

        return collect($this->agrupar($sessoes, $usuarios))
            ->map(fn (array $grupo) => new PessoaLead($grupo, $usuarios))
            ->sortByDesc(fn (PessoaLead $p) => $p->ultimaAtividade()?->getTimestamp() ?? 0)
            ->values();
    }

    /** @return Collection<int, LinhaLead> uma por sessão de checkout, mais recente primeiro */
    public function sessoes(?string $site = null): Collection
    {
        $corte = now()->subDays(max(1, (int) config('loja.lead_checkout_dias_guarda', 90)));

        $leads = Lead::query()->with('order')
            ->when($site, fn ($q) => $q->where('site', $site))
            ->where('updated_at', '>=', $corte)
            ->orderBy('created_at')->orderBy('id')
            ->get();
        $checkouts = LeadCheckout::query()->with('order')
            ->when($site, fn ($q) => $q->where('site', $site))
            ->where('updated_at', '>=', $corte)
            ->orderBy('created_at')->orderBy('id')
            ->get();
        $registros = Registro::query()
            ->when($site, fn ($q) => $q->where('site', $site))
            ->where(fn ($q) => $q->where('parado', false)->orWhere('updated_at', '>=', $corte))
            ->orderBy('abandonado_em')->orderBy('id')
            ->get();

        /** @var array<int, LinhaLead> $porLead */
        $porLead = [];
        foreach ($leads as $lead) {
            $porLead[$lead->id] = new LinhaLead(lead: $lead);
        }

        $linhas = array_values($porLead);
        $porCheckout = [];
        foreach ($checkouts as $checkout) {
            $par = $this->parDoCheckout($checkout, $leads, $porLead);
            if ($par) {
                $par->checkout = $checkout;
                $porCheckout[$checkout->id] = $par;

                continue;
            }
            $linhas[] = $porCheckout[$checkout->id] = new LinhaLead(checkout: $checkout);
        }

        foreach ($registros as $registro) {
            $linha = $registro->origem === Registro::ORIGEM_LEAD ? ($porCheckout[(int) $registro->origem_id] ?? null) : null;
            if ($linha && $linha->registro) {
                $linha = null;
            }
            $linha ??= $this->linhaDaMesmaPessoa($registro, $linhas);
            if ($linha) {
                $linha->registro = $registro;

                continue;
            }
            $linhas[] = new LinhaLead(registro: $registro);
        }

        foreach ($linhas as $linha) {
            $linha->pedido = $linha->lead?->order ?? $linha->checkout?->order ?? $this->pedidoDaSequencia($linha->registro);
            $linha->situacao = $this->resolver->resolver($linha->pedido, $linha->convertido(), $linha->ultimaAtividade(), $linha->registro);
            $linha->podeAntecipar = $this->podeAntecipar($linha);
        }

        return collect($linhas)
            ->sortByDesc(fn (LinhaLead $l) => $l->ultimaAtividade()?->getTimestamp() ?? 0)
            ->values();
    }

    /** A pessoa de qualquer uma das chaves de sessão dela. */
    public function achar(string $chave): ?PessoaLead
    {
        return $this->linhas()->first(fn (PessoaLead $p) => in_array($chave, $p->chaves(), true));
    }

    /**
     * Junta as sessões da mesma pessoa: mesma loja e mesmo e-mail, CPF ou
     * telefone (normalizado, sem o 55 e o nono dígito), de forma encadeada.
     *
     * @param  Collection<int, LinhaLead>  $sessoes
     * @param  array<int, User>  $usuarios
     * @return list<list<LinhaLead>>
     */
    private function agrupar(Collection $sessoes, array $usuarios): array
    {
        $lista = $sessoes->values()->all();
        $pai = array_keys($lista);
        $raiz = function (int $i) use (&$pai, &$raiz): int {
            return $pai[$i] === $i ? $i : ($pai[$i] = $raiz($pai[$i]));
        };
        $dono = [];
        foreach ($lista as $i => $s) {
            foreach ($this->identidades($s, $usuarios) as $id) {
                $id = $s->site().'|'.$id;
                if (isset($dono[$id])) {
                    $pai[$raiz($i)] = $raiz($dono[$id]);
                } else {
                    $dono[$id] = $i;
                }
            }
        }

        $grupos = [];
        foreach ($lista as $i => $s) {
            $grupos[$raiz($i)][] = $s;
        }

        return array_values($grupos);
    }

    /** @return list<string> */
    private function identidades(LinhaLead $s, array $usuarios): array
    {
        $ids = [];
        $usuario = null;
        foreach ([$s->lead?->user_id, $s->checkout?->user_id, $s->registro?->user_id] as $uid) {
            $usuario ??= $uid ? ($usuarios[$uid] ?? null) : null;
        }
        foreach ([$s->lead?->email, $s->checkout?->email, $s->registro?->email, $usuario?->email] as $email) {
            $email = mb_strtolower(trim((string) $email));
            // E-mail pela metade (digitando) não junta ninguém.
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $ids[] = 'email:'.$email;
            }
        }
        foreach ([$s->lead?->cpf, $s->checkout?->cpf, $usuario?->cpf] as $cpf) {
            $cpf = (string) preg_replace('/\D/', '', (string) $cpf);
            if (strlen($cpf) === 11) {
                $ids[] = 'cpf:'.$cpf;
            }
        }
        foreach ([$s->lead?->telefone, $s->checkout?->telefone, $s->registro?->telefone, $usuario?->phone] as $telefone) {
            if ($normal = WhatsAppPhone::normalize($telefone)) {
                $ids[] = 'tel:'.WhatsAppPhone::chave($normal);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  Collection<int, LinhaLead>  $sessoes
     * @return array<int, User>
     */
    private function usuarios(Collection $sessoes): array
    {
        $ids = $sessoes->flatMap(fn (LinhaLead $s) => [$s->lead?->user_id, $s->checkout?->user_id, $s->registro?->user_id, $s->pedido?->user_id])
            ->filter()->unique()->values();

        return $ids->isEmpty() ? [] : User::query()->whereIn('id', $ids)->get(['id', 'name', 'email', 'phone', 'cpf'])->keyBy('id')->all();
    }

    /**
     * Filtro dos chips (todos, agora, abertos, remarketing, pagos, parados) e da busca.
     *
     * @param  Collection<int, PessoaLead>  $linhas
     */
    public static function filtrar(Collection $linhas, ?string $status, string $busca = ''): Collection
    {
        $termo = mb_strtolower(trim($busca));
        $digitos = (string) preg_replace('/\D/', '', $busca);

        return $linhas
            ->filter(fn (PessoaLead $l) => self::noChip($l, $status))
            ->filter(fn (PessoaLead $l) => $termo === ''
                || str_contains($l->textoDeBusca(), $termo)
                || (strlen($digitos) >= 4 && collect($l->digitosDeBusca())->contains(fn ($n) => str_contains($n, $digitos))))
            ->values();
    }

    public static function noChip(PessoaLead $l, ?string $status): bool
    {
        $codigo = $l->situacao['codigo'];

        return match ($status) {
            'agora' => $codigo === LeadStatusResolver::AGORA,
            'abertos' => in_array($codigo, LeadStatusResolver::ABERTOS, true),
            'remarketing' => $codigo === LeadStatusResolver::REMARKETING,
            'pagos' => $codigo === LeadStatusResolver::PAGO,
            'parados' => $codigo === LeadStatusResolver::PAROU,
            default => true,
        };
    }

    /**
     * Cards do topo: as mesmas regras dos chips, sobre a lista já filtrada pela loja.
     *
     * @param  Collection<int, PessoaLead>  $linhas
     */
    public static function cards(Collection $linhas): array
    {
        $abertos = self::filtrar($linhas, 'abertos');

        return [
            'agora' => self::filtrar($linhas, 'agora')->count(),
            'abertos' => $abertos->count(),
            'valor_aberto' => round($abertos->sum(fn (PessoaLead $l) => $l->total()), 2),
            'remarketing' => self::filtrar($linhas, 'remarketing')->count(),
            'pagos' => self::filtrar($linhas, 'pagos')->count(),
            'parados' => self::filtrar($linhas, 'parados')->count(),
            'antecipar' => $linhas->where('podeAntecipar', true)->count(),
        ];
    }

    /**
     * Mesmas regras do botão de hoje (LeadCheckoutController::paraDisparar +
     * RecuperacaoCarrinhoWhatsApp::bloqueio): carrinho aberto, com celular ou
     * e-mail, ainda não recebeu, loja com sequência. A linha também esconde o
     * botão quando a sequência já mandou ou parou (o serviço recusaria).
     */
    private function podeAntecipar(LinhaLead $linha): bool
    {
        $checkout = $linha->checkout;
        if (! $checkout || ! in_array($checkout->site, ImportadorSequencia::SITES, true)) {
            return false;
        }
        if ($this->recuperacao->bloqueio($checkout) !== null) {
            return false;
        }

        return ! ($linha->registro && ($linha->registro->parado || $linha->registro->disparos_enviados > 0));
    }

    /**
     * @param  Collection<int, Lead>  $leads
     * @param  array<int, LinhaLead>  $porLead
     */
    private function parDoCheckout(LeadCheckout $checkout, Collection $leads, array $porLead): ?LinhaLead
    {
        $livres = $leads->filter(fn (Lead $l) => $l->site === $checkout->site && $porLead[$l->id]->checkout === null);

        $candidatos = $checkout->session_id ? $livres->where('session_id', $checkout->session_id) : collect();
        if ($candidatos->isEmpty() && $checkout->order_id) {
            $candidatos = $livres->where('order_id', $checkout->order_id);
        }
        if ($candidatos->isEmpty() && filled($checkout->visitor_id) && $checkout->created_at) {
            $de = $checkout->created_at->copy()->subHours(self::HORAS_VISITANTE);
            $ate = ($checkout->updated_at ?? $checkout->created_at)->copy()->addHours(self::HORAS_VISITANTE);
            $candidatos = $livres->filter(fn (Lead $l) => $l->visitor_id === $checkout->visitor_id
                && $l->created_at && $l->created_at->lte($ate) && ($l->updated_at ?? $l->created_at)->gte($de));
        }
        if ($candidatos->isEmpty()) {
            return null;
        }

        // Mesmo pedido primeiro; depois o lead que começou mais perto do carrinho.
        $lead = $candidatos->sortBy(fn (Lead $l) => ($checkout->order_id && (int) $l->order_id === (int) $checkout->order_id ? 0 : 10 ** 12)
            + ($checkout->created_at && $l->created_at ? abs($l->created_at->getTimestamp() - $checkout->created_at->getTimestamp()) : 10 ** 11))
            ->first();

        return $porLead[$lead->id];
    }

    /** @param  list<LinhaLead>  $linhas */
    private function linhaDaMesmaPessoa(Registro $registro, array $linhas): ?LinhaLead
    {
        $email = $registro->email ? mb_strtolower($registro->email) : null;
        if (! $email && ! $registro->user_id) {
            return null;
        }

        return collect($linhas)
            ->filter(fn (LinhaLead $l) => $l->registro === null && $l->site() === $registro->site
                && (($email && mb_strtolower((string) $l->email()) === $email)
                    || ($registro->user_id && in_array($registro->user_id, [$l->lead?->user_id, $l->checkout?->user_id], true))))
            ->sortByDesc(fn (LinhaLead $l) => $l->ultimaAtividade()?->getTimestamp() ?? 0)
            ->first();
    }

    /** Sequência parada por "comprou" sem lead ligado: o pedido da pessoa depois do abandono. */
    private function pedidoDaSequencia(?Registro $registro): ?Order
    {
        if (! $registro || $registro->motivo_parada !== Registro::PAROU_COMPROU || (! $registro->email && ! $registro->user_id)) {
            return null;
        }

        return Order::query()
            ->whereIn('status', ParadasSequencia::STATUS_PAGOS)
            ->where('created_at', '>=', $registro->abandonado_em ?? $registro->created_at ?? Carbon::create(2000))
            ->where(fn ($q) => $q
                ->when($registro->email, fn ($q) => $q->orWhere('shipping_email', $registro->email))
                ->when($registro->user_id, fn ($q) => $q->orWhere('user_id', $registro->user_id)))
            ->latest('id')
            ->first();
    }
}
