<?php

namespace App\Modules\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\App\Models\AppPushToken;
use App\Modules\App\Services\ExcluirContaDoApp;
use App\Modules\App\Support\FormatoApp;
use App\Modules\Auth\Mail\WelcomeEmail;
use App\Rules\CpfValido;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as RegraSenha;
use Illuminate\Validation\ValidationException;

/**
 * Conta do cliente no app nativo (2026-10-18): a mesma conta (users) do
 * site kazakora.com, com token do Sanctum por aparelho (ability "app").
 */
class ContaAppController extends Controller
{
    public function cadastro(Request $request): JsonResponse
    {
        if (is_string($request->input('nome'))) {
            $request->merge(['nome' => trim($request->input('nome'))]);
        }

        $dados = $request->validate([
            'nome' => ['required', 'string', 'min:3', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'cpf' => ['required', 'string', 'max:14', new CpfValido],
            'telefone' => ['required', 'string', 'min:10', 'max:20'],
            'senha' => ['required', 'string', RegraSenha::min(8)],
            'aceite_termos' => ['accepted'],
            'dispositivo' => ['nullable', 'string', 'max:60'],
        ], [
            'nome.required' => 'Digite seu nome.',
            'cpf.required' => 'Digite seu CPF.',
            'telefone.required' => 'Digite seu celular com DDD.',
            'telefone.min' => 'Digite seu celular com DDD.',
            'senha.required' => 'Crie uma senha.',
            'aceite_termos.accepted' => 'Para criar a conta, aceite os termos de uso e a política de privacidade.',
        ], ['email' => 'e-mail', 'senha' => 'senha']);

        // Um e-mail e um CPF por conta, contando a apagada (índice único do banco), como no site.
        $erros = array_filter([
            'email' => User::emailEmUso($dados['email']) ? 'Este e-mail já está em uso. Entre na sua conta ou recupere a senha.' : null,
            'cpf' => User::cpfEmUso($dados['cpf']) ? 'Este CPF já está em uso em outra conta. Entre nessa conta para continuar.' : null,
        ]);
        if ($erros) {
            throw ValidationException::withMessages($erros);
        }

        $user = User::create([
            'name' => $dados['nome'],
            'email' => trim($dados['email']),
            'cpf' => preg_replace('/\D/', '', $dados['cpf']),
            'phone' => preg_replace('/\D/', '', $dados['telefone']),
            'password' => Hash::make($dados['senha']),
            'role' => User::ROLE_CUSTOMER,
        ]);

        event(new Registered($user));
        rescue(fn () => Mail::to($user)->send(new WelcomeEmail($user)));

        return response()->json(['token' => $this->token($user, $dados['dispositivo'] ?? null), 'usuario' => FormatoApp::usuario($user)], 201);
    }

    /** Mesma regra do login do site (LoginRequest): 5 tentativas por e-mail e IP. */
    public function login(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'string', 'email'],
            'senha' => ['required', 'string'],
            'dispositivo' => ['nullable', 'string', 'max:60'],
        ], ['senha.required' => 'Digite sua senha.'], ['email' => 'e-mail']);

        $chave = 'app-login:'.Str::transliterate(Str::lower(trim($dados['email'])).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($chave, 5)) {
            $segundos = RateLimiter::availableIn($chave);

            throw ValidationException::withMessages(['email' => __('auth.throttle', ['seconds' => $segundos, 'minutes' => ceil($segundos / 60)])]);
        }

        $user = User::query()->where('email', trim($dados['email']))->first();
        if (! $user || ! Hash::check($dados['senha'], $user->password)) {
            RateLimiter::hit($chave);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($chave);

        return response()->json(['token' => $this->token($user, $dados['dispositivo'] ?? null), 'usuario' => FormatoApp::usuario($user)]);
    }

    /** E-mail de redefinição do site. A resposta é sempre a mesma: não diz se o e-mail tem conta. */
    public function esqueciSenha(Request $request): JsonResponse
    {
        $dados = $request->validate(['email' => ['required', 'string', 'email', 'max:255']], [], ['email' => 'e-mail']);

        rescue(fn () => Password::sendResetLink(['email' => trim($dados['email'])]), null, false);

        return response()->json(['message' => 'Se este e-mail tiver conta na KazaKora, enviamos um link para criar uma nova senha. Confira a caixa de entrada e o spam.']);
    }

    public function eu(Request $request): JsonResponse
    {
        return response()->json(['usuario' => FormatoApp::usuario($request->user())]);
    }

    /**
     * Nome e telefone. CPF só entra quando a conta ainda não tem um válido
     * (conta criada no site sem CPF): sem ele não dá para pagar.
     */
    public function atualizar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $semCpf = ! CpfValido::valido((string) $user->cpf);

        $dados = $request->validate([
            'nome' => ['sometimes', 'required', 'string', 'min:3', 'max:255'],
            'telefone' => ['sometimes', 'required', 'string', 'min:10', 'max:20'],
            'cpf' => $semCpf ? ['sometimes', 'required', 'string', 'max:14', new CpfValido] : ['prohibited'],
        ], [
            'telefone.min' => 'Digite seu celular com DDD.',
            'cpf.prohibited' => 'O CPF da conta não pode ser trocado pelo app. Fale com o suporte.',
        ]);

        if (isset($dados['cpf']) && User::cpfEmUso($dados['cpf'], $user->id)) {
            throw ValidationException::withMessages(['cpf' => 'Este CPF já está em uso em outra conta.']);
        }

        $user->forceFill(array_filter([
            'name' => isset($dados['nome']) ? trim($dados['nome']) : null,
            'phone' => isset($dados['telefone']) ? preg_replace('/\D/', '', $dados['telefone']) : null,
            'cpf' => isset($dados['cpf']) ? preg_replace('/\D/', '', $dados['cpf']) : null,
        ], fn ($valor) => $valor !== null))->save();

        return response()->json(['usuario' => FormatoApp::usuario($user->refresh())]);
    }

    /** Trocar a senha com a atual, como o Meu Perfil do site. Os outros aparelhos saem. */
    public function senha(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'senha_atual' => ['required', 'string'],
            'senha' => ['required', 'string', RegraSenha::min(8), 'different:senha_atual'],
        ], [
            'senha_atual.required' => 'Digite sua senha atual.',
            'senha.required' => 'Crie a nova senha.',
            'senha.different' => 'A nova senha precisa ser diferente da atual.',
        ], ['senha' => 'nova senha']);

        /** @var User $user */
        $user = $request->user();
        if (! Hash::check($dados['senha_atual'], $user->password)) {
            throw ValidationException::withMessages(['senha_atual' => 'Senha atual incorreta.']);
        }

        $user->forceFill(['password' => Hash::make($dados['senha'])])->save();
        $user->tokens()->whereKeyNot($user->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Senha alterada.']);
    }

    public function sair(Request $request): Response
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    /** Exclusão da conta pelo próprio cliente (exigência da Apple). Ver ExcluirContaDoApp. */
    public function excluir(Request $request, ExcluirContaDoApp $excluir): Response
    {
        $dados = $request->validate([
            'senha' => ['required', 'string'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ], ['senha.required' => 'Digite sua senha para confirmar.']);

        /** @var User $user */
        $user = $request->user();
        if (! Hash::check($dados['senha'], $user->password)) {
            throw ValidationException::withMessages(['senha' => 'Senha incorreta.']);
        }
        if ($user->isStaff()) {
            throw ValidationException::withMessages(['senha' => 'Conta da equipe não pode ser excluída pelo app.']);
        }

        $excluir->excluir($user);
        Log::info('app.conta_excluida', ['user_id' => $user->id, 'motivo' => $dados['motivo'] ?? null]);

        return response()->noContent();
    }

    /** Token de notificação do aparelho. Só guarda: nenhum disparo nesta versão. */
    public function pushToken(Request $request): Response
    {
        $dados = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'plataforma' => ['required', 'in:'.implode(',', AppPushToken::PLATAFORMAS)],
        ]);

        AppPushToken::query()->updateOrCreate(
            ['token' => $dados['token']],
            ['user_id' => $request->user()->id, 'plataforma' => $dados['plataforma']],
        );

        return response()->noContent();
    }

    private function token(User $user, ?string $dispositivo): string
    {
        $nome = 'app:'.(filled($dispositivo) ? Str::limit(trim($dispositivo), 60, '') : 'aparelho');

        return $user->createToken($nome, ['app'], now()->addDays((int) config('app_mobile.token_dias', 365)))->plainTextToken;
    }
}
