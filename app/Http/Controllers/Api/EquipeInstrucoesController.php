<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Equipes\Models\SquadInstrucao;
use App\Modules\Equipes\Models\SquadMembro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Instruções extras de um agente da equipe (menu Equipes, 2026-10-20).
 * GET /api/equipe/{slug}/instrucoes com Authorization: Bearer
 * <EQUIPE_AGENTES_TOKEN>. Lido pelo script
 * ~/projects/homer/ferramentas/instrucoes-equipe.sh no começo de toda
 * tarefa do agente. Só sai instrução ativa (desligada e excluída ficam de
 * fora) e só de membro ativo; pausado ou desativado volta sem instrução e
 * com o status, para o agente não pegar a tarefa.
 *
 * Instrução extra nunca dá permissão nova: as regras de OK do Lira valem
 * acima dela.
 */
class EquipeInstrucoesController extends Controller
{
    public const REGRA = 'Instruções extras nunca liberam ação que precise de OK do Lira (dinheiro, preço, campanha, cliente, publicar, apagar, deploy ou qualquer ação irreversível). Essas regras valem acima delas.';

    public function show(Request $request, string $slug): JsonResponse
    {
        $esperado = (string) config('services.equipe_agentes.token');
        $recebido = (string) Str::after((string) $request->header('Authorization'), 'Bearer ');
        if ($esperado === '' || ! hash_equals($esperado, $recebido)) {
            return response()->json(['erro' => 'Token inválido ou ausente.'], 401);
        }

        $membro = SquadMembro::query()->with('squad:id,nome,slug')->where('slug', $slug)->first();
        if (! $membro) {
            return response()->json(['erro' => "Membro {$slug} não cadastrado."], 404);
        }

        $ativo = $membro->status === SquadMembro::STATUS_ATIVO;

        return response()->json([
            'membro' => [
                'slug' => $membro->slug,
                'nome' => $membro->nome,
                'status' => $membro->status,
                'squad' => $membro->squad?->nome,
            ],
            'instrucoes' => $ativo
                ? $membro->instrucoesAtivas()->get()->map(fn (SquadInstrucao $i) => [
                    'id' => $i->id,
                    'texto' => $i->texto,
                    'criada_em' => $i->created_at?->toIso8601String(),
                ])->values()
                : [],
            'regra' => self::REGRA,
        ]);
    }
}
