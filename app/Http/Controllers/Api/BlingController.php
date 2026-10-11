<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Bling\Exceptions\BlingException;
use App\Services\Bling\BlingAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * OAuth2 com o Bling — ponte pro TikTok Shop (ver BlingAuthService/
 * TikTokShopDriver). Mesmo padrão de redirectToAuth/callback já usado por
 * MercadoLivreController/ShopeeController.
 */
class BlingController extends Controller
{
    public function __construct(private readonly BlingAuthService $auth) {}

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
            $this->auth->handleCallback($validated['code'], $validated['state']);
        } catch (BlingException $exception) {
            return redirect('/admin/integracoes')->with('error', $exception->getMessage());
        }

        return redirect('/admin/integracoes')->with(
            'success',
            'Bling conectado com sucesso. Agora informe qual loja do Bling é o TikTok Shop pra começar a importar os pedidos.',
        );
    }
}
