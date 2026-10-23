<?php

namespace App\Http\Middleware;

use App\Support\CheckoutSeguro;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checkout no subdomínio seguro (pedido do Lira 2026-10-23), ver
 * App\Support\CheckoutSeguro.
 *
 * - Na loja (kazakora.com): abrir a finalização (/finalizacao, "Comprar",
 *   "Finalizar compra") leva para https://seguro.kazakora.com/pagamento com
 *   a passagem.
 * - No seguro.kazakora.com: recebe a passagem; /finalizacao vira /pagamento;
 *   qualquer outra página da loja volta para kazakora.com com a passagem
 *   (o carrinho já vazio depois da compra, o login de quem comprou).
 *
 * Só GET de página. As chamadas do checkout (frete, cupom, pagamento,
 * status) seguem em /finalizacao/* no próprio endereço em que a página está.
 */
class RedirecionarCheckoutSeguro
{
    /** Páginas que abrem no seguro.* sem voltar para a loja. */
    private const PAGINAS_DO_SEGURO = [
        CheckoutSeguro::CAMINHO, 'finalizacao', 'finalizacao/*', 'frete/*',
        'entrar', 'sair', 'esqueci-senha', 'redefinir-senha/*',
        'termos-de-uso', 'politica-de-privacidade', 'politica-de-cookies', 'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || $request->expectsJson() && ! $request->header('X-Inertia')) {
            return $next($request);
        }

        $loja = CheckoutSeguro::lojaDoSeguro($request->getHost());

        if ($loja !== null) {
            return $this->noSeguro($request, $next, $loja);
        }

        if ($request->is(CheckoutSeguro::CAMINHO) || $request->routeIs('finalizacao.entrega', 'finalizacao.pagamento')) {
            $lojaComSeguro = CheckoutSeguro::lojaComSeguro($request->getHost());

            if ($lojaComSeguro !== null) {
                return $this->irPara($request, 'https://'.CheckoutSeguro::PREFIXO.$lojaComSeguro.'/'.CheckoutSeguro::CAMINHO);
            }

            // Desligado nesta loja: /pagamento é só outro nome da finalização.
            if ($request->is(CheckoutSeguro::CAMINHO)) {
                return redirect()->route('finalizacao.entrega', $request->query());
            }
        }

        if ($request->filled(CheckoutSeguro::PARAMETRO) && CheckoutSeguro::lojaComSeguro($request->getHost()) !== null) {
            CheckoutSeguro::receberPassagem($request, (string) $request->query(CheckoutSeguro::PARAMETRO));

            return redirect()->to($this->semPassagem($request));
        }

        return $next($request);
    }

    private function noSeguro(Request $request, Closure $next, string $loja): Response
    {
        if ($request->filled(CheckoutSeguro::PARAMETRO)) {
            CheckoutSeguro::receberPassagem($request, (string) $request->query(CheckoutSeguro::PARAMETRO));

            return redirect()->to($this->semPassagem($request));
        }

        // As rotas do checkout mandam para route('finalizacao.entrega'):
        // aqui o endereço que o cliente vê é /pagamento. reflash: o erro de
        // validação precisa chegar até a tela.
        if ($request->is('finalizacao')) {
            $request->session()->reflash();

            return redirect()->to('/'.CheckoutSeguro::CAMINHO.$this->query($request));
        }

        if ($request->is(self::PAGINAS_DO_SEGURO)) {
            return $next($request);
        }

        return $this->irPara($request, 'https://'.$loja.'/'.ltrim($request->path(), '/'));
    }

    /** Endereço de destino com a passagem, mantendo os parâmetros (cupom, ?v=1, UTM). */
    private function irPara(Request $request, string $destino): Response
    {
        $query = $request->query();
        unset($query[CheckoutSeguro::PARAMETRO]);
        $query[CheckoutSeguro::PARAMETRO] = CheckoutSeguro::criarPassagem($request);

        $url = rtrim($destino, '/').'?'.http_build_query($query);

        // Navegação do Inertia (link ou redirect depois de POST): outro
        // domínio só com location(), que faz o navegador abrir a página inteira.
        return $request->header('X-Inertia') ? Inertia::location($url) : redirect()->away($url);
    }

    private function semPassagem(Request $request): string
    {
        $query = $request->query();
        unset($query[CheckoutSeguro::PARAMETRO]);

        return '/'.ltrim($request->path(), '/').($query ? '?'.http_build_query($query) : '');
    }

    private function query(Request $request): string
    {
        return $request->getQueryString() ? '?'.$request->getQueryString() : '';
    }
}
