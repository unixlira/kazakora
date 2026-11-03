<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Controllers\ShippingMethodController;
use App\Modules\Checkout\Support\TabelaFreteLocalidades;
use App\Services\MelhorEnvio\Exceptions\MelhorEnvioException;
use App\Services\MelhorEnvio\MelhorEnvioAuthService;
use App\Support\FreteProvedor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MelhorEnvioController extends Controller
{
    public function __construct(private readonly MelhorEnvioAuthService $auth) {}

    public function redirectToAuth(): RedirectResponse
    {
        return redirect()->away($this->auth->getAuthorizationUrl());
    }

    public function callback(Request $request, TabelaFreteLocalidades $tabela): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        try {
            $token = $this->auth->handleCallback($validated['code'], $validated['state']);
        } catch (MelhorEnvioException $exception) {
            return redirect('/admin/logistica')->with('error', $exception->getMessage());
        }

        // Veio do "Conectar" de Logística (2026-11-03): conecta o Melhor
        // Envio como provedor do frete e desconecta os Correios.
        if ($request->session()->pull('frete.conectar_melhor_envio')) {
            return redirect('/admin/logistica')->with('success', ShippingMethodController::ativar(FreteProvedor::MELHOR_ENVIO, $tabela));
        }

        return redirect('/admin/logistica')->with(
            'success',
            "Conta do Melhor Envio \"{$token->account_label}\" conectada com sucesso.",
        );
    }
}
