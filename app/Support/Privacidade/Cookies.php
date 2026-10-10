<?php

namespace App\Support\Privacidade;

use Illuminate\Http\Request;

/**
 * Cookies da loja e o OK do cliente (pedido 2026-10-10). Documentação:
 * docs/privacidade-e-cookies.md.
 *
 * - Necessários (sessão, XSRF-TOKEN e este cookie de OK): funcionam sem o
 *   OK — sem eles a loja não abre carrinho, login nem checkout.
 * - Estatística (kazakora_visitor): só depois do OK.
 *
 * Mudou o que a loja coleta? Troque VERSAO: o aviso volta para todo mundo.
 */
class Cookies
{
    public const VERSAO = '2026-10-10';

    /** Guarda que o cliente deu OK (e em qual versão do aviso). */
    public const COOKIE_OK = 'kazakora_cookies';

    /** Identificador aleatório de visitante, só para estatística. */
    public const COOKIE_VISITANTE = 'kazakora_visitor';

    /** 12 meses: depois disso o aviso aparece de novo. */
    public const DURACAO_MINUTOS = 60 * 24 * 365;

    public static function aceitou(Request $request): bool
    {
        return $request->cookie(self::COOKIE_OK) === self::VERSAO;
    }
}
