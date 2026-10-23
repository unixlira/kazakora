<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Catalog\Support\ModalAvaliacao;
use App\Modules\Inventory\Support\LowStockAlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class AuthenticatedSessionController extends Controller
{
    /** Endereços da loja onde o nginx devolve 404 para /admin (sites-enabled/kazakora). */
    private const HOSTS_SEM_PAINEL = ['kazakora.com', 'www.kazakora.com'];

    /** Onde o painel mora (mesmo endereço do link Painel Admin da loja). */
    private const PAINEL_URL = 'https://painel.kazakora.com';

    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => true,
        ]);
    }

    public function store(LoginRequest $request, LowStockAlertService $lowStockAlert): HttpResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();
        $isAdmin = $user instanceof User && $user->role === User::ROLE_ADMIN;

        // Correção 2026-10-22: em kazakora.com o nginx bloqueia /admin (404),
        // o painel mora em painel.kazakora.com. Admin que entra pela loja vai
        // para o painel já logado: a sessão é presa ao endereço
        // (SESSION_DOMAIN=null), então ele leva um link assinado de uso único
        // que abre a sessão lá (entrarNoPainel).
        $painelNesteEndereco = ! in_array($request->getHost(), self::HOSTS_SEM_PAINEL, true);

        if ($isAdmin && ! $painelNesteEndereco) {
            $request->session()->forget('url.intended');

            $link = URL::temporarySignedRoute('entrar.painel', now()->addMinute(), ['user' => $user->id], absolute: false);

            // Login é POST do Inertia: endereço de outro domínio só com location().
            return Inertia::location(self::PAINEL_URL.$link);
        }

        // Modal "Avalie sua compra" (2026-10-23): só cliente, na loja.
        if (! $isAdmin) {
            ModalAvaliacao::ativar($request->session());
        }

        if ($isAdmin) {
            $this->alertarEstoqueBaixo($request, $user, $lowStockAlert);
        }

        // Correção 2026-08-20: o modal de estoque baixo só existe dentro do
        // AdminLayout agora (antes vazava pra loja também). Sem URL
        // "intended" salva, admin cai direto no painel — senão o flash
        // de estoque baixo nunca teria layout pra aparecer.
        $default = $isAdmin ? route('admin.painel') : route('catalogo.inicio');

        return redirect()->intended($default);
    }

    /**
     * Abre no painel a sessão do admin que entrou pela loja. Link assinado,
     * vale 1 minuto e uma vez só.
     */
    public function entrarNoPainel(Request $request, User $user, LowStockAlertService $lowStockAlert): RedirectResponse
    {
        $usado = ! Cache::add('entrar-painel:'.hash('sha256', (string) $request->query('signature')), true, now()->addMinutes(2));

        abort_if($usado || $user->role !== User::ROLE_ADMIN, 403);

        if (! Auth::check() || Auth::id() !== $user->id) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $this->alertarEstoqueBaixo($request, $user, $lowStockAlert);
        }

        return redirect()->route('admin.painel');
    }

    /**
     * Pedido explícito 2026-08-16: alerta de estoque baixo em modal "toda vez
     * que logar" — só pra admin (mesma regra de quem já recebe
     * OversellDetectedNotification/etc, ver HandleInertiaRequests
     * ::ADMIN_ONLY_NOTIFICATION_TYPES). Flash de sessão: aparece uma vez por
     * login. Roda no endereço onde o painel abre, senão o flash se perde.
     */
    private function alertarEstoqueBaixo(Request $request, User $user, LowStockAlertService $lowStockAlert): void
    {
        $lowStockProducts = $lowStockAlert->checkAndNotify($user);

        if ($lowStockProducts !== []) {
            $request->session()->flash('lowStockProducts', $lowStockProducts);
        }
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('catalogo.inicio');
    }
}
