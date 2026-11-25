<?php

namespace App\Modules\Notifications\Push\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Notifications\Push\AvisosPush;
use App\Modules\Notifications\Push\DadosDoApp;
use App\Modules\Notifications\Push\TokensDoApp;
use App\Modules\Notifications\Push\MetricasDaLoja;
use App\Modules\Notifications\Push\Models\PushInscricao;
use App\Modules\Notifications\Push\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * API da página de ativação dos apps de vendas (/app/<app>/, repositório
 * próprio de cada app). Só admin, com a sessão normal do painel.
 */
class AppVendasController extends Controller
{
    /**
     * Login: o app manda para cá, o painel pede a senha e volta para o app.
     * O login é um POST do Inertia que segue o redirecionamento para cá:
     * Inertia::location faz o navegador abrir a página do app inteira (um
     * redirect comum cairia como "resposta que não é Inertia").
     */
    public function entrar(string $app): Response
    {
        // Endereço antigo do app no painel: depois que app.<loja>.com entra no ar,
        // o nginx do painel manda /app/<loja>/ para lá com 301.
        return Inertia::location('/app/'.$app.'/');
    }

    public function estado(Request $request, string $app, WebPush $webPush): JsonResponse
    {
        return response()->json([
            'app' => $app,
            'usuario' => Str::before((string) $request->user()->name, ' '),
            'chave_publica' => $webPush->chavePublica(),
            'configurado' => $webPush->configurado(),
            'aparelhos' => PushInscricao::query()->where('app', $app)->where('user_id', $request->user()->id)->count(),
        ]);
    }

    public function inscrever(Request $request, string $app): JsonResponse
    {
        $dados = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:120'],
            'keys.auth' => ['required', 'string', 'max:60'],
        ]);

        $p256dh = WebPush::base64urlDecode($dados['keys']['p256dh']);
        if (strlen($p256dh) !== 65 || $p256dh[0] !== "\x04" || strlen(WebPush::base64urlDecode($dados['keys']['auth'])) !== 16) {
            return response()->json(['message' => 'Chaves da inscrição inválidas.'], 422);
        }

        $inscricao = PushInscricao::query()->updateOrCreate(
            ['endpoint_hash' => PushInscricao::hashDoEndpoint($dados['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'app' => $app,
                'endpoint' => $dados['endpoint'],
                'p256dh' => $dados['keys']['p256dh'],
                'auth' => $dados['keys']['auth'],
                'user_agent' => Str::limit((string) $request->userAgent(), 290, ''),
                'ultima_vez_em' => now(),
            ],
        );

        return response()->json(['ok' => true, 'id' => $inscricao->id]);
    }

    public function cancelar(Request $request, string $app): JsonResponse
    {
        $dados = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        PushInscricao::query()
            ->where('endpoint_hash', PushInscricao::hashDoEndpoint($dados['endpoint']))
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['ok' => true]);
    }

    public function teste(Request $request, string $app, AvisosPush $avisos): JsonResponse
    {
        $resultado = $avisos->enviar($app, $avisos->mensagemDeTeste($app), $request->user());

        return response()->json($resultado, $resultado['enviados'] > 0 ? 200 : 422);
    }

    /**
     * Tela principal do app (2026-11-19): métricas só da loja do app
     * (MetricasDaLoja), guardadas 60 s. ?periodo=hoje|7d|30d.
     */
    public function metricas(Request $request, string $app, MetricasDaLoja $metricas): JsonResponse
    {
        // Setas do app (2026-11-24): &fim=AAAA-MM-DD volta a janela no tempo; sem futuro.
        // (/api-apps-vendas fica fora de api/*: o erro de validação volta em JSON por aqui.)
        $validacao = Validator::make($request->query(), [
            'fim' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:2020-01-01'],
        ], [
            'fim.before_or_equal' => 'Essa data ainda não chegou.',
        ]);
        if ($validacao->fails()) {
            return response()->json(['message' => $validacao->errors()->first(), 'errors' => $validacao->errors()], 422);
        }
        $fim = $request->filled('fim') ? Carbon::createFromFormat('!Y-m-d', (string) $request->query('fim'), config('app.timezone')) : null;

        return response()->json($metricas->montar($app, MetricasDaLoja::periodo($request->query('periodo')), $fim))
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * Login do app (2026-11-19: app.kazakora.com e app.ferrakora.com são
     * sites próprios). Confere a senha sem abrir sessão, com o mesmo limite
     * de 5 tentativas do login do painel, e devolve um token só deste app
     * (TokensDoApp). Só admin.
     */
    public function login(Request $request, string $app): JsonResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
            'aparelho' => ['nullable', 'string', 'max:120'],
        ]);
        $chave = 'apps-vendas-login:'.Str::transliterate(Str::lower($dados['email'])).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($chave, 5)) {
            return response()->json(['message' => 'Muitas tentativas. Tente de novo em '.RateLimiter::availableIn($chave).' segundos.'], 429);
        }

        $user = User::query()->where('email', $dados['email'])->first();
        if (! $user || ! Auth::guard('web')->getProvider()->validateCredentials($user, ['password' => $dados['password']])) {
            RateLimiter::hit($chave);

            return response()->json(['message' => 'E-mail ou senha incorretos.'], 422);
        }
        RateLimiter::clear($chave);

        if ($user->role !== User::ROLE_ADMIN) {
            return response()->json(['message' => 'Só administrador entra no app.'], 403);
        }

        return response()->json(TokensDoApp::emitir($user, $app, (string) ($dados['aparelho'] ?? '')) + [
            'usuario' => Str::before((string) $user->name, ' '),
        ]);
    }

    public function sessao(Request $request, string $app): JsonResponse
    {
        return response()->json(['logado' => true, 'usuario' => Str::before((string) $request->user()->name, ' ')]);
    }

    /** Sai: apaga o token e, se vier, a inscrição de aviso deste aparelho. */
    public function sair(Request $request, string $app): JsonResponse
    {
        if (is_string($endpoint = $request->input('endpoint')) && $endpoint !== '') {
            PushInscricao::query()->where('endpoint_hash', PushInscricao::hashDoEndpoint($endpoint))
                ->where('user_id', $request->user()->id)->delete();
        }
        $request->attributes->get('app_vendas_token')?->delete();

        return response()->json(['ok' => true]);
    }

    public function pedidos(Request $request, string $app, DadosDoApp $dados): JsonResponse
    {
        return response()->json($dados->pedidos($app, $request->query('status'), (int) $request->query('pagina', 1)));
    }

    public function pedido(string $app, int $pedido, DadosDoApp $dados): JsonResponse
    {
        $resposta = $dados->pedido($app, $pedido);
        abort_if($resposta === null, 404);

        return response()->json($resposta);
    }

    public function produtos(Request $request, string $app, DadosDoApp $dados): JsonResponse
    {
        return response()->json($dados->produtos($app, is_string($request->query('busca')) ? mb_substr($request->query('busca'), 0, 80) : null));
    }

    public function campanhas(Request $request, string $app, DadosDoApp $dados): JsonResponse
    {
        return response()->json($dados->campanhas($app, MetricasDaLoja::periodo($request->query('periodo'))));
    }
}
