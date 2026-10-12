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

    /**
     * Cookies que o log de visitas pode guardar (pedido 2026-10-12: ver os
     * cookies do visitante no olho de Visitas ao site). LISTA PERMITIDA:
     * cookie que não está aqui nunca é gravado. Fora de propósito ficam a
     * sessão do Laravel (kazakora-session), o XSRF-TOKEN, o "remember_*" do
     * login e qualquer coisa que a loja venha a criar no futuro.
     *
     * Terminar em "*" vale para o prefixo (_ga_ABC123 do Google Analytics 4).
     */
    public const COOKIES_REGISTRAVEIS = [
        self::COOKIE_VISITANTE,
        self::COOKIE_OK,
        '_fbp', '_fbc',
        '_ga', '_ga_*', '_gid', '_gcl_au', '_gcl_aw',
        '_ttp', 'ttclid',
    ];

    /** Tamanho máximo do valor gravado (caractere). */
    public const LIMITE_VALOR_COOKIE = 200;

    public static function aceitou(Request $request): bool
    {
        return $request->cookie(self::COOKIE_OK) === self::VERSAO;
    }

    public static function registravel(string $nome): bool
    {
        foreach (self::COOKIES_REGISTRAVEIS as $permitido) {
            if ($permitido === $nome || (str_ends_with($permitido, '*') && str_starts_with($nome, substr($permitido, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cookies permitidos que o navegador mandou nesta requisição, nome =>
     * valor. Os da própria loja chegam criptografados (o Laravel já abre);
     * os de terceiros (_fbp, _ga...) chegam em texto puro, e o Laravel os
     * descarta ao tentar abrir, por isso vêm direto do cabeçalho Cookie.
     * Valor vazio ou sem formato de texto simples fica de fora.
     *
     * @return array<string, string>
     */
    public static function doVisitante(Request $request): array
    {
        $encontrados = [];

        foreach (explode(';', (string) $request->headers->get('cookie')) as $par) {
            [$nome, $valor] = array_pad(explode('=', trim($par), 2), 2, '');
            $nome = trim($nome);
            if ($nome !== '' && self::registravel($nome)) {
                $encontrados[$nome] = rawurldecode($valor);
            }
        }

        // Os próprios (criptografados): o valor aberto vem do Laravel.
        foreach ([self::COOKIE_VISITANTE, self::COOKIE_OK] as $nome) {
            $aberto = $request->cookie($nome);
            if (is_string($aberto) && $aberto !== '') {
                $encontrados[$nome] = $aberto;
            } else {
                unset($encontrados[$nome]);
            }
        }

        $limpos = [];
        foreach ($encontrados as $nome => $valor) {
            $valor = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $valor));
            if ($valor !== '') {
                $limpos[$nome] = mb_substr($valor, 0, self::LIMITE_VALOR_COOKIE);
            }
        }
        ksort($limpos);

        return $limpos;
    }
}
