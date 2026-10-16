<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Financeiro\Support\AlertaColetaFinanceira;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Os agentes da coleta financeira (TikTok e Amazon, na Cora Box) avisam aqui
 * quando não conseguem tirar os dados: resposta com erro, sessão caída,
 * tela mudou. A Manuela manda o motivo ao WhatsApp do Lira (2026-10-16).
 * POST /api/financeiro/coletas/falha com Bearer do agente (TikTok ou Amazon).
 */
class ColetaFinanceiraController extends Controller
{
    private const CANAIS = ['tiktok' => 'TikTok', 'amazon' => 'Amazon'];

    public function falha(Request $request, AlertaColetaFinanceira $alerta): JsonResponse
    {
        $recebido = (string) Str::after((string) $request->header('Authorization'), 'Bearer ');
        $tokens = array_filter([config('services.tiktok_reports.token'), config('services.amazon_reports.token')], 'filled');

        if ($recebido === '' || ! collect($tokens)->contains(fn ($t) => hash_equals((string) $t, $recebido))) {
            return response()->json(['ok' => false, 'erro' => 'Token inválido.'], 401);
        }

        $dados = $request->validate([
            'canal' => ['required', 'string', 'in:'.implode(',', array_keys(self::CANAIS))],
            'motivo' => ['required', 'string', 'max:500'],
        ]);

        $status = $alerta->avisar(self::CANAIS[$dados['canal']], $dados['motivo']);

        return response()->json(['ok' => true, 'whatsapp' => $status]);
    }
}
