<?php

namespace App\Support\Privacidade;

use App\Modules\Analytics\Models\ConsentimentoCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Cookies da loja e o OK do cliente (pedido 2026-10-10). Documentação:
 * docs/privacidade-e-cookies.md.
 *
 * - Necessários (sessão, XSRF-TOKEN e este cookie de OK): funcionam sem o
 *   OK — sem eles a loja não abre carrinho, login nem checkout.
 * - Estatística (kazakora_visitor): só depois do OK.
 * - Navegação (kazakora_navegacao, pedido 2026-10-25): necessário, criado no
 *   primeiro acesso. Código aleatório que liga as páginas do mesmo navegador
 *   no registro de acesso (Marco Civil), sem login e sem OK; não leva nome,
 *   e-mail nem CPF. Antes dele o visitante sem OK era a sessão, que muda no
 *   login e some em 2 h, e o histórico do olho se partia.
 *
 * Desde 2026-10-24 não há mais aviso flutuante: o aceite vem junto com o
 * "Finalizar pedido" (frase abaixo do botão) e é gravado por registrarAceite().
 *
 * Mudou o que a loja coleta? Troque VERSAO e atualize as políticas.
 */
class Cookies
{
    // 2026-10-18: API de Conversões da Meta (o servidor também manda os eventos).
    // 2026-10-24: aceite na frase do checkout, no lugar do aviso flutuante.
    public const VERSAO = '2026-10-24';

    /** OKs anteriores para os mesmos cookies (aviso de 2026-10-18): continuam valendo. */
    public const VERSOES_ANTERIORES = ['2026-10-18'];

    /** Guarda que o cliente deu OK (e em qual versão do aviso). */
    public const COOKIE_OK = 'kazakora_cookies';

    /** Identificador aleatório de visitante, só para estatística. */
    public const COOKIE_VISITANTE = 'kazakora_visitor';

    /** Código de navegação do registro de acesso (necessário, sem OK). */
    public const COOKIE_NAVEGACAO = 'kazakora_navegacao';

    /** 6 meses, o mesmo prazo do registro de acesso (Marco Civil, art. 15). */
    public const DURACAO_NAVEGACAO_MINUTOS = 60 * 24 * 183;

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
        return in_array($request->cookie(self::COOKIE_OK), [self::VERSAO, ...self::VERSOES_ANTERIORES], true);
    }

    /**
     * Grava o aceite (LGPD art. 8º, § 2º) e devolve o código do visitante.
     * Na KazaKora também cria os cookies do OK e do visitante e marca a
     * requisição atual como aceita, para o resto dela (rastreio da Meta do
     * pedido) já enxergar o aceite. Os outros sites (Beauty Kora, FerraKora)
     * chegam pela API, de outro domínio: só a prova é gravada.
     */
    public static function registrarAceite(Request $request, string $origem = ConsentimentoCookie::ORIGEM_KAZAKORA, ?int $userId = null): string
    {
        $naLoja = $origem === ConsentimentoCookie::ORIGEM_KAZAKORA;
        $visitante = ($naLoja ? $request->cookie(self::COOKIE_VISITANTE) : null) ?: (string) Str::uuid();

        if (! $naLoja || ! self::aceitou($request)) {
            ConsentimentoCookie::create([
                'visitante_id' => $visitante,
                'user_id' => $userId ?? $request->user()?->id,
                'origem' => $origem,
                'versao' => self::VERSAO,
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
                'aceito_em' => now(),
            ]);
        }

        if ($naLoja) {
            Cookie::queue(self::COOKIE_OK, self::VERSAO, self::DURACAO_MINUTOS);
            Cookie::queue(self::COOKIE_VISITANTE, $visitante, self::DURACAO_MINUTOS);
            $request->cookies->set(self::COOKIE_OK, self::VERSAO);
            $request->cookies->set(self::COOKIE_VISITANTE, $visitante);
        }

        return $visitante;
    }

    /**
     * Visitante sem o OK: o código do cookie de navegação, com prefixo "n-".
     * Sem o cookie (primeiro acesso), cria agora e já marca na requisição,
     * para o log de visitas, o funil e a localização desta mesma resposta
     * usarem o mesmo código.
     */
    public static function visitanteSemOk(Request $request): string
    {
        $codigo = $request->cookie(self::COOKIE_NAVEGACAO);

        if (! is_string($codigo) || preg_match('/^[a-f0-9]{32}$/', $codigo) !== 1) {
            $codigo = bin2hex(random_bytes(16));
            Cookie::queue(self::COOKIE_NAVEGACAO, $codigo, self::DURACAO_NAVEGACAO_MINUTOS);
            $request->cookies->set(self::COOKIE_NAVEGACAO, $codigo);
        }

        return 'n-'.$codigo;
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
