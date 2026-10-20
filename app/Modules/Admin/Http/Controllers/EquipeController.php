<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Equipes\Models\Squad;
use App\Modules\Equipes\Models\SquadInstrucao;
use App\Modules\Equipes\Models\SquadMembro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Equipes de agentes de IA (menu Equipes › <squad>, pedido do Lira
 * 2026-10-20, validado com o Jonathan). Tela da squad com os membros em
 * cards e painel lateral; cadastro e edição em tela própria. As instruções
 * extras salvas aqui saem pela API (EquipeInstrucoesController) e o agente
 * passa a seguir na próxima tarefa. Nada é apagado de verdade.
 */
class EquipeController extends Controller
{
    public function inicio(): RedirectResponse
    {
        $squad = Squad::query()->where('ativa', true)->orderBy('nome')->first();
        abort_unless($squad, 404);

        return redirect("/admin/equipes/{$squad->slug}");
    }

    public function show(Squad $squad): Response
    {
        $membros = $squad->membros()
            ->with(['respondePara:id,nome', 'instrucoes.autor:id,name'])
            ->get();

        return Inertia::render('Admin/Equipes/Show', [
            'squad' => ['nome' => $squad->nome, 'slug' => $squad->slug],
            'membros' => $membros->map(fn (SquadMembro $m) => $this->membroParaTela($m))->values(),
            'areas' => $this->areas(),
            'precisaOkOpcoes' => SquadMembro::PRECISA_OK,
        ]);
    }

    public function create(Squad $squad): Response
    {
        return Inertia::render('Admin/Equipes/Form', $this->dadosDoFormulario($squad, null));
    }

    public function store(Request $request, Squad $squad): RedirectResponse
    {
        $dados = $this->validar($request, $squad, null);
        $membro = new SquadMembro($dados);
        $membro->squad_id = $squad->id;
        $membro->ordem = (int) $squad->membros()->max('ordem') + 1;
        $this->guardarFoto($request, $membro);
        $membro->save();

        return redirect("/admin/equipes/{$squad->slug}?membro={$membro->slug}")
            ->with('success', "{$membro->nome} entrou na {$squad->nome}.");
    }

    public function edit(Squad $squad, SquadMembro $membro): Response
    {
        return Inertia::render('Admin/Equipes/Form', $this->dadosDoFormulario($squad, $membro));
    }

    public function update(Request $request, Squad $squad, SquadMembro $membro): RedirectResponse
    {
        $dados = $this->validar($request, $squad, $membro);
        $membro->fill($dados);
        $this->guardarFoto($request, $membro);
        $membro->save();

        return redirect("/admin/equipes/{$squad->slug}?membro={$membro->slug}")
            ->with('success', "{$membro->nome} atualizado.");
    }

    public function status(Request $request, Squad $squad, SquadMembro $membro): RedirectResponse
    {
        $dados = $request->validate(['status' => ['required', Rule::in(SquadMembro::STATUS)]]);
        $membro->update(['status' => $dados['status']]);

        $msg = match ($dados['status']) {
            SquadMembro::STATUS_PAUSADO => "{$membro->nome} pausado. Ele não pega tarefa até ser retomado.",
            SquadMembro::STATUS_DESATIVADO => "{$membro->nome} desativado. Dá para reativar depois.",
            default => "{$membro->nome} está ativo.",
        };

        return back()->with('success', $msg);
    }

    public function storeInstrucao(Request $request, Squad $squad, SquadMembro $membro): RedirectResponse
    {
        $dados = $request->validate(['texto' => ['required', 'string', 'min:5', 'max:2000']]);

        $membro->instrucoes()->create([
            'texto' => trim($dados['texto']),
            'ativa' => true,
            'criada_por' => $request->user()->id,
        ]);

        return back()->with('success', "Instrução salva. O {$membro->nome} usa a partir da próxima tarefa.");
    }

    public function alternarInstrucao(Request $request, Squad $squad, SquadMembro $membro, SquadInstrucao $instrucao): RedirectResponse
    {
        abort_unless($instrucao->membro_id === $membro->id, 404);
        $dados = $request->validate(['ativa' => ['required', 'boolean']]);
        $instrucao->update(['ativa' => (bool) $dados['ativa']]);

        return back()->with('success', $instrucao->ativa
            ? "Instrução ligada. O {$membro->nome} volta a seguir na próxima tarefa."
            : "Instrução desligada. O {$membro->nome} deixa de seguir na próxima tarefa.");
    }

    public function destroyInstrucao(Squad $squad, SquadMembro $membro, SquadInstrucao $instrucao): RedirectResponse
    {
        abort_unless($instrucao->membro_id === $membro->id, 404);
        $instrucao->delete();

        return back()->with('success', 'Instrução excluída.');
    }

    private function membroParaTela(SquadMembro $m): array
    {
        return [
            'id' => $m->id,
            'nome' => $m->nome,
            'slug' => $m->slug,
            'funcao' => $m->funcao,
            'area' => $m->area,
            'area_rotulo' => SquadMembro::AREAS[$m->area]['rotulo'] ?? 'Outra',
            'cor' => $m->corDaArea(),
            'foto' => $m->fotoUrl(),
            'o_que_faz' => $m->o_que_faz,
            'skills' => array_values($m->skills ?? []),
            'responde_para' => $m->respondePara?->nome ?? 'Homer',
            'precisa_ok' => array_values($m->precisa_ok ?? []),
            'status' => $m->status,
            'instrucoes' => $m->instrucoes->map(fn (SquadInstrucao $i) => [
                'id' => $i->id,
                'texto' => $i->texto,
                'ativa' => $i->ativa,
                'criada_em' => $i->created_at?->toIso8601String(),
                'autor' => $i->autor?->name,
            ])->values(),
            'instrucoes_ativas' => $m->instrucoes->where('ativa', true)->count(),
        ];
    }

    private function areas(): array
    {
        return collect(SquadMembro::AREAS)->map(fn ($a, $chave) => ['valor' => $chave] + $a)->values()->all();
    }

    private function dadosDoFormulario(Squad $squad, ?SquadMembro $membro): array
    {
        return [
            'squad' => ['nome' => $squad->nome, 'slug' => $squad->slug],
            'membro' => $membro ? [
                'id' => $membro->id,
                'nome' => $membro->nome,
                'slug' => $membro->slug,
                'funcao' => $membro->funcao,
                'area' => $membro->area,
                'o_que_faz' => $membro->o_que_faz,
                'skills' => array_values($membro->skills ?? []),
                'responde_para_id' => $membro->responde_para_id,
                'precisa_ok' => array_values($membro->precisa_ok ?? []),
                'status' => $membro->status,
                'cor' => $membro->cor,
                'foto' => $membro->fotoUrl(),
            ] : null,
            'outrosMembros' => $squad->membros()
                ->when($membro, fn ($q) => $q->whereKeyNot($membro->id))
                ->where('status', '!=', SquadMembro::STATUS_DESATIVADO)
                ->get(['id', 'nome', 'funcao'])
                ->map(fn ($m) => ['id' => $m->id, 'nome' => $m->nome, 'funcao' => $m->funcao])->values(),
            'areas' => $this->areas(),
            'precisaOkOpcoes' => SquadMembro::PRECISA_OK,
        ];
    }

    private function validar(Request $request, Squad $squad, ?SquadMembro $membro): array
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:80'],
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('squad_membros', 'slug')->ignore($membro?->id)],
            'funcao' => ['required', 'string', 'max:160'],
            'area' => ['required', Rule::in(array_keys(SquadMembro::AREAS))],
            'o_que_faz' => ['nullable', 'string', 'max:2000'],
            'skills' => ['nullable', 'array', 'max:30'],
            'skills.*.nome' => ['nullable', 'string', 'max:80'],
            'skills.*.descricao' => ['nullable', 'string', 'max:200'],
            'responde_para_id' => ['nullable', 'integer', Rule::exists('squad_membros', 'id')->where('squad_id', $squad->id)],
            'precisa_ok' => ['nullable', 'array'],
            'precisa_ok.*' => [Rule::in(array_keys(SquadMembro::PRECISA_OK))],
            'status' => ['required', Rule::in([SquadMembro::STATUS_ATIVO, SquadMembro::STATUS_PAUSADO, SquadMembro::STATUS_DESATIVADO])],
            'cor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'foto' => ['nullable', 'image', 'max:2048'],
            'remover_foto' => ['nullable', 'boolean'],
        ], [
            'slug.regex' => 'Use só letras minúsculas, números e hífen (ex.: caue-shopee).',
            'slug.unique' => 'Já existe um membro com esse identificador.',
        ]);

        if ($membro && (int) ($dados['responde_para_id'] ?? 0) === $membro->id) {
            $dados['responde_para_id'] = null;
        }

        $dados['skills'] = collect($dados['skills'] ?? [])
            ->map(fn ($s) => ['nome' => trim((string) ($s['nome'] ?? '')), 'descricao' => trim((string) ($s['descricao'] ?? ''))])
            ->filter(fn ($s) => $s['nome'] !== '')->values()->all();
        $dados['precisa_ok'] = array_values(array_unique($dados['precisa_ok'] ?? []));

        unset($dados['foto'], $dados['remover_foto']);

        return $dados;
    }

    private function guardarFoto(Request $request, SquadMembro $membro): void
    {
        if ($request->hasFile('foto')) {
            $membro->foto_path = $request->file('foto')->store('equipes', 'public');
        } elseif ($request->boolean('remover_foto')) {
            // O arquivo antigo fica no disco (nada é apagado de verdade); só sai do cadastro.
            $membro->foto_path = null;
        }
    }
}
