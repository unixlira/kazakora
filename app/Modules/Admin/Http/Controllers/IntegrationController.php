<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesShopeeAuthorizationLanding;
use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\ShopeeProductImportService;
use App\Services\Amazon\AmazonAuthService;
use App\Services\Amazon\Exceptions\AmazonException;
use App\Services\MercadoLivre\MercadoLivreAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class IntegrationController extends Controller
{
    use HandlesShopeeAuthorizationLanding;

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
        MarketplaceAccount::CHANNEL_AMAZON => [
            'name' => 'Amazon',
            'description' => 'Importação de pedidos, envio da nota fiscal (POST_INVOICE_CONFIRMATION) e etiqueta via Fulfillment por Comerciante. Publicação de produto ainda não implementada.',
            'icon' => 'fab fa-amazon',
            'connectHref' => '/api/amazon/auth',
            // Apps privados (não publicados) não usam o redirect OAuth — o
            // vendedor gera o refresh token direto no Seller Central e cola
            // aqui (ver connectAmazon() abaixo).
            'manualConnect' => true,
            'available' => true,
        ],
    ];

    public function __construct(
        private readonly MercadoLivreAuthService $mercadoLivreAuth,
        private readonly AmazonAuthService $amazonAuth,
    ) {}

    /**
     * Um dos 3 destinos plausíveis do redirect de autorização "Seller In
     * House" da Shopee — ver HandlesShopeeAuthorizationLanding.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->shopeeAuthorizationLandingRedirect($request)) {
            return $redirect;
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
                'manualConnect' => $meta['manualConnect'] ?? false,
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

        if ($channel === MarketplaceAccount::CHANNEL_AMAZON) {
            // Sem endpoint de revogação usado aqui (mesma situação do
            // Mercado Livre/Shopee) — desconectar para de usar o token
            // localmente. O refresh token continua válido do lado da
            // Amazon até o vendedor revogar direto no Seller Central.
            MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_AMAZON)->update([
                'status' => MarketplaceAccount::STATUS_DISCONNECTED,
                'access_token' => null,
                'refresh_token' => null,
                'token_expires_at' => null,
            ]);

            return back()->with('success', 'Amazon desconectada.');
        }

        return back()->with('error', 'Esse canal ainda não está disponível.');
    }

    /**
     * Self-authorization: apps SP-API privados (não publicados na loja de
     * apps) deixam o próprio vendedor gerar um refresh token direto no
     * Seller Central (Partner Network > Develop Apps > Autorizar), sem
     * precisar do redirect OAuth completo (ver AmazonController, usado só
     * se/quando o app for publicado). AmazonAuthService::connectWithRefreshToken()
     * valida o token contra a API de verdade antes de persistir.
     */
    public function connectAmazon(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'refresh_token' => ['required', 'string'],
            'seller_id' => ['nullable', 'string'],
        ]);

        try {
            $this->amazonAuth->connectWithRefreshToken($validated['refresh_token'], $validated['seller_id'] ?? null);
        } catch (AmazonException $exception) {
            return back()->with('error', 'Não foi possível conectar a Amazon: '.$exception->getMessage());
        }

        return back()->with('success', 'Conta da Amazon conectada com sucesso.');
    }

    /**
     * Vincula anúncios já publicados na Shopee (feitos direto por lá, fora
     * do Kazakora) a produtos locais existentes, por similaridade de nome
     * — ver ShopeeProductImportService. Roda síncrono (a lista de itens da
     * loja não é grande o bastante pra justificar fila) e devolve o
     * resultado via flash, mesmo padrão das outras ações desta página.
     */
    public function importShopeeProducts(ShopeeProductImportService $service): RedirectResponse
    {
        try {
            $result = $service->import();
        } catch (Throwable $exception) {
            return back()->with('error', 'Erro ao importar produtos da Shopee: '.$exception->getMessage());
        }

        $message = "{$result['linked']} produto(s) vinculado(s) automaticamente.";

        if ($result['already_linked'] > 0) {
            $message .= " {$result['already_linked']} já estava(m) vinculado(s).";
        }

        if ($result['unmatched']) {
            $count = count($result['unmatched']);
            $names = implode(', ', array_slice($result['unmatched'], 0, 5));
            $message .= " {$count} sem correspondência confiável, precisam de vínculo manual: {$names}".($count > 5 ? '...' : '.');

            return back()->with('warning', $message);
        }

        return back()->with('success', $message);
    }
}
