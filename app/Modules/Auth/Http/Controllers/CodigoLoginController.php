<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Support\CodigoLoginAdmin;
use App\Modules\Inventory\Support\LowStockAlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Segunda etapa do login do admin (auditoria 2026-11-25): a senha já foi
 * conferida em AuthenticatedSessionController::store, que guardou na sessão
 * quem está entrando e mandou o código por e-mail. Aqui o código abre a
 * sessão de verdade. Regras do código em CodigoLoginAdmin.
 */
class CodigoLoginController extends Controller
{
    /** Chave da sessão com o login pela metade: user_id, remember, em. */
    public const SESSAO = 'login_2fa';

    public function create(Request $request, CodigoLoginAdmin $codigoLogin): HttpResponse
    {
        $user = $this->usuarioPendente($request, $codigoLogin);

        if (! $user) {
            return redirect()->route('entrar');
        }

        return Inertia::render('Auth/CodigoLogin', [
            'email' => self::mascararEmail($user->email),
            'validadeMinutos' => $codigoLogin->validadeMinutos(),
            'lembrarDias' => $codigoLogin->lembrarDias(),
        ])->toResponse($request);
    }

    public function store(Request $request, CodigoLoginAdmin $codigoLogin, LowStockAlertService $lowStockAlert, AuthenticatedSessionController $sessao): HttpResponse
    {
        $dados = $request->validate([
            'codigo' => ['required', 'string'],
            'lembrar' => ['nullable', 'boolean'],
        ]);

        $user = $this->usuarioPendente($request, $codigoLogin);

        if (! $user) {
            return $this->voltarParaSenha($request, 'O código venceu. Entre de novo com a sua senha.');
        }

        $codigo = preg_replace('/\D/', '', $dados['codigo']);
        [$situacao, $restantes] = strlen($codigo) === 6
            ? $codigoLogin->conferir($user, $codigo)
            : [CodigoLoginAdmin::ERRADO, null];

        if ($situacao === CodigoLoginAdmin::EXPIRADO) {
            return $this->voltarParaSenha($request, 'O código venceu. Entre de novo com a sua senha.');
        }

        if ($situacao === CodigoLoginAdmin::BLOQUEADO) {
            Log::warning('admin_2fa.bloqueado', ['user_id' => $user->id, 'ip' => $request->ip()]);

            return $this->voltarParaSenha($request, 'Muitas tentativas com código errado. Entre de novo com a sua senha.');
        }

        if ($situacao === CodigoLoginAdmin::ERRADO) {
            Log::warning('admin_2fa.codigo_errado', ['user_id' => $user->id, 'ip' => $request->ip(), 'restantes' => $restantes]);

            throw ValidationException::withMessages([
                'codigo' => $restantes === null
                    ? 'O código tem 6 números.'
                    : 'Código incorreto. '.($restantes === 1 ? 'Resta 1 tentativa.' : "Restam {$restantes} tentativas."),
            ]);
        }

        $remember = (bool) ($request->session()->get(self::SESSAO)['remember'] ?? false);
        $request->session()->forget(self::SESSAO);

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        if ($request->boolean('lembrar')) {
            cookie()->queue($codigoLogin->cookieLembrar($user));
        }

        Log::info('admin_2fa.ok', ['user_id' => $user->id, 'ip' => $request->ip(), 'lembrar' => $request->boolean('lembrar')]);

        return $sessao->concluirLogin($request, $user, $lowStockAlert);
    }

    public function reenviar(Request $request, CodigoLoginAdmin $codigoLogin): RedirectResponse
    {
        $user = $this->usuarioPendente($request, $codigoLogin);

        if (! $user) {
            return $this->voltarParaSenha($request, 'O código venceu. Entre de novo com a sua senha.');
        }

        // O prazo da etapa conta a partir do último envio.
        $request->session()->put(self::SESSAO.'.em', now()->getTimestamp());

        self::enviarCodigo($codigoLogin, $user, 'codigo');

        return redirect()->route('entrar.codigo')->with('success', 'Enviamos um código novo para o seu e-mail.');
    }

    /** Manda o código; e-mail fora do ar vira mensagem na tela em vez de erro 500. */
    public static function enviarCodigo(CodigoLoginAdmin $codigoLogin, User $user, string $campoDoErro): void
    {
        try {
            $codigoLogin->enviar($user);
        } catch (Throwable $e) {
            Log::error('admin_2fa.envio_falhou', ['user_id' => $user->id, 'erro' => $e->getMessage()]);

            throw ValidationException::withMessages([
                $campoDoErro => 'Não conseguimos enviar o código por e-mail agora. Tente de novo em instantes.',
            ]);
        }
    }

    private function usuarioPendente(Request $request, CodigoLoginAdmin $codigoLogin): ?User
    {
        $pendente = $request->session()->get(self::SESSAO);

        if (! is_array($pendente) || ! isset($pendente['user_id'], $pendente['em'])) {
            return null;
        }

        if ((int) $pendente['em'] + $codigoLogin->validadeMinutos() * 60 < now()->getTimestamp()) {
            $request->session()->forget(self::SESSAO);

            return null;
        }

        $user = User::query()->find($pendente['user_id']);

        return $user && $user->isStaff() ? $user : null;
    }

    private function voltarParaSenha(Request $request, string $mensagem): RedirectResponse
    {
        $request->session()->forget(self::SESSAO);

        return redirect()->route('entrar')->withErrors(['email' => $mensagem]);
    }

    public static function mascararEmail(string $email): string
    {
        [$nome, $dominio] = array_pad(explode('@', $email, 2), 2, '');
        $visivel = mb_substr($nome, 0, min(2, max(1, mb_strlen($nome) - 1)));

        return $visivel.str_repeat('*', max(1, mb_strlen($nome) - mb_strlen($visivel))).'@'.$dominio;
    }
}
