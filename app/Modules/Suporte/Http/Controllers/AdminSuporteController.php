<?php

namespace App\Modules\Suporte\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Suporte\Jobs\ResponderComManuela;
use App\Modules\Suporte\Models\Chamado;
use App\Modules\Suporte\Models\MensagemSuporte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin > Suporte > Chamados (pedido 2026-10-11): lista e conversa no jeito
 * da caixa do WhatsApp. Com "Atendimento humano" ligado a Manuela para e o
 * atendente responde; desligado, a Manuela volta a responder.
 */
class AdminSuporteController extends Controller
{
    public function index(Request $request): Response
    {
        $filtro = $request->query('status', 'ativos');

        $chamados = Chamado::query()
            ->with(['user:id,name,email', 'order:id,external_order_id,total,status'])
            ->when($filtro === 'ativos', fn ($q) => $q->whereIn('status', Chamado::ATIVOS))
            ->when(in_array($filtro, Chamado::STATUS, true), fn ($q) => $q->where('status', $filtro))
            ->when($request->query('busca'), fn ($q, $b) => $q->where(fn ($w) => $w
                ->where('numero', 'like', "%{$b}%")->orWhere('assunto', 'like', "%{$b}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$b}%")->orWhere('email', 'like', "%{$b}%"))
                ->orWhereHas('order', fn ($o) => $o->where('external_order_id', 'like', "%{$b}%"))))
            ->orderByDesc('ultima_mensagem_em')->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (Chamado $c) => $this->linha($c));

        return Inertia::render('Admin/Suporte/Index', [
            'chamados' => $chamados,
            'filtro' => $filtro,
            'busca' => (string) $request->query('busca', ''),
            'contagem' => Chamado::query()->selectRaw('status, count(*) total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function mensagens(Chamado $chamado): JsonResponse
    {
        $chamado->forceFill(['nao_lidas_admin' => 0])->save();

        return response()->json([
            'chamado' => $this->linha($chamado->load(['user:id,name,email,phone', 'order:id,external_order_id,total,status,created_at'])),
            'mensagens' => $chamado->mensagens()->get()->map->paraTela()->values(),
        ]);
    }

    public function enviar(Request $request, Chamado $chamado): JsonResponse
    {
        abort_unless($chamado->humano_ativo, 422, 'Ligue o atendimento humano para responder.');

        $dados = $request->validate(['mensagem' => ['required', 'string', 'max:2000']]);
        $chamado->registrar(MensagemSuporte::ATENDENTE, trim($dados['mensagem']), $request->user()->id);

        if ($chamado->status !== Chamado::EM_ATENDIMENTO) {
            $chamado->forceFill(['status' => Chamado::EM_ATENDIMENTO, 'aberto_em' => $chamado->aberto_em ?? now()])->save();
        }

        return $this->mensagens($chamado);
    }

    public function humano(Request $request, Chamado $chamado): JsonResponse
    {
        $ligar = ! $chamado->humano_ativo;
        $chamado->forceFill(['humano_ativo' => $ligar])->save();
        $chamado->registrar(MensagemSuporte::SISTEMA, $ligar
            ? 'Um atendente da KazaKora entrou na conversa.'
            : 'A Manuela voltou a te atender por aqui.');

        // Desligou o humano e a última mensagem é do cliente: a Manuela responde.
        if (! $ligar && $chamado->mensagens()->latest('id')->where('autor', '!=', MensagemSuporte::SISTEMA)->value('autor') === MensagemSuporte::CLIENTE) {
            ResponderComManuela::dispatch($chamado->id);
        }

        return $this->mensagens($chamado);
    }

    public function atualizar(Request $request, Chamado $chamado): JsonResponse
    {
        $dados = $request->validate([
            'status' => ['sometimes', Rule::in(Chamado::STATUS)],
            'prioridade' => ['sometimes', Rule::in(Chamado::PRIORIDADES)],
            'assunto' => ['sometimes', 'nullable', 'string', 'max:160'],
        ]);

        if (isset($dados['status']) && $dados['status'] !== $chamado->status) {
            $dados['fechado_em'] = in_array($dados['status'], [Chamado::RESOLVIDO, Chamado::FECHADO], true) ? now() : null;
            $chamado->registrar(MensagemSuporte::SISTEMA, 'Status do chamado: '.str_replace('_', ' ', $dados['status']).'.');
        }

        $chamado->forceFill($dados)->save();

        return $this->mensagens($chamado);
    }

    /** Chamado criado pelo atendente (ex.: cliente ligou ou mandou WhatsApp). */
    public function criar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email', Rule::exists('users', 'email')],
            'pedido' => ['nullable', 'string', 'max:30'],
            'assunto' => ['required', 'string', 'max:160'],
            'mensagem' => ['required', 'string', 'max:2000'],
            'prioridade' => ['required', Rule::in(Chamado::PRIORIDADES)],
        ], [], ['email' => 'e-mail do cliente']);

        $user = User::query()->where('email', $dados['email'])->firstOrFail();
        $order = filled($dados['pedido'] ?? null)
            ? Order::query()->where('user_id', $user->id)->where(fn ($q) => $q->where('external_order_id', strtoupper(trim($dados['pedido'])))->orWhere('id', (int) $dados['pedido']))->first()
            : null;

        $chamado = Chamado::create([
            'numero' => Chamado::gerarNumero(),
            'user_id' => $user->id,
            'order_id' => $order?->id,
            'assunto' => $dados['assunto'],
            'prioridade' => $dados['prioridade'],
            'status' => Chamado::EM_ATENDIMENTO,
            'humano_ativo' => true,
            'aberto_por' => 'atendente',
            'aberto_em' => now(),
        ]);
        $chamado->registrar(MensagemSuporte::ATENDENTE, $dados['mensagem'], $request->user()->id);

        return redirect()->route('admin.suporte.chamados', ['c' => $chamado->id])->with('success', "Chamado {$chamado->numero} criado.");
    }

    public function excluir(Chamado $chamado): JsonResponse
    {
        $chamado->delete();

        return response()->json(['ok' => true]);
    }

    private function linha(Chamado $c): array
    {
        return $c->paraTela() + [
            'cliente' => $c->user ? ['nome' => $c->user->name, 'email' => $c->user->email, 'telefone' => $c->user->phone ?? null] : null,
            'pedido' => $c->order ? ['id' => $c->order->id, 'numero' => $c->order->numero, 'total' => (float) $c->order->total, 'status' => $c->order->status] : null,
            'previa' => \Illuminate\Support\Str::limit((string) $c->mensagens()->latest('id')->value('conteudo'), 90),
        ];
    }
}
