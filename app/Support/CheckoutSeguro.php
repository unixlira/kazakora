<?php

namespace App\Support;

use App\Models\User;
use App\Support\Privacidade\Cookies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Checkout no subdomínio seguro (pedido do Lira 2026-10-23): o botão de
 * compra leva para https://seguro.<domínio da loja>/pagamento, para o
 * cliente ver "seguro" no endereço. É o mesmo sistema respondendo pelos
 * dois endereços (nginx/hPanel apontam seguro.* para a mesma pasta).
 *
 * A sessão é presa ao endereço (SESSION_DOMAIN=null, o painel depende
 * disso), então carrinho, rascunho do checkout, cupom, login e o OK de
 * cookies passam de um endereço para o outro por uma "passagem": código
 * aleatório de uso único, guardado 10 minutos no cache, no mesmo molde do
 * link de entrada no painel (AuthenticatedSessionController::entrarNoPainel).
 *
 * Liga por loja em CHECKOUT_SEGURO_HOSTS (ex.: "kazakora.com"): só depois
 * que o DNS e o certificado SSL do seguro.* estiverem no ar.
 */
class CheckoutSeguro
{
    public const PREFIXO = 'seguro.';

    public const PARAMETRO = 'passagem';

    /** Caminho que o cliente vê no endereço do checkout. */
    public const CAMINHO = 'pagamento';

    private const VALIDADE_MINUTOS = 10;

    /** O que a compra precisa levar de um endereço para o outro. */
    private const CHAVES_SESSAO = ['cart', 'compra_direta', 'checkout_draft', 'checkout_version', 'frete_embutido'];

    /** Endereço da loja sem o www. ("kazakora.com"), se o checkout seguro estiver ligado para ela. */
    public static function lojaComSeguro(string $host): ?string
    {
        $host = preg_replace('/^www\./', '', strtolower($host));

        return in_array($host, self::hostsLigados(), true) ? $host : null;
    }

    /** "seguro.kazakora.com" → "kazakora.com"; null se não for um seguro.* ligado. */
    public static function lojaDoSeguro(string $host): ?string
    {
        $host = strtolower($host);

        if (! str_starts_with($host, self::PREFIXO)) {
            return null;
        }

        return self::lojaComSeguro(substr($host, strlen(self::PREFIXO)));
    }

    public static function noSeguro(Request $request): bool
    {
        return self::lojaDoSeguro($request->getHost()) !== null;
    }

    /** Guarda o que a compra precisa e devolve o código da passagem. */
    public static function criarPassagem(Request $request): string
    {
        $sessao = [];
        foreach (self::CHAVES_SESSAO as $chave) {
            if ($request->session()->has($chave)) {
                $sessao[$chave] = $request->session()->get($chave);
            }
        }

        $cookies = [];
        foreach (self::cookiesLevados() as $nome) {
            if (is_string($valor = $request->cookie($nome)) && $valor !== '') {
                $cookies[$nome] = $valor;
            }
        }

        $codigo = Str::random(48);

        Cache::put(self::chave($codigo), [
            'sessao' => $sessao,
            'apagar' => array_values(array_diff(self::CHAVES_SESSAO, array_keys($sessao))),
            'cookies' => $cookies,
            'user_id' => $request->user()?->id,
        ], now()->addMinutes(self::VALIDADE_MINUTOS));

        return $codigo;
    }

    /**
     * Abre no endereço atual o que a passagem levou. Uso único: o código sai
     * do cache na hora. Devolve false se não existe ou já foi usado.
     */
    public static function receberPassagem(Request $request, string $codigo): bool
    {
        if (preg_match('/^[A-Za-z0-9]{48}$/', $codigo) !== 1) {
            return false;
        }

        $dados = Cache::pull(self::chave($codigo));

        if (! is_array($dados)) {
            return false;
        }

        $usuario = isset($dados['user_id']) ? User::find($dados['user_id']) : null;

        if ($usuario && Auth::id() !== $usuario->id) {
            Auth::guard('web')->login($usuario);
            $request->session()->regenerate();
        } elseif (! $usuario && Auth::check()) {
            // Saiu da conta no outro endereço: não fica logado aqui.
            Auth::guard('web')->logout();
            $request->session()->regenerate();
        }

        $request->session()->forget($dados['apagar'] ?? []);
        foreach ($dados['sessao'] ?? [] as $chave => $valor) {
            $request->session()->put($chave, $valor);
        }

        foreach ($dados['cookies'] ?? [] as $nome => $valor) {
            if (in_array($nome, self::cookiesLevados(), true)) {
                Cookie::queue(Cookie::make($nome, $valor, Cookies::DURACAO_MINUTOS, null, null, null, true, false, 'lax'));
            }
        }

        return true;
    }

    /** @return array<int, string> */
    private static function hostsLigados(): array
    {
        return array_values(array_filter(array_map(
            fn ($host) => preg_replace('/^www\./', '', strtolower(trim((string) $host))),
            (array) config('loja.checkout_seguro_hosts', []),
        )));
    }

    /** OK de cookies, visitante (estatística) e CEP do frete embutido. */
    private static function cookiesLevados(): array
    {
        return array_values(array_filter([
            Cookies::COOKIE_OK,
            Cookies::COOKIE_VISITANTE,
            (string) config('frete_embutido.cookie'),
        ]));
    }

    private static function chave(string $codigo): string
    {
        return 'checkout-seguro:'.hash('sha256', $codigo);
    }
}
