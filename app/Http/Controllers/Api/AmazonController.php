<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Amazon\AmazonAuthService;
use App\Services\Amazon\Exceptions\AmazonException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AmazonController extends Controller
{
    public function __construct(private readonly AmazonAuthService $auth) {}

    public function redirectToAuth(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('amazon_oauth_state', $state);

        return redirect()->away($this->auth->getAuthorizationUrl($state));
    }

    /**
     * A Amazon manda `spapi_oauth_code`+`state`+`selling_partner_id` (e
     * `mws_auth_token`, legado, ignorado). `state` confere contra o que foi
     * salvo antes de redirecionar — a Amazon não tem PKCE, esse é o único
     * mecanismo de proteção CSRF do fluxo.
     */
    public function callback(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'spapi_oauth_code' => ['required', 'string'],
            'state' => ['required', 'string'],
            'selling_partner_id' => ['nullable', 'string'],
        ]);

        $expectedState = $request->session()->pull('amazon_oauth_state');

        if (! $expectedState || ! hash_equals($expectedState, $validated['state'])) {
            return redirect('/admin/integracoes')->with('error', 'Estado inválido na autorização da Amazon — tente conectar novamente.');
        }

        try {
            $this->auth->handleCallback($validated['spapi_oauth_code'], $validated['selling_partner_id'] ?? null);
        } catch (AmazonException $exception) {
            return redirect('/admin/integracoes')->with('error', $exception->getMessage());
        }

        return redirect('/admin/integracoes')->with('success', 'Conta da Amazon conectada com sucesso.');
    }
}
