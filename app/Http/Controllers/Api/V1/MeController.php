<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint de autoverificação — pedido explícito 2026-08-21: um parceiro
 * consegue confirmar que o token está válido e quais abilities ele
 * carrega sem precisar tentar-e-errar contra um recurso de verdade.
 */
class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $partner = $request->user();

        return response()->json([
            'id' => $partner->id,
            'name' => $partner->name,
            'abilities' => $partner->currentAccessToken()->abilities ?? [],
            'rate_limit_per_minute' => $partner->rate_limit_per_minute,
        ]);
    }
}
