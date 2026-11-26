<?php

namespace App\Modules\Clientes\Support;

use App\Modules\Checkout\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Colunas de acompanhamento da lista Clientes dos sites (pedido do Lira
 * 26/11/2026): último acesso, dias com acesso, origem do cadastro, formas de
 * pagamento usadas e se refez a senha. Calculado só para as linhas da página
 * (no máximo 50 contas), com consultas por lote em colunas indexadas.
 *
 * Fontes:
 * - Acesso: KazaKora = páginas vistas logado (site_visits.user_id) + logins
 *   (cliente_eventos, desde 26/11/2026). FerraKora = sessões da conta
 *   ainda guardadas (ferrakora_sessoes) + logins + páginas com a conta
 *   logada e "Aceito" (site_visits.ferrakora_cliente_id, desde 26/11/2026).
 * - Origem: a do cadastro (cliente_eventos, desde 26/11/2026); antes disso,
 *   estimada pela 1ª página de fora do site ligada à conta ou pelo
 *   carrinho (lead_checkout) do mesmo e-mail.
 * - Pagamento: payments dos pedidos da loja da conta (todas as tentativas).
 * - Senha: eventos de troca/redefinição (desde 26/11/2026), o link de nova
 *   senha ainda em aberto e, na KazaKora, a senha temporária já trocada.
 */
class ResumoDoCliente
{
    public const METODOS = ['pix' => 'Pix', 'card' => 'Cartão', 'boleto' => 'Boleto'];

    /** Pagamento aprovado (o resto é tentativa, cancelado ou esperando). */
    public const PAGAMENTO_APROVADO = ['captured', 'refunded', 'approved', 'paid', 'succeeded'];

    /**
     * @param  iterable<object>  $linhas  linhas da consulta (site, id, email)
     * @return array<string, array> chave "site:id"
     */
    public function paraLinhas(iterable $linhas): array
    {
        $porSite = collect($linhas)->groupBy('site');
        $resultado = [];

        foreach ($porSite as $site => $contas) {
            $ids = $contas->pluck('id')->map(fn ($id) => (int) $id)->all();
            $emails = $contas->pluck('email', 'id')->map(fn ($e) => mb_strtolower(trim((string) $e)));
            if ($ids === []) {
                continue;
            }

            $acessos = $this->acessos($site, $ids);
            $origens = $this->origens($site, $ids, $emails);
            $pagamentos = $this->pagamentos($site, $ids);
            $senhas = $this->senhas($site, $ids, $emails);

            foreach ($ids as $id) {
                $resultado["{$site}:{$id}"] = [
                    'ultimo_acesso_em' => $acessos[$id]['ultimo'] ?? null,
                    'dias_com_acesso' => $acessos[$id]['dias'] ?? 0,
                    'origem' => $origens[$id] ?? null,
                    'pagamentos' => $pagamentos[$id] ?? [],
                    'senha' => $senhas[$id] ?? null,
                ];
            }
        }

        return $resultado;
    }

    /** @return array<int, array{ultimo: ?string, dias: int}> */
    private function acessos(string $site, array $ids): array
    {
        $chaveVisita = $site === HistoricoDoCliente::KAZAKORA ? 'user_id' : 'ferrakora_cliente_id';
        $chaveEvento = $chaveVisita;

        $momentos = DB::table('site_visits')->whereIn($chaveVisita, $ids)
            ->selectRaw("{$chaveVisita} as dono, DATE(created_at) as dia, MAX(created_at) as ultimo")
            ->groupBy($chaveVisita, DB::raw('DATE(created_at)'))
            ->get()
            ->concat(DB::table('cliente_eventos')->whereIn($chaveEvento, $ids)->where('tipo', 'login')
                ->selectRaw("{$chaveEvento} as dono, DATE(created_at) as dia, MAX(created_at) as ultimo")
                ->groupBy($chaveEvento, DB::raw('DATE(created_at)'))
                ->get());

        if ($site === HistoricoDoCliente::FERRAKORA) {
            $momentos = $momentos->concat(DB::table('ferrakora_sessoes')->whereIn('cliente_id', $ids)
                ->get(['cliente_id', 'created_at', 'ultimo_uso_em'])
                ->flatMap(fn ($s) => collect([$s->created_at, $s->ultimo_uso_em])->filter()->map(fn ($m) => (object) [
                    'dono' => $s->cliente_id, 'dia' => substr((string) $m, 0, 10), 'ultimo' => $m,
                ])));
        }

        return $momentos->groupBy('dono')->map(fn (Collection $itens) => [
            'ultimo' => $this->iso($itens->max('ultimo')),
            'dias' => $itens->pluck('dia')->unique()->count(),
        ])->all();
    }

    /** @return array<int, array{texto: string, estimada: bool, detalhe: array}> */
    private function origens(string $site, array $ids, Collection $emails): array
    {
        $chave = $site === HistoricoDoCliente::KAZAKORA ? 'user_id' : 'ferrakora_cliente_id';
        $origens = [];

        // 1) A gravada no cadastro.
        DB::table('cliente_eventos')->whereIn($chave, $ids)->where('tipo', 'cadastro')
            ->orderBy('id')->get([$chave.' as dono', 'detalhe'])
            ->each(function ($e) use (&$origens) {
                $detalhe = json_decode((string) $e->detalhe, true) ?: [];
                if (! isset($origens[$e->dono])) {
                    $origens[$e->dono] = ['detalhe' => $detalhe['origem'] ?? [], 'via' => $detalhe['via'] ?? null, 'estimada' => false];
                }
            });

        // 2) Estimada: 1ª página de fora do site ligada à conta.
        $faltam = array_values(array_filter($ids, fn ($id) => empty($origens[$id]['detalhe'])));
        if ($faltam !== []) {
            $primeiras = DB::table('site_visits')->whereIn($chave, $faltam)
                ->whereNotNull('origem')->where('origem', '!=', 'interno')
                ->selectRaw("{$chave} as dono, MIN(id) as id")->groupBy($chave)->pluck('id', 'dono');
            if ($primeiras->isNotEmpty()) {
                DB::table('site_visits')->whereIn('id', $primeiras->values())
                    ->get([$chave.' as dono', 'origem', 'utm_source', 'utm_medium', 'utm_campaign', 'referer', 'click_id_param', 'created_at'])
                    ->each(function ($v) use (&$origens) {
                        $origens[$v->dono] = [...($origens[$v->dono] ?? ['via' => null]), 'detalhe' => HistoricoDoCliente::origemDaVisita($v), 'estimada' => true];
                    });
            }
        }

        // 3) Estimada: carrinho do mesmo e-mail com origem.
        $faltam = array_values(array_filter($ids, fn ($id) => empty($origens[$id]['detalhe'])));
        if ($faltam !== []) {
            $emailsFaltam = $emails->only($faltam)->filter();
            if ($emailsFaltam->isNotEmpty()) {
                $porEmail = $emailsFaltam->flip();
                DB::table('lead_checkout')
                    ->when($site === HistoricoDoCliente::FERRAKORA, fn ($q) => $q->where('site', 'ferrakora'), fn ($q) => $q->where(fn ($q) => $q->whereNull('site')->orWhere('site', '!=', 'ferrakora')))
                    ->whereIn('email', $emailsFaltam->values())
                    ->where(fn ($q) => $q->whereNotNull('origem')->orWhereNotNull('utm_source'))
                    ->orderBy('id')
                    ->get(['email', 'origem', 'utm_source', 'utm_medium', 'utm_campaign', 'created_at'])
                    ->each(function ($l) use (&$origens, $porEmail) {
                        $dono = $porEmail[mb_strtolower((string) $l->email)] ?? null;
                        if ($dono !== null && empty($origens[$dono]['detalhe'])) {
                            $origens[$dono] = [...($origens[$dono] ?? ['via' => null]), 'detalhe' => array_filter([
                                'origem' => $l->origem, 'utm_source' => $l->utm_source, 'utm_medium' => $l->utm_medium, 'utm_campaign' => $l->utm_campaign,
                            ]), 'estimada' => true];
                        }
                    });
            }
        }

        return collect($origens)->map(fn ($o) => [
            'texto' => self::textoDaOrigem($o['detalhe'] ?? []) ?? ($o['via'] ? 'Sem origem gravada' : null),
            'estimada' => (bool) ($o['estimada'] ?? false),
            'via' => $o['via'] ?? null,
            'detalhe' => $o['detalhe'] ?? [],
        ])->filter(fn ($o) => $o['texto'] !== null)->all();
    }

    /** "instagram · utm: meta / cpc / campanha · clique fbclid" */
    public static function textoDaOrigem(array $o): ?string
    {
        if ($o === []) {
            return null;
        }

        $utm = collect([$o['utm_source'] ?? null, $o['utm_medium'] ?? null, $o['utm_campaign'] ?? null])->filter()->implode(' / ');

        return collect([
            isset($o['origem']) ? \App\Modules\Analytics\Support\OrigemVisita::rotulo($o['origem'], $o['referer'] ?? null, $o['utm_source'] ?? null) : null,
            $utm !== '' ? "utm: {$utm}" : null,
            isset($o['referer']) ? "veio de {$o['referer']}" : null,
            isset($o['clique']) ? "clique {$o['clique']}" : null,
        ])->filter()->unique()->implode(' · ') ?: null;
    }

    /** @return array<int, list<array{metodo: string, nome: string, aprovado: bool, vezes: int}>> */
    private function pagamentos(string $site, array $ids): array
    {
        $chave = $site === HistoricoDoCliente::KAZAKORA ? 'orders.user_id' : 'orders.ferrakora_cliente_id';

        return DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.origin', Order::ORIGIN_STORE)
            ->whereIn($chave, $ids)
            ->whereNotNull('payments.method_type')
            ->get(["{$chave} as dono", 'payments.method_type', 'payments.status'])
            ->groupBy('dono')
            ->map(fn (Collection $pgs) => $pgs->groupBy('method_type')->map(fn (Collection $mesmo, $metodo) => [
                'metodo' => $metodo,
                'nome' => self::METODOS[$metodo] ?? ucfirst((string) $metodo),
                'aprovado' => $mesmo->contains(fn ($p) => in_array($p->status, self::PAGAMENTO_APROVADO, true)),
                'vezes' => $mesmo->count(),
            ])->values()->all())
            ->all();
    }

    /** @return array<int, array{status: string, texto: string, em: ?string}> */
    private function senhas(string $site, array $ids, Collection $emails): array
    {
        $chave = $site === HistoricoDoCliente::KAZAKORA ? 'user_id' : 'ferrakora_cliente_id';
        $senhas = [];

        DB::table('cliente_eventos')->whereIn($chave, $ids)
            ->whereIn('tipo', ['senha_redefinida', 'senha_trocada', 'senha_link_enviado'])
            ->orderBy('created_at')->orderBy('id')
            ->get([$chave.' as dono', 'tipo', 'created_at'])
            ->each(function ($e) use (&$senhas) {
                $atual = $senhas[$e->dono]['status'] ?? null;
                // Troca vale mais que o pedido de link.
                if ($e->tipo === 'senha_link_enviado' && in_array($atual, ['redefiniu', 'trocou'], true)) {
                    return;
                }
                $senhas[$e->dono] = match ($e->tipo) {
                    'senha_redefinida' => ['status' => 'redefiniu', 'texto' => 'Redefiniu pelo link', 'em' => $this->iso($e->created_at)],
                    'senha_trocada' => ['status' => 'trocou', 'texto' => 'Trocou no perfil', 'em' => $this->iso($e->created_at)],
                    default => ['status' => 'pediu_link', 'texto' => 'Pediu link de nova senha', 'em' => $this->iso($e->created_at)],
                };
            });

        // Link de nova senha ainda em aberto (o banco guarda só o último por e-mail).
        $tabelaToken = $site === HistoricoDoCliente::KAZAKORA ? 'password_reset_tokens' : 'ferrakora_senha_tokens';
        $porEmail = $emails->filter()->flip();
        if ($porEmail->isNotEmpty()) {
            DB::table($tabelaToken)->whereIn('email', $porEmail->keys())->get(['email', 'created_at'])
                ->each(function ($t) use (&$senhas, $porEmail) {
                    $dono = $porEmail[mb_strtolower((string) $t->email)] ?? null;
                    if ($dono !== null && ! isset($senhas[$dono])) {
                        $senhas[$dono] = ['status' => 'pediu_link', 'texto' => 'Pediu link de nova senha', 'em' => $this->iso($t->created_at)];
                    }
                });
        }

        // KazaKora: conta da compra sem cadastro (senha temporária) que já trocou a senha.
        if ($site === HistoricoDoCliente::KAZAKORA) {
            $comTemporaria = DB::table('notifications')
                ->where('type', \App\Notifications\Cliente\SenhaTemporariaEnviada::class)
                ->where('notifiable_type', \App\Models\User::class)
                ->whereIn('notifiable_id', $ids)
                ->pluck('notifiable_id')->map(fn ($id) => (int) $id)->unique();
            if ($comTemporaria->isNotEmpty()) {
                DB::table('users')->whereIn('id', $comTemporaria)->get(['id', 'deve_trocar_senha'])
                    ->each(function ($u) use (&$senhas) {
                        if (isset($senhas[$u->id])) {
                            return;
                        }
                        $senhas[$u->id] = $u->deve_trocar_senha
                            ? ['status' => 'temporaria', 'texto' => 'Ainda com a senha temporária', 'em' => null]
                            : ['status' => 'trocou', 'texto' => 'Trocou a senha temporária (data não registrada)', 'em' => null];
                    });
            }
        }

        return $senhas;
    }

    private function iso($valor): ?string
    {
        return $valor ? CarbonImmutable::parse($valor, config('app.timezone'))->toIso8601String() : null;
    }
}
