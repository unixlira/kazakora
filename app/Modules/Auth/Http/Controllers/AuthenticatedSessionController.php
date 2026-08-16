<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Inventory\Support\LowStockAlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => true,
        ]);
    }

    public function store(LoginRequest $request, LowStockAlertService $lowStockAlert): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Pedido explícito 2026-08-16: alerta de estoque baixo em modal
        // "toda vez que logar" — só pra admin (mesma regra de quem já
        // recebe OversellDetectedNotification/etc, ver HandleInertiaRequests
        // ::ADMIN_ONLY_NOTIFICATION_TYPES), não faz sentido pro cliente
        // comum da loja. Flash de sessão (mesmo padrão de success/error) —
        // some sozinho depois do próximo request, então só aparece uma vez
        // por login, não em toda navegação seguinte.
        $user = $request->user();

        if ($user instanceof User && $user->role === User::ROLE_ADMIN) {
            $lowStockProducts = $lowStockAlert->checkAndNotify($user);

            if ($lowStockProducts !== []) {
                $request->session()->flash('lowStockProducts', $lowStockProducts);
            }
        }

        return redirect()->intended(route('catalogo.inicio'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('catalogo.inicio');
    }
}
