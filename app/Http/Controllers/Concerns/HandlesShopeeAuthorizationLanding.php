<?php

namespace App\Http\Controllers\Concerns;

use App\Services\Shopee\Exceptions\ShopeeException;
use App\Services\Shopee\ShopeeAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Apps "Seller In House" da Shopee são autorizados direto no console deles
 * (botão "Authorize"), não pelo link /auth que a gente gera — e esse fluxo
 * usa a "Redirect URL Domain" cadastrada lá dentro, um campo manual sem
 * acesso via API pra confirmar/alterar daqui. Já vimos esse campo apontar
 * pra 3 páginas diferentes ao longo de tentativas de configuração
 * (raiz "/", /admin/integracoes, /admin/empresa — achados reais 2026-08-06/
 * 07, cada um descoberto só depois que o code/shop_id chegava lá e era
 * silenciosamente ignorado, sem erro nenhum, só a página normal
 * renderizando). Em vez de perseguir qual é o valor certo agora, todo
 * controller que é um destino plausível desse redirect usa este trait —
 * não importa qual dos três a Shopee realmente usar, o handshake completa
 * igual.
 */
trait HandlesShopeeAuthorizationLanding
{
    /**
     * Null quando a requisição não é uma volta de autorização da Shopee —
     * chamador segue o fluxo normal de renderização nesse caso.
     */
    protected function shopeeAuthorizationLandingRedirect(Request $request): ?RedirectResponse
    {
        if (! $request->filled('code') || ! $request->filled('shop_id')) {
            return null;
        }

        $validated = $request->validate([
            'code' => ['required', 'string'],
            'shop_id' => ['required', 'integer'],
        ]);

        try {
            app(ShopeeAuthService::class)->handleCallback($validated['code'], (int) $validated['shop_id']);
        } catch (ShopeeException $exception) {
            return redirect('/admin/integracoes')->with('error', $exception->getMessage());
        }

        return redirect('/admin/integracoes')->with('success', 'Loja da Shopee conectada com sucesso.');
    }
}
