<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\EquipeComando;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Equipe (pedido do Lira 2026-10-20): visão geral dos agentes, a página de
 * cada um com as skills (config/equipe.php) e os comandos extras que o
 * Lira acrescenta para turbinar a skill (tabela equipe_comandos).
 */
class EquipeController extends Controller
{
    public function index(): Response
    {
        $ativos = EquipeComando::query()->ativos()
            ->selectRaw('membro, count(*) as total')
            ->groupBy('membro')
            ->pluck('total', 'membro');

        return Inertia::render('Admin/Equipe/Index', [
            'membros' => collect(EquipeComando::membros())
                ->map(fn (array $m, string $slug) => self::resumo($slug, $m) + ['comandosAtivos' => (int) ($ativos[$slug] ?? 0)])
                ->values(),
        ]);
    }

    public function show(string $membro): Response
    {
        abort_unless(EquipeComando::membroExiste($membro), 404);
        $dados = EquipeComando::membros()[$membro];

        return Inertia::render('Admin/Equipe/Membro', [
            'membro' => self::resumo($membro, $dados) + [
                'skills' => array_values($dados['skills'] ?? []),
                'limites' => $dados['limites'] ?? null,
            ],
            'comandos' => EquipeComando::query()->with('user:id,name')
                ->where('membro', $membro)
                ->orderByDesc('ativo')->latest('id')
                ->get()->map->paraTela(),
        ]);
    }

    public function store(Request $request, string $membro): RedirectResponse
    {
        abort_unless(EquipeComando::membroExiste($membro), 404);
        $dados = $request->validate([
            'texto' => ['required', 'string', 'max:2000'],
        ], [
            'texto.required' => 'Escreva o comando antes de adicionar.',
            'texto.max' => 'O comando pode ter até 2000 caracteres.',
        ]);

        EquipeComando::query()->create([
            'membro' => $membro,
            'texto' => trim($dados['texto']),
            'ativo' => true,
            'user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'Comando adicionado.');
    }

    public function update(Request $request, string $membro, EquipeComando $comando): RedirectResponse
    {
        abort_unless(EquipeComando::membroExiste($membro) && $comando->membro === $membro, 404);
        $dados = $request->validate(['ativo' => ['required', 'boolean']]);

        $comando->update(['ativo' => (bool) $dados['ativo']]);

        return back()->with('success', $comando->ativo ? 'Comando reativado.' : 'Comando desativado.');
    }

    /** @return array<string, mixed> */
    private static function resumo(string $slug, array $m): array
    {
        return [
            'slug' => $slug,
            'nome' => $m['nome'],
            'inicial' => mb_substr($m['nome'], 0, 1),
            'cargo' => $m['cargo'],
            'cor' => $m['cor'],
            'icone' => $m['icone'],
            'descricao' => $m['descricao'],
        ];
    }
}
