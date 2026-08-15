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
        // Achado real 2026-08-15: até aqui o card só mostrava o formulário
        // de colar refresh token manual (self-authorization, ver
        // connectAmazon() abaixo) — mas AMAZON_AUTH_DRAFT_MODE já está
        // false em produção (app publicado), então o redirect OAuth padrão
        // funciona igual ao Mercado Livre/Shopee: connectHref sozinho já
        // basta, sem formulário nenhum. Pedido explícito do usuário: "só
        // clica no botão, a URL completa é montada" — é exatamente o que
        // AmazonController::redirectToAuth() já fazia, só não estava
        // exposto porque 'manualConnect' desviava pro formulário.
        MarketplaceAccount::CHANNEL_AMAZON => [
            'name' => 'Amazon',
            'description' => 'Importação de pedidos e etiqueta via Fulfillment por Comerciante (MFN). Envio de nota fiscal e publicação de produto ainda não implementados — endpoint SP-API real pra NF-e de pedido não-FBA não confirmado na documentação pública.',
            'icon' => 'fab fa-amazon',
            'connectHref' => '/api/amazon/auth',
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
     * Self-authorization: rota de reserva pra apps SP-API ainda em Draft
     * (não publicados), onde o vendedor gera um refresh token direto no
     * Seller Central (Partner Network > Develop Apps > Autorizar) e cola
     * na mão, sem o redirect OAuth completo. Achado real 2026-08-15: o
     * card não usa mais este caminho por padrão (AMAZON_AUTH_DRAFT_MODE já
     * é false em produção, o redirect OAuth de AmazonController funciona
     * normal, mesmo padrão do Mercado Livre/Shopee) — este endpoint fica
     * só como via manual de emergência (chamado direto via POST), não tem
     * botão nenhum na UI apontando pra cá. AmazonAuthService::
     * connectWithRefreshToken() valida o token contra a API de verdade
     * antes de persistir.
     */
    public function connectAmazon(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'refresh_token' => ['nullable', 'string'],
            'seller_id' => ['nullable', 'string'],
        ]);

        $refreshToken = filled($validated['refresh_token'] ?? null)
            ? $validated['refresh_token']
            : config('services.amazon.bootstrap_refresh_token');

        $sellerId = filled($validated['seller_id'] ?? null)
            ? $validated['seller_id']
            : config('services.amazon.bootstrap_seller_id');

        if (! filled($refreshToken)) {
            return back()->with('error', 'Informe o refresh token da Amazon (nenhum encontrado no .env nem no formulário).');
        }

        try {
            $this->amazonAuth->connectWithRefreshToken($refreshToken, $sellerId);
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
