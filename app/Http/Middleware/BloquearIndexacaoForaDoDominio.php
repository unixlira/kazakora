<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Search Console (pedido 2026-10-12): o homolog (kazakora.devlira.com.br)
 * estava aberto para o Google, com as mesmas páginas da loja. Conteúdo
 * duplicado tira a força do kazakora.com. Qualquer host que não seja o
 * domínio oficial responde com X-Robots-Tag noindex (o robô ainda pode
 * rastrear, que é o que faz ele ver o cabeçalho e tirar a página do índice).
 *
 * No domínio oficial, páginas sem valor na busca (carrinho, login, formas
 * de envio, busca e ordenação) também levam noindex, para não virarem
 * "Duplicada" ou "Rastreada, não indexada" no relatório.
 */
class BloquearIndexacaoForaDoDominio
{
    private const CAMINHOS_SEM_INDICE = [
        'carrinho', 'carrinho/*', 'entrar', 'cadastro', 'rastreio',
        'esqueci-senha', 'redefinir-senha/*', 'produtos/*/envio',
    ];

    private const PARAMETROS_SEM_INDICE = ['search', 'ordenar', 'tipo', 'page', 'v'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $foraDoDominio = strtolower($request->getHost()) !== strtolower((string) config('app.dominio_indexavel'));
        $semIndice = $request->is(self::CAMINHOS_SEM_INDICE)
            || array_intersect(self::PARAMETROS_SEM_INDICE, array_keys($request->query())) !== [];

        if ($foraDoDominio) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        } elseif ($semIndice) {
            $response->headers->set('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }
}
