<?php

namespace App\Modules\Notifications\Push\Http;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Push\AvisosPush;
use App\Modules\Notifications\Push\Models\PushInscricao;
use App\Modules\Notifications\Push\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}
