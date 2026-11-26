<?php

namespace App\Modules\Clientes\Support;

use App\Modules\Checkout\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Linha do tempo completa de uma conta (pedido do Lira 26/11/2026: "quero
 * rastreio de histórico total dos clientes"). Junta, em ordem de data e
 * hora, o que cada tabela já guardava e o que passou a ser gravado em
 * cliente_eventos. Só leitura. Nada do passado é inventado: FONTES diz
 * desde quando cada tipo existe.
 *
 * LGPD (docs/privacidade-e-cookies.md, "Histórico do cliente"):
 * - Página visitada só aparece com o aceite de cookies daquela visita
 *   (site_visits.consentiu, ou a FerraKora com "Aceito"); sem ele, só o
 *   horário do acesso.
 * - O mapa de cliques (kzm.js, infoproduto_eventos) é anônimo por design e
 *   não entra aqui.
 * - Medição do checkout entra só do próprio usuário ou do navegador cujas
 *   visitas já foram ligadas à conta.
 */
class LinhaDoTempoDoCliente
{
    /** Teto por fonte (as mais recentes); a tela avisa quando bate. */
    private const LIMITE_PAGINAS = 1500;

    private const LIMITE_FONTE = 300;

    /** Tempo na página: diferença até a próxima página do mesmo navegador, se for menor que isso. */
    private const TEMPO_MAXIMO_PAGINA = 30 * 60;

    public const CATEGORIAS = [
        'conta' => 'Conta',
        'acesso' => 'Login e saída',
        'pagina' => 'Páginas',
        'carrinho' => 'Carrinho e checkout',
        'pedido' => 'Pedidos e pagamentos',
        'mensagem' => 'WhatsApp e e-mail',
        'senha' => 'Senha',
        'perfil' => 'Perfil, foto e endereço',
        'cupom' => 'Cupons',
        'privacidade' => 'Cookies',
    ];

    private const NOMES_EVENTO_CHECKOUT = [
        'abriu' => 'Abriu o checkout',
        'produto_carrinho' => 'Pôs produto no carrinho',
        'pagamento' => 'Escolheu a forma de pagamento',
        'finalizar' => 'Clicou em Finalizar pedido',
        'erro' => 'Erro no checkout',
        'saiu' => 'Saiu do checkout',
        'cupom_aplicado' => 'Aplicou cupom',
        'cupom_erro' => 'Cupom recusado',
    ];

    private array $eventos = [];

    private array $cortes = [];

    /**
     * @param  object  $conta  linha de users ou ferrakora_clientes
     * @return array{eventos: list<array>, fontes: list<array>, cortes: list<string>}
     */
    public function montar(string $site, object $conta): array
    {
        $this->eventos = [];
        $this->cortes = [];
        $kz = $site === HistoricoDoCliente::KAZAKORA;
        $id = (int) $conta->id;
        $email = mb_strtolower(trim((string) $conta->email));
        $cpf = preg_replace('/\D/', '', (string) $conta->cpf);
        $telefone = preg_replace('/\D/', '', (string) $conta->phone);

        $this->cadastro($site, $conta);
        $this->eventosGravados($site, $id);
        $visitantes = $this->paginas($site, $id);
        if ($kz) {
            $this->medicaoDoCheckout($id, $visitantes);
            $this->aceitesDeCookies($id);
            $this->senhaTemporaria($id);
        } else {
            $this->sessoesFerraKora($id);
        }
        $this->linkDeSenhaEmAberto($kz ? 'password_reset_tokens' : 'ferrakora_senha_tokens', $email);
        $this->enderecos($site, $id);
        $orderIds = $this->pedidos($site, $id);
        $this->emailsDosPedidos($orderIds);
        $this->cupons($site, $id, $orderIds);
        $this->carrinhos($site, $id, $email, $cpf, $telefone);
        $this->recuperacao($site, $id, $email, $telefone);
        $this->whatsapp($telefone);

        $eventos = collect($this->eventos)
            ->filter(fn ($e) => $e['em'] !== null)
            ->sortBy([['em', 'asc'], ['ordem', 'asc']])
            ->values()
            ->map(fn ($e) => collect($e)->except('ordem')->all())
            ->all();

        return ['eventos' => $eventos, 'fontes' => $this->fontes($site), 'cortes' => array_values(array_unique($this->cortes))];
    }

    /** Desde quando cada tipo existe e por quanto tempo fica guardado. */
    private function fontes(string $site): array
    {
        $hoje = $this->dataBr(HistoricoDoCliente::REGISTRADO_DESDE);
        $comum = [
            ['nome' => 'Data do cadastro', 'desde' => 'sempre', 'guarda' => 'enquanto a conta existir'],
            ['nome' => 'Origem do cadastro', 'desde' => $hoje, 'guarda' => 'enquanto a conta existir', 'obs' => 'antes disso, estimada pela 1ª visita de fora ou pelo carrinho'],
            ['nome' => 'Login e saída', 'desde' => $hoje, 'guarda' => '6 meses'],
            ['nome' => 'Pedido de link e troca de senha', 'desde' => $hoje, 'guarda' => 'enquanto a conta existir', 'obs' => 'antes disso, só o último link ainda em aberto'],
            ['nome' => 'Foto, endereço e dados do perfil', 'desde' => $hoje, 'guarda' => 'enquanto a conta existir', 'obs' => 'endereços antigos aparecem pela data de cadastro deles'],
            ['nome' => 'Pedidos e pagamentos', 'desde' => 'sempre', 'guarda' => 'enquanto houver o pedido'],
            ['nome' => 'E-mails do pedido', 'desde' => '31/07/2026', 'guarda' => 'enquanto houver o pedido'],
            ['nome' => 'WhatsApp (pelo telefone da conta)', 'desde' => '05/09/2026', 'guarda' => 'enquanto houver a conversa'],
            ['nome' => 'Carrinho no checkout (lead)', 'desde' => '24/10/2026', 'guarda' => '3 meses sem mexer'],
            ['nome' => 'Sequência do carrinho abandonado', 'desde' => '21/11/2026', 'guarda' => 'enquanto houver o carrinho'],
            ['nome' => 'Cupons usados', 'desde' => '25/10/2026', 'guarda' => 'enquanto houver o pedido'],
        ];

        return $site === HistoricoDoCliente::KAZAKORA
            ? [...$comum,
                ['nome' => 'Páginas vistas com a conta logada', 'desde' => '12/10/2026', 'guarda' => '6 meses', 'obs' => 'a página só aparece com o aceite de cookies; navegação de antes do login ligada à conta desde '.$hoje],
                ['nome' => 'Medição do checkout', 'desde' => '23/10/2026', 'guarda' => '6 meses'],
                ['nome' => 'Aceite de cookies', 'desde' => '10/10/2026', 'guarda' => '5 anos'],
                ['nome' => 'Senha temporária enviada (compra sem conta)', 'desde' => '11/10/2026', 'guarda' => 'enquanto a conta existir'],
            ]
            : [...$comum,
                ['nome' => 'Páginas vistas com a conta logada e "Aceito"', 'desde' => $hoje, 'guarda' => '6 meses', 'obs' => 'a navegação de antes do login não é ligada (o visitante da FerraKora é o IP)'],
                ['nome' => 'Entradas antes de '.$hoje, 'desde' => '15/10/2026', 'guarda' => 'só as sessões ainda abertas (somem ao sair ou após 30 dias sem uso)'],
            ];
    }

    private function cadastro(string $site, object $conta): void
    {
        $chave = $site === HistoricoDoCliente::KAZAKORA ? 'user_id' : 'ferrakora_cliente_id';
        $evento = DB::table('cliente_eventos')->where($chave, $conta->id)->where('tipo', 'cadastro')->orderBy('id')->first();
        $detalhe = $evento ? (json_decode((string) $evento->detalhe, true) ?: []) : [];
        $loja = $site === HistoricoDoCliente::KAZAKORA ? 'KazaKora' : 'FerraKora';
        $origem = ResumoDoCliente::textoDaOrigem($detalhe['origem'] ?? []);

        $this->add($conta->created_at, 'conta', "Cadastro na {$loja}", collect([
            $detalhe['via'] ?? null,
            $origem ? "origem: {$origem}" : ($evento ? 'sem origem gravada' : 'origem não registrada (cadastro antes de '.$this->dataBr(HistoricoDoCliente::REGISTRADO_DESDE).')'),
        ])->filter()->implode(' · '), 'fa-user-plus', -1);
    }

    private function eventosGravados(string $site, int $id): void
    {
        $chave = $site === HistoricoDoCliente::KAZAKORA ? 'user_id' : 'ferrakora_cliente_id';
        $linhas = DB::table('cliente_eventos')->where($chave, $id)->where('tipo', '!=', 'cadastro')
            ->orderByDesc('id')->limit(self::LIMITE_FONTE + 1)->get();
        $this->cortou($linhas, self::LIMITE_FONTE, 'Eventos da conta: mostrando os '.self::LIMITE_FONTE.' mais recentes.');

        foreach ($linhas as $e) {
            $d = json_decode((string) $e->detalhe, true) ?: [];
            [$categoria, $icone] = match (true) {
                in_array($e->tipo, ['login', 'logout'], true) => ['acesso', $e->tipo === 'login' ? 'fa-right-to-bracket' : 'fa-right-from-bracket'],
                str_starts_with($e->tipo, 'senha') => ['senha', 'fa-key'],
                $e->tipo === 'visitas_ligadas' => ['pagina', 'fa-link'],
                default => ['perfil', str_starts_with($e->tipo, 'foto') ? 'fa-camera' : (str_starts_with($e->tipo, 'endereco') ? 'fa-location-dot' : 'fa-user-pen')],
            };
            $texto = collect([
                isset($d['como']) ? "por {$d['como']}" : null,
                isset($d['visitas']) ? "{$d['visitas']} página(s) deste navegador, de antes do login (com aceite de cookies)" : null,
                isset($d['cidade']) || isset($d['uf']) ? trim(($d['cidade'] ?? '').'/'.($d['uf'] ?? ''), '/') : null,
                isset($d['campos']) ? 'campos: '.implode(', ', array_map(fn ($c) => self::NOMES_CAMPO[$c] ?? $c, (array) $d['campos'])) : null,
                ! empty($d['era_temporaria']) ? 'era a senha temporária' : null,
                ! empty($d['pela_equipe']) ? 'alterado pela equipe' : null,
                $e->dispositivo ? "({$e->dispositivo})" : null,
            ])->filter()->implode(' · ');

            $this->add($e->created_at, $categoria, HistoricoDoCliente::TIPOS[$e->tipo] ?? $e->tipo, $texto, $icone);
        }
    }

    private const NOMES_CAMPO = ['name' => 'nome', 'email' => 'e-mail', 'phone' => 'telefone', 'cpf' => 'CPF', 'birth_date' => 'nascimento'];

    /**
     * Páginas da conta. Devolve os visitantes (kazakora_visitor) dessas
     * visitas, para a medição do checkout do mesmo navegador.
     *
     * @return list<string>
     */
    private function paginas(string $site, int $id): array
    {
        $chave = $site === HistoricoDoCliente::KAZAKORA ? 'user_id' : 'ferrakora_cliente_id';
        $visitas = DB::table('site_visits')->where($chave, $id)
            ->orderByDesc('id')->limit(self::LIMITE_PAGINAS + 1)
            ->get(['id', 'visitor_id', 'path', 'created_at', 'consentiu', 'origem', 'utm_source', 'utm_medium', 'utm_campaign', 'referer', 'click_id_param', 'dispositivo', 'site']);
        $this->cortou($visitas, self::LIMITE_PAGINAS, 'Páginas: mostrando as '.self::LIMITE_PAGINAS.' mais recentes.');

        $visitas = $visitas->sortBy('id')->values();
        // FerraKora: só entra visita com a conta logada e "Aceito" (gravada assim), então vale como aceite.
        $comAceite = fn ($v) => $site === HistoricoDoCliente::FERRAKORA || (bool) $v->consentiu;

        // Tempo na página: até a próxima página do mesmo navegador (percorre de trás para frente).
        $tempos = [];
        $proximaDoVisitante = [];
        foreach ($visitas->reverse() as $v) {
            $agora = CarbonImmutable::parse($v->created_at)->timestamp;
            $seguinte = $proximaDoVisitante[$v->visitor_id] ?? null;
            $tempos[$v->id] = $seguinte !== null && ($seguinte - $agora) <= self::TEMPO_MAXIMO_PAGINA ? $seguinte - $agora : null;
            $proximaDoVisitante[$v->visitor_id] = $agora;
        }

        foreach ($visitas as $v) {
            $tempo = $tempos[$v->id];
            $origem = $v->origem && $v->origem !== 'interno' ? ResumoDoCliente::textoDaOrigem(HistoricoDoCliente::origemDaVisita($v)) : null;

            if ($comAceite($v)) {
                $this->add($v->created_at, 'pagina', 'Abriu '.$v->path, collect([
                    $origem ? "chegou por {$origem}" : null,
                    $v->dispositivo,
                ])->filter()->implode(' · '), 'fa-eye', 0, ['tempo_segundos' => $tempo, 'pagina' => $v->path]);
            } else {
                $this->add($v->created_at, 'pagina', 'Acessou o site logado', 'página não mostrada: visita sem aceite de cookies', 'fa-eye-slash', 0, ['tempo_segundos' => $tempo]);
            }
        }

        return $visitas->pluck('visitor_id')->filter(fn ($v) => Str::isUuid((string) $v))->unique()->values()->all();
    }

    private function medicaoDoCheckout(int $id, array $visitantes): void
    {
        $linhas = DB::table('checkout_eventos')
            ->where(fn ($q) => $q->whereNull('site')->orWhere('site', '!=', 'ferrakora'))
            ->where(fn ($q) => $q->where('user_id', $id)->when($visitantes !== [], fn ($q) => $q->orWhereIn('visitor_id', $visitantes)))
            ->whereIn('evento', array_keys(self::NOMES_EVENTO_CHECKOUT))
            ->orderByDesc('id')->limit(self::LIMITE_FONTE + 1)
            ->get(['evento', 'campo', 'valor', 'motivo', 'created_at']);
        $this->cortou($linhas, self::LIMITE_FONTE, 'Medição do checkout: mostrando os '.self::LIMITE_FONTE.' eventos mais recentes.');

        foreach ($linhas as $e) {
            $texto = match ($e->evento) {
                'pagamento' => $e->valor ? 'forma: '.(ResumoDoCliente::METODOS[$e->valor] ?? $e->valor) : null,
                'erro' => collect([$e->campo ? "campo {$e->campo}" : null, $e->motivo])->filter()->implode(' · '),
                'produto_carrinho' => $e->valor ? "produto #{$e->valor}" : null,
                default => null,
            };
            $this->add($e->created_at, 'carrinho', self::NOMES_EVENTO_CHECKOUT[$e->evento], (string) $texto, 'fa-cart-shopping');
        }
    }

    private function aceitesDeCookies(int $id): void
    {
        DB::table('consentimentos_cookies')->where('user_id', $id)->orderBy('id')->limit(self::LIMITE_FONTE)
            ->get(['versao', 'escolha', 'origem', 'aceito_em'])
            ->each(fn ($c) => $this->add($c->aceito_em, 'privacidade', ($c->escolha ?? 'aceito') === 'basico' ? 'Recusou cookies não necessários' : 'Aceitou os cookies', "versão {$c->versao}".($c->origem ? " · {$c->origem}" : ''), 'fa-cookie-bite'));
    }

    private function senhaTemporaria(int $id): void
    {
        DB::table('notifications')
            ->where('type', \App\Notifications\Cliente\SenhaTemporariaEnviada::class)
            ->where('notifiable_type', \App\Models\User::class)->where('notifiable_id', $id)
            ->get(['created_at'])
            ->each(fn ($n) => $this->add($n->created_at, 'senha', 'Senha temporária enviada por e-mail', 'conta criada na compra sem cadastro', 'fa-key'));
    }

    private function sessoesFerraKora(int $id): void
    {
        $logins = DB::table('cliente_eventos')->where('ferrakora_cliente_id', $id)->where('tipo', 'login')->pluck('created_at')
            ->map(fn ($m) => CarbonImmutable::parse($m)->timestamp);

        DB::table('ferrakora_sessoes')->where('cliente_id', $id)->orderBy('id')->get(['created_at', 'ultimo_uso_em', 'user_agent'])
            ->each(function ($s) use ($logins) {
                $em = CarbonImmutable::parse($s->created_at)->timestamp;
                if ($logins->contains(fn ($l) => abs($l - $em) <= 10)) {
                    return; // o login já está no histórico
                }
                $this->add($s->created_at, 'acesso', 'Entrou na conta', 'sessão do site ainda guardada'.($s->ultimo_uso_em ? ' · último uso '.CarbonImmutable::parse($s->ultimo_uso_em)->timezone(config('app.timezone'))->format('d/m/Y H:i') : ''), 'fa-right-to-bracket');
            });
    }

    private function linkDeSenhaEmAberto(string $tabela, string $email): void
    {
        if ($email === '') {
            return;
        }
        $token = DB::table($tabela)->where('email', $email)->first(['created_at']);
        if ($token && ! $this->temPerto('senha', 'Pediu link de nova senha', $token->created_at)) {
            $this->add($token->created_at, 'senha', 'Pediu link de nova senha', 'link ainda não usado (o banco guarda só o último)', 'fa-key');
        }
    }

    private function enderecos(string $site, int $id): void
    {
        [$tabela, $chave] = $site === HistoricoDoCliente::KAZAKORA ? ['addresses', 'user_id'] : ['ferrakora_enderecos', 'cliente_id'];
        DB::table($tabela)->where($chave, $id)->orderBy('id')->limit(self::LIMITE_FONTE)->get(['city', 'state', 'created_at'])
            ->each(function ($e) {
                if (! $this->temPerto('perfil', HistoricoDoCliente::TIPOS['endereco_criado'], $e->created_at)) {
                    $this->add($e->created_at, 'perfil', HistoricoDoCliente::TIPOS['endereco_criado'], trim("{$e->city}/{$e->state}", '/'), 'fa-location-dot');
                }
            });
    }

    /** @return list<int> */
    private function pedidos(string $site, int $id): array
    {
        $chave = $site === HistoricoDoCliente::KAZAKORA ? 'user_id' : 'ferrakora_cliente_id';
        $pedidos = Order::query()->where('origin', Order::ORIGIN_STORE)->where($chave, $id)
            ->latest('id')->limit(self::LIMITE_FONTE)
            ->get(['id', 'external_order_id', 'status', 'total', 'coupon_code', 'created_at', 'entregue_em', 'cancelado_em', 'estornado_em', 'site_origem']);
        if ($pedidos->isEmpty()) {
            return [];
        }

        foreach ($pedidos as $o) {
            $numero = $o->external_order_id ?: $o->id;
            $this->add($o->created_at, 'pedido', "Pedido #{$numero} criado", 'R$ '.number_format((float) $o->total, 2, ',', '.')." · status atual: {$o->status}", 'fa-bag-shopping', 1, ['pedido_id' => $o->id]);
            if ($o->entregue_em) {
                $this->add($o->entregue_em, 'pedido', "Pedido #{$numero} entregue", '', 'fa-box-open', 1, ['pedido_id' => $o->id]);
            }
            if ($o->cancelado_em) {
                $this->add($o->cancelado_em, 'pedido', "Pedido #{$numero} cancelado", '', 'fa-ban', 1, ['pedido_id' => $o->id]);
            }
            if ($o->estornado_em) {
                $this->add($o->estornado_em, 'pedido', "Pedido #{$numero} estornado", '', 'fa-rotate-left', 1, ['pedido_id' => $o->id]);
            }
        }

        $numeros = $pedidos->mapWithKeys(fn ($o) => [$o->id => $o->external_order_id ?: $o->id]);
        DB::table('payments')->whereIn('order_id', $pedidos->pluck('id'))->orderBy('id')
            ->get(['order_id', 'method_type', 'status', 'amount', 'motivo_recusa', 'created_at', 'updated_at'])
            ->each(function ($p) use ($numeros) {
                $metodo = ResumoDoCliente::METODOS[$p->method_type] ?? ($p->method_type ?: 'forma não informada');
                $this->add($p->created_at, 'pedido', "Pagamento por {$metodo} (pedido #{$numeros[$p->order_id]})", collect([
                    'status: '.(self::STATUS_PAGAMENTO[$p->status] ?? $p->status),
                    $p->amount !== null ? 'R$ '.number_format((float) $p->amount, 2, ',', '.') : null,
                    $p->motivo_recusa ? "recusa: {$p->motivo_recusa}" : null,
                ])->filter()->implode(' · '), 'fa-credit-card', 2, ['pedido_id' => $p->order_id]);
            });

        return $pedidos->pluck('id')->all();
    }

    private const STATUS_PAGAMENTO = [
        'captured' => 'aprovado', 'refunded' => 'estornado', 'canceled' => 'cancelado',
        'requires_confirmation' => 'aguardando pagamento', 'pending' => 'pendente', 'rejected' => 'recusado', 'failed' => 'falhou',
    ];

    private function emailsDosPedidos(array $orderIds): void
    {
        if ($orderIds === []) {
            return;
        }
        $nomes = ['order_confirmation' => 'confirmação do pedido', 'PedidoEnviado' => 'pedido enviado', 'PedidoCancelado' => 'pedido cancelado'];
        DB::table('order_email_logs')->whereIn('order_id', $orderIds)->orderByDesc('id')->limit(self::LIMITE_FONTE)
            ->get(['order_id', 'mailable', 'status', 'attempt', 'created_at'])
            ->each(function ($m) use ($nomes) {
                $nome = $nomes[class_basename($m->mailable)] ?? class_basename($m->mailable);
                $this->add($m->created_at, 'mensagem', "E-mail: {$nome}", "pedido {$m->order_id} · {$m->status}".($m->attempt > 1 ? " · tentativa {$m->attempt}" : ''), 'fa-envelope', 3, ['pedido_id' => $m->order_id]);
            });
    }

    private function cupons(string $site, int $id, array $orderIds): void
    {
        $vistos = [];
        if ($site === HistoricoDoCliente::KAZAKORA) {
            DB::table('cupom_usos')->leftJoin('coupons', 'coupons.id', '=', 'cupom_usos.coupon_id')
                ->where(fn ($q) => $q->where('cupom_usos.user_id', $id)->when($orderIds !== [], fn ($q) => $q->orWhereIn('cupom_usos.order_id', $orderIds)))
                ->get(['coupons.code', 'cupom_usos.order_id', 'cupom_usos.created_at'])
                ->each(function ($c) use (&$vistos) {
                    $vistos[(int) $c->order_id] = true;
                    $this->add($c->created_at, 'cupom', 'Usou o cupom '.($c->code ?? ''), $c->order_id ? "pedido {$c->order_id}" : '', 'fa-ticket');
                });
        }
        if ($orderIds !== []) {
            DB::table('orders')->whereIn('id', $orderIds)->whereNotNull('coupon_code')->where('coupon_code', '!=', '')
                ->get(['id', 'coupon_code', 'discount_amount', 'created_at'])
                ->each(function ($o) use ($vistos) {
                    if (! isset($vistos[(int) $o->id])) {
                        $this->add($o->created_at, 'cupom', "Usou o cupom {$o->coupon_code}", "pedido {$o->id}".((float) $o->discount_amount > 0 ? ' · desconto R$ '.number_format((float) $o->discount_amount, 2, ',', '.') : ''), 'fa-ticket', 1);
                    }
                });
        }
    }

    private function carrinhos(string $site, int $id, string $email, string $cpf, string $telefone): void
    {
        if (strlen($telefone) >= 12 && str_starts_with($telefone, '55')) {
            $telefone = substr($telefone, 2);
        }
        $doCliente = fn ($q) => $q
            ->when($site === HistoricoDoCliente::FERRAKORA, fn ($q) => $q->where('site', 'ferrakora'), fn ($q) => $q->where(fn ($q) => $q->whereNull('site')->orWhere('site', '!=', 'ferrakora')))
            ->where(function ($q) use ($site, $id, $email, $cpf, $telefone) {
                $q->whereRaw('1 = 0')
                    ->when($site === HistoricoDoCliente::KAZAKORA, fn ($q) => $q->orWhere('user_id', $id))
                    ->when($email !== '', fn ($q) => $q->orWhere('email', $email))
                    ->when(strlen($cpf) === 11, fn ($q) => $q->orWhere('cpf', $cpf))
                    ->when(strlen($telefone) >= 10, fn ($q) => $q->orWhere('telefone', $telefone));
            });

        $doCliente(DB::table('lead_checkout'))->orderByDesc('id')->limit(self::LIMITE_FONTE)
            ->get(['id', 'etapa', 'status', 'subtotal', 'quantidade_itens', 'order_id', 'created_at', 'updated_at', 'convertido_em'])
            ->each(function ($l) {
                $this->add($l->created_at, 'carrinho', 'Começou o checkout', collect([
                    "{$l->quantidade_itens} item(ns)",
                    'R$ '.number_format((float) $l->subtotal, 2, ',', '.'),
                    $l->etapa ? "chegou até: {$l->etapa}" : null,
                ])->filter()->implode(' · '), 'fa-cart-arrow-down');
                if ($l->convertido_em || $l->order_id) {
                    $this->add($l->convertido_em ?? $l->updated_at, 'carrinho', 'Checkout virou pedido', $l->order_id ? "pedido {$l->order_id}" : '', 'fa-cart-arrow-down');
                } elseif ($l->status === 'aberto') {
                    $this->add($l->updated_at, 'carrinho', 'Checkout abandonado (sem pedido)', 'última mexida neste horário', 'fa-cart-arrow-down');
                }
            });

        // Leads da FerraKora (cada campo preenchido no checkout de lá, desde 11/11/2026).
        if ($site === HistoricoDoCliente::FERRAKORA) {
            $doCliente(DB::table('leads'))->orderByDesc('id')->limit(self::LIMITE_FONTE)
                ->get(['status', 'subtotal', 'ultimo_campo', 'order_id', 'created_at', 'updated_at'])
                ->each(fn ($l) => $this->add($l->created_at, 'carrinho', 'Começou o checkout (FerraKora)', collect([
                    'R$ '.number_format((float) $l->subtotal, 2, ',', '.'),
                    $l->ultimo_campo ? "último campo: {$l->ultimo_campo}" : null,
                    $l->order_id ? "virou o pedido {$l->order_id}" : ($l->status ? "situação: {$l->status}" : null),
                ])->filter()->implode(' · '), 'fa-cart-arrow-down'));
        }
    }

    private function recuperacao(string $site, int $id, string $email, string $telefone): void
    {
        $carrinhos = DB::table('adm_abandoned_carts')
            ->where('site', $site === HistoricoDoCliente::KAZAKORA ? 'kazakora' : 'ferrakora')
            ->where(function ($q) use ($site, $id, $email, $telefone) {
                $q->whereRaw('1 = 0')
                    ->when($site === HistoricoDoCliente::KAZAKORA, fn ($q) => $q->orWhere('user_id', $id))
                    ->when($email !== '', fn ($q) => $q->orWhere('email', $email))
                    ->when(strlen($telefone) >= 10, fn ($q) => $q->orWhereIn('telefone', array_unique([$telefone, str_starts_with($telefone, '55') ? substr($telefone, 2) : '55'.$telefone])));
            })
            ->orderByDesc('id')->limit(50)->get();

        foreach ($carrinhos as $c) {
            $this->add($c->abandonado_em ?? $c->created_at, 'carrinho', 'Carrinho abandonado', 'R$ '.number_format((float) $c->subtotal, 2, ',', '.').' · entrou na sequência de recuperação', 'fa-cart-shopping');
            foreach (json_decode((string) $c->historico, true) ?: [] as $m) {
                $this->add($m['quando'] ?? null, 'mensagem', 'Recuperação do carrinho: mensagem '.($m['numero'] ?? '?').' por '.(($m['canal'] ?? '') === 'whatsapp' ? 'WhatsApp' : 'e-mail'), collect([
                    $m['assunto'] ?? null, $m['status'] ?? null,
                ])->filter()->implode(' · '), ($m['canal'] ?? '') === 'whatsapp' ? 'fa-comment' : 'fa-envelope');
            }
            if ($c->convertido_em) {
                $this->add($c->convertido_em, 'carrinho', 'Carrinho recuperado (virou pedido)', '', 'fa-cart-shopping');
            }
            if ($c->parado && $c->motivo_parada) {
                $this->add($c->updated_at, 'carrinho', 'Sequência do carrinho parada', "motivo: {$c->motivo_parada}", 'fa-cart-shopping');
            }
        }
    }

    private function whatsapp(string $telefone): void
    {
        if (strlen($telefone) < 10) {
            return;
        }
        $comDdi = str_starts_with($telefone, '55') && strlen($telefone) >= 12 ? $telefone : '55'.$telefone;
        // Celular com e sem o 9 (o WhatsApp às vezes grava o número antigo, sem o 9).
        $numeros = [$comDdi];
        if (strlen($comDdi) === 13) {
            $numeros[] = substr($comDdi, 0, 4).substr($comDdi, 5);
        }

        $conversas = DB::table('whatsapp_conversations')->whereIn('wa_id', $numeros)->pluck('id');
        if ($conversas->isEmpty()) {
            return;
        }

        $mensagens = DB::table('whatsapp_messages')->whereIn('conversation_id', $conversas)
            ->orderByDesc('id')->limit(self::LIMITE_FONTE + 1)
            ->get(['direction', 'type', 'body', 'status', 'sent_at', 'received_at', 'created_at']);
        $this->cortou($mensagens, self::LIMITE_FONTE, 'WhatsApp: mostrando as '.self::LIMITE_FONTE.' mensagens mais recentes.');

        foreach ($mensagens->take(self::LIMITE_FONTE) as $m) {
            $saiu = $m->direction !== 'inbound';
            $this->add($m->sent_at ?? $m->received_at ?? $m->created_at, 'mensagem', $saiu ? 'WhatsApp enviado' : 'WhatsApp recebido do cliente', collect([
                Str::limit(trim(preg_replace('/\s+/', ' ', (string) $m->body)), 120) ?: ($m->type !== 'text' ? $m->type : null),
                $saiu && $m->status ? $m->status : null,
            ])->filter()->implode(' · '), 'fa-comment');
        }
    }

    private function add($quando, string $categoria, string $titulo, string $texto, string $icone, int $ordem = 0, array $extra = []): void
    {
        $em = $quando ? CarbonImmutable::parse($quando, config('app.timezone'))->toIso8601String() : null;
        $this->eventos[] = ['em' => $em, 'categoria' => $categoria, 'titulo' => $titulo, 'texto' => $texto, 'icone' => $icone, 'ordem' => $ordem, ...$extra];
    }

    /** Já existe evento igual a até 10 s deste momento (ex.: endereço já gravado no histórico)? */
    private function temPerto(string $categoria, string $titulo, $quando): bool
    {
        $t = CarbonImmutable::parse($quando, config('app.timezone'))->timestamp;

        return collect($this->eventos)->contains(fn ($e) => $e['categoria'] === $categoria && $e['titulo'] === $titulo
            && $e['em'] && abs(CarbonImmutable::parse($e['em'])->timestamp - $t) <= 10);
    }

    private function cortou(Collection $linhas, int $limite, string $aviso): void
    {
        if ($linhas->count() > $limite) {
            $linhas->pop();
            $this->cortes[] = $aviso;
        }
    }

    private function dataBr(string $iso): string
    {
        return CarbonImmutable::parse($iso)->format('d/m/Y');
    }
}
