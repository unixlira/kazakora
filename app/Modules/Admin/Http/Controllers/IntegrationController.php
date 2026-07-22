<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\MercadoLivre\MercadoLivreAuthService;
use Illuminate\Http\RedirectResponse;
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
            'description' => 'Publique produtos e sincronize estoque e pedidos.',
            'icon' => 'fas fa-bag-shopping',
            'connectHref' => null,
            'available' => false,
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

    public function index(): Response
    {
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

        return back()->with('error', 'Esse canal ainda não está disponível.');
    }
}
