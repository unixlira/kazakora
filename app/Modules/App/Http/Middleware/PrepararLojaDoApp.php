<?php

namespace App\Modules\App\Http\Middleware;

use App\Modules\Checkout\Support\FreteEmbutido;
use App\Support\SiteAtual;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rotas /api/app (2026-10-18): os mesmos preços das telas da loja
 * (AtivarFreteEmbutido): frete embutido ligado e Oferta Relâmpago da
 * KazaKora. Sem sessão nem cookie, a região do cliente vem do CEP que o app
 * mandar (?cep= ou cabeçalho X-App-Cep); sem ele, do estado pelo IP
 * (Cloudflare), como para o visitante do site.
 */
class PrepararLojaDoApp
{
    public function handle(Request $request, Closure $next): Response
    {
        $frete = app(FreteEmbutido::class);
        $frete->ligar();
        app(SiteAtual::class)->definir(SiteAtual::KAZAKORA);

        $cep = preg_replace('/\D/', '', (string) ($request->query('cep') ?: $request->header('X-App-Cep')));
        if (strlen($cep) === 8) {
            $frete->usarCep($cep);
        }

        $resposta = $next($request);
        // Preço depende do CEP, do estoque e da hora (relâmpago): sem cache no meio do caminho.
        $resposta->headers->set('Cache-Control', 'private, no-store');

        return $resposta;
    }
}
