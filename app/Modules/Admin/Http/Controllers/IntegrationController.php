<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\MercadoLivre\MercadoLivreAuthService;
use App\Services\Shopee\Exceptions\ShopeeException;
use App\Services\Shopee\ShopeeAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationController extends Controller
{
    private const CATALOG = [
        MarketplaceAccount::CHANNEL_MERCADO_LIVRE => [
            'name' => 'Mercado Livre',
            'description' => 'Publique produtos e sincronize estoque e pedidos.',
            'icon' => 'fas fa-store',
            'connectHref' => '/api/mercadolivre/auth',
            'available' => true,
        ],
        MarketplaceAccount::CHANNEL_SHOPEE => [
            'name' => 'Shopee',
            'description' => 'Envio de nota fiscal, confirmação de frete e etiqueta de envio. Publicação de produto ainda não implementada.',
            'icon' => 'fas fa-bag-shopping',
            'connectHref' => '/api/shopee/auth',
            'available' => true,
        ],
        MarketplaceAccount::CHANNEL_TIKTOK_SHOP => [
            'name' => 'TikTok Shop',
            'description' => 'Publique produtos e sincronize estoque e pedidos.',
            'icon' => 'fab fa-tiktok',
            'connectHref' => null,
            'available' => false,
        ],
    ];

    public function __construct(private readonly MercadoLivreAuthService $mercadoLivreAuth) {}

    /**
     * Achado real 2026-08-06/07: o link de autorização "Seller In House" da
     * Shopee (botão Authorize no console deles, não o /api/shopee/auth que
     * a gente gera) usa a "Redirect URL Domain" cadastrada lá — que, ao
     * contrário do que a primeira correção assumiu (ver
     * CatalogController::index()), não é o domínio raiz "/", é
     * literalmente "/admin/integracoes". code/shop_id chegavam aqui e eram
     * silenciosamente ignorados (sem erro nenhum — a página só renderizava
     * normal), então a loja nunca ficava conectada de fato. Mesma lógica
     * de ShopeeController::callback()/CatalogController::index(), só mais
     * um ponto de entrada possível pro mesmo handshake.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->filled('code') && $request->filled('shop_id')) {
            return $this->handleShopeeAuthorizationLanding($request);
        }

        $accounts = MarketplaceAccount::query()->get()->keyBy('channel');
        $mercadoLivreToken = $this->mercadoLivreAuth->currentToken();

        $integrations = collect(self::CATALOG)->map(function (array $meta, string $channel) use ($accounts, $mercadoLivreToken) {
            $account = $accounts->get($channel);

            return [
                'channel' => $channel,
                ...$meta,
                'connected' => $account?->isConnected() ?? false,
                'connectedAt' => $account?->connected_at,
                'accountLabel' => $channel === MarketplaceAccount::CHANNEL_MERCADO_LIVRE ? $mercadoLivreToken?->ml_nickname : $account?->seller_id,
            ];
        })->values();

        return Inertia::render('Admin/Integracoes/Index', [
            'integrations' => $integrations,
        ]);
    }

    public function disconnect(string $channel): RedirectResponse
    {
        if ($channel === MarketplaceAccount::CHANNEL_MERCADO_LIVRE) {
            $token = $this->mercadoLivreAuth->currentToken();

            if ($token) {
                $this->mercadoLivreAuth->revokeToken($token);
            }

            return back()->with('success', 'Mercado Livre desconectado.');
        }

        if ($channel === MarketplaceAccount::CHANNEL_SHOPEE) {
            // Shopee não tem endpoint de revogação nesse fluxo (mesma
            // situação do Mercado Livre) — desconectar aqui significa só
            // parar de usar as credenciais localmente.
            MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->update([
                'status' => MarketplaceAccount::STATUS_DISCONNECTED,
                'access_token' => null,
                'refresh_token' => null,
                'token_expires_at' => null,
            ]);

            return back()->with('success', 'Shopee desconectada.');
        }

        return back()->with('error', 'Esse canal ainda não está disponível.');
    }

    /**
     * Mesma lógica de ShopeeController::callback() —
     * ShopeeAuthService::handleCallback() já é idempotente/self-contained,
     * só o ponto de entrada muda (aqui em vez de /api/shopee/callback).
     */
    private function handleShopeeAuthorizationLanding(Request $request): RedirectResponse
    {
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
