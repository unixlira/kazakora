<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\FluxoDoCheckout;
use App\Modules\Admin\Support\Leads\JornadaDoLead;
use App\Modules\Admin\Support\Leads\LeadsUnificados;
use App\Modules\Admin\Support\Leads\LinhaLead;
use App\Modules\Admin\Support\Leads\PessoaLead;
use App\Modules\Admin\Support\Leads\RemarketingDoLead;
use App\Modules\Cart\Support\RecuperacaoCarrinhoWhatsApp;
use App\Modules\Cart\Support\Sequencia\CalendarioSequencia;
use App\Support\SiteAtual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin > Leads, tela única (plano do Lira 25/11/2026): junta Leads,
 * Carrinhos abandonados e Sequência numa lista só, sem repetir a mesma
 * sessão (ver LeadsUnificados). Desde 25/11/2026 é a tela de /admin/leads; as
 * rotas antigas (Carrinhos abandonados, Sequência e /admin/leads-v2) redirecionam
 * para cá com o filtro equivalente. O código das telas antigas continua no repositório.
 *
 * Só consulta, mais as duas ações que já existem (antecipar a 1ª mensagem,
 * um ou todos), que chamam o mesmo RecuperacaoCarrinhoWhatsApp do botão
 * "WhatsApp + e-mail" de Carrinhos abandonados.
 */
class LeadsUnicosController extends Controller
{
    private const PER_PAGE = 50;

    /** Teto por clique em "Antecipar para todos" (o mesmo de LeadCheckoutController). */
    private const LIMITE_DISPARO = 100;

    public const STATUS = ['agora', 'abertos', 'remarketing', 'pagos', 'parados'];

    public function index(Request $request, LeadsUnificados $unificados, FluxoDoCheckout $fluxo): Response
    {
        $site = $this->site($request);
        $status = in_array($request->string('status')->toString(), self::STATUS, true) ? $request->string('status')->toString() : null;
        $busca = trim($request->string('q')->toString());

        $todas = $unificados->linhas($site);
        $filtradas = LeadsUnificados::filtrar($todas, $status, $busca);
        $pagina = max(1, (int) $request->integer('page', 1));
        $daPagina = $filtradas->forPage($pagina, self::PER_PAGE)->values();

        // "Parou em" da sessão principal de cada pessoa da página (uma consulta por tabela).
        $principais = $daPagina->map(fn (PessoaLead $p) => $p->principal());
        $paradasLead = $fluxo->paradas($principais->map(fn (LinhaLead $l) => $l->lead)->filter());
        $paradasCheckout = $fluxo->paradas($principais->filter(fn (LinhaLead $l) => ! $l->lead && $l->checkout)->map(fn (LinhaLead $l) => $l->checkout));

        $paginador = new LengthAwarePaginator(
            $daPagina->map(function (PessoaLead $p) use ($paradasLead, $paradasCheckout) {
                $l = $p->principal();

                return $this->linha($p, $l->lead ? ($paradasLead[$l->lead->id] ?? null) : ($l->checkout ? ($paradasCheckout[$l->checkout->id] ?? null) : null));
            }),
            $filtradas->count(),
            self::PER_PAGE,
            $pagina,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('Admin/LeadsUnicos/Index', [
            'leads' => $paginador,
            'resumo' => [...LeadsUnificados::cards($todas), 'atualizado_em' => now()->toIso8601String()],
            'sites' => collect(SiteAtual::SITES)->map(fn ($nome, $id) => ['id' => $id, 'nome' => $nome])->values(),
            'filters' => ['loja' => $site, 'status' => $status, 'q' => $busca],
            'base' => '/admin/leads',
        ]);
    }

    public function remarketing(string $chave, LeadsUnificados $unificados, RemarketingDoLead $remarketing): JsonResponse
    {
        return response()->json($remarketing->montar($this->linhaOu404($unificados, $chave)));
    }

    public function jornada(string $chave, LeadsUnificados $unificados, JornadaDoLead $jornada): JsonResponse
    {
        return response()->json($jornada->montar($this->linhaOu404($unificados, $chave)));
    }

    /** Antecipa a 1ª mensagem de um lead: o mesmo serviço do botão "WhatsApp + e-mail" de hoje. */
    public function antecipar(string $chave, LeadsUnificados $unificados, RecuperacaoCarrinhoWhatsApp $recuperacao): RedirectResponse
    {
        $linha = $this->linhaOu404($unificados, $chave);
        // O carrinho elegível; sem nenhum, o mais recente (o serviço devolve o motivo de não enviar).
        $checkout = $linha->checkout ?? collect($linha->sessoes)->map(fn (LinhaLead $s) => $s->checkout)->filter()->first();
        if (! $checkout) {
            return back()->with('error', 'Este lead não chegou na Entrega: não há carrinho para a sequência.');
        }

        $resultado = $recuperacao->enviar($checkout);

        return $resultado === RecuperacaoCarrinhoWhatsApp::OK
            ? back()->with('success', 'Mensagem enviada para '.($linha->nome() ?: 'o cliente').'.')
            : back()->with('error', $resultado);
    }

    /** Todos os elegíveis (da loja filtrada, se houver), um por um, como o "Disparar para todos" de hoje. */
    public function anteciparTodos(Request $request, LeadsUnificados $unificados, RecuperacaoCarrinhoWhatsApp $recuperacao): RedirectResponse
    {
        $enviados = 0;
        $falhas = 0;
        $elegiveis = $unificados->linhas($this->site($request))->where('podeAntecipar', true)->take(self::LIMITE_DISPARO);
        foreach ($elegiveis as $linha) {
            $recuperacao->enviar($linha->checkout) === RecuperacaoCarrinhoWhatsApp::OK ? $enviados++ : $falhas++;
        }

        return back()->with($enviados ? 'success' : 'warning', "Mensagens enviadas: {$enviados}.".($falhas ? " Não enviadas: {$falhas} (ainda fora da sequência, já recebidas ou recusadas)." : ''));
    }

    private function site(Request $request): ?string
    {
        $loja = $request->string('loja')->toString();

        return isset(SiteAtual::SITES[$loja]) ? $loja : null;
    }

    /** /admin/leads-v2 (rota de teste até 25/11/2026) vai para /admin/leads com os mesmos filtros. */
    public function daV2(Request $request): RedirectResponse
    {
        return redirect()->to($this->comFiltros([], $request->only(['loja', 'status', 'q'])), 302);
    }

    /** Admin > Carrinhos abandonados agora é Leads com o chip Abertos (loja e busca vão junto). */
    public function deCarrinhos(Request $request): RedirectResponse
    {
        return redirect()->to($this->comFiltros(['status' => 'abertos'], $this->filtrosAntigos($request)), 302);
    }

    /** Admin > Sequência de carrinho agora é Leads com o chip Em remarketing. */
    public function daSequencia(Request $request): RedirectResponse
    {
        return redirect()->to($this->comFiltros(['status' => 'remarketing'], $this->filtrosAntigos($request)), 302);
    }

    /** As telas antigas usavam ?site= e ?search=. */
    private function filtrosAntigos(Request $request): array
    {
        return ['loja' => $request->string('site')->toString(), 'q' => $request->string('search')->toString()];
    }

    private function comFiltros(array $base, array $extra): string
    {
        $query = http_build_query(array_filter([...$base, ...$extra], fn ($v) => $v !== null && $v !== ''));

        return '/admin/leads'.($query !== '' ? '?'.$query : '');
    }

    private function linhaOu404(LeadsUnificados $unificados, string $chave): PessoaLead
    {
        $linha = $unificados->achar($chave);
        abort_if($linha === null, 404);

        return $linha;
    }

    /** @return array<string, mixed> */
    private function linha(PessoaLead $l, ?array $parou): array
    {
        $situacao = $l->situacao;
        $pedido = $l->pedido;
        $codigo = $situacao['codigo'];
        $detalhe = match ($codigo) {
            'pago' => 'pedido pago',
            'aguardando' => 'pedido gerado, sem pagamento',
            default => $parou['texto'] ?? null,
        };
        if ($codigo === 'parou' && $situacao['motivo']) {
            $detalhe = $situacao['motivo'].($detalhe ? ' · '.$detalhe : '');
        }

        return [
            'chave' => $l->chave(),
            'site' => $l->site(),
            'nome' => $l->nome(),
            'email' => $l->email(),
            'telefone' => $l->telefone(),
            // Os outros números da pessoa (todos na janela Jornada, com a origem).
            'telefones_extra' => max(0, count($l->telefones()) - 1),
            'sessoes' => count($l->sessoes),
            'itens' => collect($l->itens())->map(fn ($i) => [
                'nome' => (string) ($i['nome'] ?? ''),
                'variacao' => $i['variacao'] ?? null,
                'quantidade' => (int) ($i['quantidade'] ?? 1),
                'subtotal' => round((float) ($i['subtotal'] ?? 0), 2),
            ])->values(),
            'total' => $l->total(),
            'situacao' => [...$situacao, 'detalhe' => $detalhe, 'etapa' => $parou['etapa'] ?? null],
            'pedido' => $pedido ? ['id' => $pedido->id, 'numero' => $pedido->external_order_id] : null,
            'pedido_id' => $pedido?->id,
            'proximo_contato' => $l->proximoContato()?->toIso8601String(),
            'disparos' => $l->registro ? (int) $l->registro->disparos_enviados : null,
            'total_disparos' => CalendarioSequencia::TOTAL,
            'ultima_atividade' => $l->ultimaAtividade()?->toIso8601String(),
            'origem' => collect($l->origem())->only(['origem', 'utm_source', 'utm_campaign'])->filter()->implode(' · ') ?: null,
            'pode_antecipar' => $l->podeAntecipar,
            'tem_sequencia' => $l->registro !== null,
            'chaves' => $l->chaves(),
            'fontes' => array_map(fn (LinhaLead $s) => ['lead' => $s->lead?->id, 'carrinho' => $s->checkout?->id, 'sequencia' => $s->registro?->id], $l->sessoes),
        ];
    }
}
