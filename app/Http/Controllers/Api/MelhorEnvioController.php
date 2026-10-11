<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MelhorEnvio\Exceptions\MelhorEnvioException;
use App\Services\MelhorEnvio\MelhorEnvioAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MelhorEnvioController extends Controller
{
    public function __construct(private readonly MelhorEnvioAuthService $auth) {}

    public function redirectToAuth(): RedirectResponse
    {
        return redirect()->away($this->auth->getAuthorizationUrl());
    }

    public function callback(Request $request): RedirectResponse
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

        return redirect('/admin/logistica')->with(
            'success',
            "Conta do Melhor Envio \"{$token->account_label}\" conectada com sucesso.",
        );
    }
}
