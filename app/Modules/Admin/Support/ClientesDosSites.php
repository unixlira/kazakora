<?php

namespace App\Modules\Admin\Support;

use App\Models\User;
use App\Modules\Checkout\Models\Lead;
use App\Modules\Checkout\Models\LeadCheckout;
use App\Modules\Checkout\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Admin > Clientes dos sites (pedido do Lira 2026-11-16): as CONTAS criadas
 * nos sites próprios, não os compradores de todos os canais (isso é a tela
 * Clientes, CustomerAggregator).
 *
 * - KazaKora: tabela users, só role "customer" (equipe e admin ficam de
 *   fora), sem conta apagada. Endereços em addresses. Pedido ligado por
 *   orders.user_id (origin "loja"). A conta criada pelo checkout da Beauty
 *   Kora também é um User, então aparece aqui com o pedido dela.
 * - FerraKora: tabela ferrakora_clientes (conta separada desde 2026-10-15),
 *   endereços em ferrakora_enderecos. Pedido ligado por
 *   orders.ferrakora_cliente_id (origin "loja", site_origem "ferrakora").
 *
 * Pedido pago = paid, shipped ou completed, com o filtro de venda efetiva
 * (Order::vendaEfetiva: sem estorno, devolução concluída nem amostra).
 * Só leitura: nada aqui altera o cadastro nem fala com o cliente. Hash de
 * senha e token nunca saem daqui.
 */
class ClientesDosSites
{
    public const KAZAKORA = 'kazakora';

    public const FERRAKORA = 'ferrakora';

    public const SITES = [self::KAZAKORA => 'KazaKora', self::FERRAKORA => 'FerraKora'];

    public const STATUS_PAGOS = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED];

    public const ORDENS = ['recentes', 'antigos', 'maior_gasto', 'ultimo_pedido', 'nome'];

    /** Teto de pedidos, leads e carrinhos no detalhe. */
    private const LIMITE_DETALHE = 100;

    /** Filtro "as duas lojas" (pedido do Lira 26/11/2026: "quero filtro por empresa"). */
    public const TODAS = 'todas';

    public static function siteValido(?string $site): string
    {
        if ($site === self::TODAS || $site === null || $site === '') {
            return self::TODAS;
        }

        return isset(self::SITES[$site]) ? $site : self::TODAS;
    }

    /**
     * Lista filtrada (uma linha por conta). Com "todas", as contas das duas
     * lojas numa lista só (coluna site diz de qual loja é).
     *
     * @param  array{busca?: ?string, de?: ?string, ate?: ?string, pedido?: ?string, foto?: ?string, ordem?: ?string}  $filtros
     */
    public function consulta(string $site, array $filtros): Builder
    {
        $base = $site === self::TODAS
            ? $this->parte(self::KAZAKORA, $filtros)->unionAll($this->parte(self::FERRAKORA, $filtros))
            : $this->parte($site, $filtros);

        $query = DB::query()->fromSub($base, 'u')->select('u.*');

        match ($filtros['ordem'] ?? 'recentes') {
            'antigos' => $query->orderBy('u.created_at')->orderBy('u.id'),
            'maior_gasto' => $query->orderByRaw('COALESCE(u.total_gasto, 0) DESC'),
            'ultimo_pedido' => $query->orderByRaw('u.ultimo_pedido_em IS NULL')->orderByDesc('u.ultimo_pedido_em'),
            'nome' => $query->orderBy('u.name'),
            default => $query->orderByDesc('u.created_at'),
        };

        return $query->orderByDesc('u.id');
    }

    /** As contas de uma loja, já filtradas, sem ordem (entra no UNION). */
    private function parte(string $site, array $filtros): Builder
    {
        [$tabela, $tabelaEndereco, $chaveEndereco] = $this->tabelas($site);

        $endereco = fn (string $coluna) => DB::table($tabelaEndereco)
            ->select($coluna)
            ->whereColumn("{$tabelaEndereco}.{$chaveEndereco}", 'c.id')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->limit(1);

        $query = DB::table("{$tabela} as c")
            ->leftJoinSub($this->resumoDePedidos($site), 'p', 'p.cliente_id', '=', 'c.id')
            ->select([
                DB::raw("'{$site}' as site"),
                'c.id', 'c.name', 'c.email', 'c.phone', 'c.cpf', 'c.created_at', 'c.avatar_path',
                $site === self::KAZAKORA ? 'c.recebe_promocoes' : DB::raw('NULL as recebe_promocoes'),
                'p.pedidos_pagos', 'p.total_gasto', 'p.ultimo_pedido_em',
            ])
            ->selectSub($endereco('city'), 'cidade')
            ->selectSub($endereco('state'), 'uf');

        if ($site === self::KAZAKORA) {
            $query->where('c.role', User::ROLE_CUSTOMER)->whereNull('c.deleted_at');
        }

        $busca = trim((string) ($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $digitos = preg_replace('/\D/', '', $busca);
            $query->where(function (Builder $q) use ($busca, $digitos) {
                $q->where('c.name', 'like', "%{$busca}%")
                    ->orWhere('c.email', 'like', '%'.mb_strtolower($busca).'%');
                if (strlen($digitos) >= 3) {
                    $q->orWhereRaw($this->soDigitos('c.cpf').' like ?', ["%{$digitos}%"])
                        ->orWhereRaw($this->soDigitos('c.phone').' like ?', ["%{$digitos}%"]);
                }
            });
        }

        if ($de = $this->data($filtros['de'] ?? null)) {
            $query->where('c.created_at', '>=', $de->startOfDay());
        }
        if ($ate = $this->data($filtros['ate'] ?? null)) {
            $query->where('c.created_at', '<=', $ate->endOfDay());
        }

        match ($filtros['pedido'] ?? null) {
            'com' => $query->whereNotNull('p.cliente_id'),
            'sem' => $query->whereNull('p.cliente_id'),
            default => null,
        };

        match ($filtros['foto'] ?? null) {
            'com' => $query->whereNotNull('c.avatar_path')->where('c.avatar_path', '!=', ''),
            'sem' => $query->where(fn ($q) => $q->whereNull('c.avatar_path')->orWhere('c.avatar_path', '')),
            default => null,
        };

        return $query;
    }

    /** Linha da lista e do CSV (sem senha, token nem nada além do cadastro). */
    public function linha(object $c): array
    {
        $telefone = preg_replace('/\D/', '', (string) $c->phone);

        return [
            'id' => (int) $c->id,
            'site' => $c->site ?? null,
            'tem_foto' => filled($c->avatar_path ?? null),
            'nome' => $c->name,
            'email' => $c->email,
            'telefone' => $telefone !== '' ? $telefone : null,
            'whatsapp' => $this->whatsapp($telefone),
            'cpf' => preg_replace('/\D/', '', (string) $c->cpf) ?: null,
            'cadastro_em' => $this->iso($c->created_at),
            'recebe_promocoes' => $c->recebe_promocoes === null ? null : (bool) $c->recebe_promocoes,
            'cidade' => $c->cidade,
            'uf' => $c->uf,
            'pedidos_pagos' => (int) ($c->pedidos_pagos ?? 0),
            'total_gasto' => round((float) ($c->total_gasto ?? 0), 2),
            'ultimo_pedido_em' => $this->iso($c->ultimo_pedido_em),
        ];
    }

    /** Tudo do detalhe, ou null se a conta não é cliente do site. */
    public function detalhe(string $site, int $id): ?array
    {
        $linha = $this->consulta($site, [])->where('u.id', $id)->first();
        if (! $linha) {
            return null;
        }

        [$tabela, $tabelaEndereco, $chaveEndereco] = $this->tabelas($site);
        $conta = DB::table($tabela)->where('id', $id)->first();
        $telefone = preg_replace('/\D/', '', (string) $conta->phone);
        $cpf = preg_replace('/\D/', '', (string) $conta->cpf);

        $cadastro = [
            'nome' => $conta->name,
            'email' => $conta->email,
            'telefone' => $telefone ?: null,
            'cpf' => $cpf ?: null,
            'nascimento' => $conta->birth_date ? substr((string) $conta->birth_date, 0, 10) : null,
            'cadastro_em' => $this->iso($conta->created_at),
            'atualizado_em' => $this->iso($conta->updated_at),
            'senha_temporaria' => (bool) ($conta->deve_trocar_senha ?? false),
        ];
        if ($site === self::KAZAKORA) {
            $cadastro += [
                'email_verificado_em' => $this->iso($conta->email_verified_at),
                'recebe_promocoes' => (bool) $conta->recebe_promocoes,
            ];
        } else {
            // Conta criada pela migração dos pedidos antigos, sem senha escolhida.
            $cadastro['senha_definida'] = (bool) $conta->senha_definida;
        }

        $enderecos = DB::table($tabelaEndereco)
            ->where($chaveEndereco, $id)
            ->orderByDesc('is_default')->orderBy('id')
            ->get(['label', 'recipient_name', 'phone', 'zip', 'street', 'number', 'complement', 'neighborhood', 'city', 'state', 'is_default', 'created_at'])
            ->map(fn ($e) => [
                'rotulo' => $e->label,
                'destinatario' => $e->recipient_name,
                'telefone' => preg_replace('/\D/', '', (string) $e->phone) ?: null,
                'cep' => preg_replace('/\D/', '', (string) $e->zip),
                'rua' => $e->street,
                'numero' => $e->number,
                'complemento' => $e->complement,
                'bairro' => $e->neighborhood,
                'cidade' => $e->city,
                'uf' => $e->state,
                'principal' => (bool) $e->is_default,
                'criado_em' => $this->iso($e->created_at),
            ])->all();

        $chavePedido = $site === self::KAZAKORA ? 'user_id' : 'ferrakora_cliente_id';
        $pedidos = Order::query()
            ->where('origin', Order::ORIGIN_STORE)
            ->where($chavePedido, $id)
            ->latest('created_at')->latest('id')
            ->limit(self::LIMITE_DETALHE)
            ->get(['id', 'external_order_id', 'status', 'total', 'site_origem', 'created_at'])
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'numero' => $o->external_order_id,
                'status' => $o->status,
                'total' => round((float) $o->total, 2),
                'site' => $o->site_origem ?: self::KAZAKORA,
                'pago' => in_array($o->status, self::STATUS_PAGOS, true),
                'criado_em' => $o->created_at?->toIso8601String(),
            ])->all();

        return [
            'site' => $site,
            'cliente' => ['id' => $id, ...$cadastro, 'whatsapp' => $this->whatsapp($telefone)],
            'resumo' => $this->linha($linha),
            'enderecos' => $enderecos,
            'pedidos' => $pedidos,
            'acesso' => $this->acesso($site, $id),
            'leads' => $this->leads(Lead::query(), $site, $id, $conta->email, $cpf, $telefone)
                ->get()->map(fn (Lead $l) => [
                    'id' => $l->id,
                    'site' => $l->site,
                    'nome' => $l->nome,
                    'email' => $l->email,
                    'telefone' => $l->telefone,
                    'cep' => $l->cep,
                    'itens' => $l->itens ?? [],
                    'subtotal' => round((float) $l->subtotal, 2),
                    'status' => $l->status,
                    'pedido_id' => $l->order_id,
                    'origem' => collect([$l->origem, $l->utm_source, $l->utm_campaign])->filter()->implode(' · ') ?: null,
                    'criado_em' => $l->created_at?->toIso8601String(),
                    'atualizado_em' => $l->updated_at?->toIso8601String(),
                ])->all(),
            'carrinhos' => $this->leads(LeadCheckout::query(), $site, $id, $conta->email, $cpf, $telefone)
                ->get()->map(fn (LeadCheckout $l) => [
                    'id' => $l->id,
                    'site' => $l->site,
                    'etapa' => $l->etapa,
                    'itens' => $l->itens ?? [],
                    'subtotal' => round((float) $l->subtotal, 2),
                    'status' => $l->status,
                    'pedido_id' => $l->order_id,
                    'criado_em' => $l->created_at?->toIso8601String(),
                    'atualizado_em' => $l->updated_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * Agregado dos pedidos pagos por conta: cliente_id, pedidos_pagos,
     * total_gasto, ultimo_pedido_em.
     */
    private function resumoDePedidos(string $site)
    {
        $chave = $site === self::KAZAKORA ? 'orders.user_id' : 'orders.ferrakora_cliente_id';

        return Order::query()
            ->vendaEfetiva()
            ->where('orders.origin', Order::ORIGIN_STORE)
            ->whereIn('orders.status', self::STATUS_PAGOS)
            ->whereNotNull($chave)
            ->groupBy($chave)
            ->selectRaw("{$chave} as cliente_id, COUNT(*) as pedidos_pagos, SUM(orders.total) as total_gasto, MAX(orders.created_at) as ultimo_pedido_em");
    }

    /**
     * Último acesso que o sistema conhece. KazaKora: última página vista
     * logado (site_visits.user_id). FerraKora: último uso de uma sessão do
     * site ainda guardada (a sessão some ao sair ou 30 dias sem uso).
     */
    private function acesso(string $site, int $id): array
    {
        if ($site === self::KAZAKORA) {
            return [
                'ultimo_acesso_em' => $this->iso(DB::table('site_visits')->where('user_id', $id)->max('created_at')),
                'fonte' => 'Última página da loja vista com a conta logada.',
                'sessoes_abertas' => null,
            ];
        }

        $sessoes = DB::table('ferrakora_sessoes')->where('cliente_id', $id);

        return [
            'ultimo_acesso_em' => $this->iso((clone $sessoes)->max(DB::raw('COALESCE(ultimo_uso_em, created_at)'))),
            'fonte' => 'Último uso de uma sessão do site FerraKora (a sessão some ao sair ou após 30 dias sem uso).',
            'sessoes_abertas' => (clone $sessoes)->where('expira_em', '>', now())->count(),
        ];
    }

    /**
     * Leads e carrinhos do mesmo site ligados à conta: pelo user_id
     * (KazaKora), pelo e-mail, pelo CPF ou pelo telefone (gravados só com
     * dígitos, sem o 55).
     */
    private function leads($query, string $site, int $id, ?string $email, string $cpf, string $telefone)
    {
        if (strlen($telefone) >= 12 && str_starts_with($telefone, '55')) {
            $telefone = substr($telefone, 2);
        }

        return $query
            ->when($site === self::FERRAKORA, fn ($q) => $q->where('site', self::FERRAKORA), fn ($q) => $q->where('site', '!=', self::FERRAKORA))
            ->where(function ($q) use ($site, $id, $email, $cpf, $telefone) {
                $q->whereRaw('1 = 0')
                    ->when($site === self::KAZAKORA, fn ($q) => $q->orWhere('user_id', $id))
                    ->when(filled($email), fn ($q) => $q->orWhere('email', mb_strtolower(trim((string) $email))))
                    ->when(strlen($cpf) === 11, fn ($q) => $q->orWhere('cpf', $cpf))
                    ->when(strlen($telefone) >= 10, fn ($q) => $q->orWhere('telefone', $telefone));
            })
            ->latest('updated_at')->latest('id')
            ->limit(self::LIMITE_DETALHE);
    }

    /** @return array{0: string, 1: string, 2: string} tabela, tabela de endereço, chave do endereço */
    private function tabelas(string $site): array
    {
        return $site === self::KAZAKORA
            ? ['users', 'addresses', 'user_id']
            : ['ferrakora_clientes', 'ferrakora_enderecos', 'cliente_id'];
    }

    /** Coluna sem máscara (CPF e telefone ficam gravados com ou sem pontuação). */
    private function soDigitos(string $coluna): string
    {
        $sql = "COALESCE({$coluna}, '')";
        foreach (['.', '-', '(', ')', ' ', '+', '/'] as $sinal) {
            $sql = "REPLACE({$sql}, '{$sinal}', '')";
        }

        return $sql;
    }

    /** Link wa.me com o 55 do Brasil (número de 10 ou 11 dígitos). */
    private function whatsapp(string $telefone): ?string
    {
        if (strlen($telefone) >= 12 && str_starts_with($telefone, '55')) {
            return "https://wa.me/{$telefone}";
        }

        return in_array(strlen($telefone), [10, 11], true) ? "https://wa.me/55{$telefone}" : null;
    }

    private function data(?string $valor): ?CarbonImmutable
    {
        if (! $valor || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $valor, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function iso($valor): ?string
    {
        return $valor ? CarbonImmutable::parse($valor, config('app.timezone'))->toIso8601String() : null;
    }
}
