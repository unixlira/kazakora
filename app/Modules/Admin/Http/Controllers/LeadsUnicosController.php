<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\FluxoDoCheckout;
use App\Modules\Admin\Support\Leads\JornadaDoLead;
use App\Modules\Admin\Support\Leads\LeadsUnificados;
use App\Modules\Admin\Support\Leads\LinhaLead;
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
 * sessão (ver LeadsUnificados). Por enquanto em /admin/leads-v2, ao lado das
 * telas antigas, para o Lira comparar antes de trocar a rota.
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

        $paradasLead = $fluxo->paradas($daPagina->map->lead->filter());
        $paradasCheckout = $fluxo->paradas($daPagina->filter(fn (LinhaLead $l) => ! $l->lead && $l->checkout)->map->checkout);

        $paginador = new LengthAwarePaginator(
            $daPagina->map(fn (LinhaLead $l) => $this->linha($l, $l->lead ? ($paradasLead[$l->lead->id] ?? null) : ($l->checkout ? ($paradasCheckout[$l->checkout->id] ?? null) : null))),
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
            'base' => '/admin/leads-v2',
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
        if (! $linha->checkout) {
            return back()->with('error', 'Este lead não chegou na Entrega: não há carrinho para a sequência.');
        }

        $resultado = $recuperacao->enviar($linha->checkout);

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

    private function linhaOu404(LeadsUnificados $unificados, string $chave): LinhaLead
    {
        $linha = $unificados->achar($chave);
        abort_if($linha === null, 404);

        return $linha;
    }

    /** @return array<string, mixed> */
    private function linha(LinhaLead $l, ?array $parou): array
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
            'fontes' => ['lead' => $l->lead?->id, 'carrinho' => $l->checkout?->id, 'sequencia' => $l->registro?->id],
        ];
    }
}
