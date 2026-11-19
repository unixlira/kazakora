<?php

namespace App\Modules\Notifications\Push\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Notifications\Push\AvisosPush;
use App\Modules\Notifications\Push\DadosDoApp;
use App\Modules\Notifications\Push\MetricasDaLoja;
use App\Modules\Notifications\Push\Models\PushInscricao;
use App\Modules\Notifications\Push\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        return response()->json($metricas->montar($app, MetricasDaLoja::periodo($request->query('periodo'))))
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * Sessão do app (fora do auth): diz se já há admin logado e deixa o
     * cookie XSRF para o login. Sempre 200, para a tela de entrar do app.
     */
    public function sessao(Request $request, string $app): JsonResponse
    {
        $user = $request->user();
        $admin = $user instanceof User && $user->role === User::ROLE_ADMIN;

        return response()->json([
            'logado' => $admin,
            'usuario' => $admin ? Str::before((string) $user->name, ' ') : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Login do próprio app (2026-11-19: o app não abre a tela de login do
     * painel). Mesma regra e limite de tentativas do login do painel; só
     * admin fica logado.
     */
    public function login(LoginRequest $request, string $app): JsonResponse
    {
        $request->merge(['remember' => true]);
        $request->authenticate();

        if ($request->user()?->role !== User::ROLE_ADMIN) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Só administrador entra no app.'], 403);
        }

        $request->session()->regenerate();

        return response()->json(['logado' => true, 'usuario' => Str::before((string) $request->user()->name, ' ')]);
    }

    public function sair(Request $request, string $app): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

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
