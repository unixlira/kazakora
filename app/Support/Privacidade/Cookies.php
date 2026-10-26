<?php

namespace App\Support\Privacidade;

use App\Http\Middleware\TrackSiteVisit;
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
 * - Visitante (kazakora_visitor): desde 2026-10-25 criado no primeiro
 *   acesso (garantirVisitante), para o histórico de páginas existir desde a
 *   entrada. Navegador completo, valor do clique e cookies de terceiros no
 *   log continuam só depois do OK.
 *
 * Aceite (gravado por registrarAceite()): desde 2026-10-25 a faixa de cookies
 * voltou ("ao navegar você concorda"); o OK ou 3 s sem clique gravam o aceite
 * (AvisoCookies.vue → /cookies/aceitar). O "Finalizar pedido" também aceita.
 *
 * Mudou o que a loja coleta? Troque VERSAO e atualize as políticas.
 */
class Cookies
{
    // 2026-10-18: API de Conversões da Meta (o servidor também manda os eventos).
    // 2026-10-24: aceite na frase do checkout, no lugar do aviso flutuante.
    // 2026-10-25: faixa de volta, aceite ao navegar (OK ou 3 s sem clique).
    public const VERSAO = '2026-10-25';

    /** OKs anteriores para os mesmos cookies (aviso de 2026-10-18): continuam valendo. */
    public const VERSOES_ANTERIORES = ['2026-10-18', '2026-10-24'];

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

    /** Chave na sessão com o código do visitante, para quando o navegador recusa o cookie. */
    public const SESSAO_VISITANTE = 'kazakora_visitante';

    public static function aceitou(Request $request): bool
    {
        return in_array($request->cookie(self::COOKIE_OK), [self::VERSAO, ...self::VERSOES_ANTERIORES], true);
    }

    /**
     * Cria o kazakora_visitor já no primeiro acesso, antes de qualquer aceite
     * (pedido 2026-10-25: o histórico de navegação do visitante, todas as
     * páginas, tem que existir desde a entrada no site, não só depois do
     * checkout). Código aleatório do próprio site, sem dado pessoal e sem
     * terceiro; base legal: legítimo interesse (art. 7º IX), descrito na
     * Política de Cookies. O mesmo código fica na sessão para o navegador
     * que recusa cookie continuar com um visitante só.
     */
    public static function garantirVisitante(Request $request): string
    {
        $visitante = self::visitante($request);

        if (! is_string($request->cookie(self::COOKIE_VISITANTE)) || $request->cookie(self::COOKIE_VISITANTE) === '') {
            if (str_starts_with($visitante, 's-')) {
                $visitante = (string) Str::uuid();
            }
            Cookie::queue(self::COOKIE_VISITANTE, $visitante, self::DURACAO_MINUTOS);
            $request->cookies->set(self::COOKIE_VISITANTE, $visitante);
        }

        if ($request->hasSession()) {
            $request->session()->put(self::SESSAO_VISITANTE, $visitante);
        }

        return $visitante;
    }

    /**
     * Visitante da loja, o mesmo em site_visits, checkout, lead e
     * geolocalização: o cookie kazakora_visitor; sem ele, o código guardado
     * na sessão; sem os dois, o código tirado da sessão (s-...). Nunca cria cookie.
     */
    public static function visitante(Request $request): string
    {
        $cookie = $request->cookie(self::COOKIE_VISITANTE);
        if (is_string($cookie) && $cookie !== '') {
            return substr($cookie, 0, 36);
        }

        if (! $request->hasSession()) {
            return 's-'.substr(hash('sha256', (string) $request->ip().$request->userAgent()), 0, 34);
        }

        $guardado = $request->session()->get(self::SESSAO_VISITANTE);

        return is_string($guardado) && $guardado !== ''
            ? substr($guardado, 0, 36)
            : 's-'.substr(hash('sha256', $request->session()->getId()), 0, 34);
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
            self::criarFbc($request);
        }

        return $visitante;
    }

    /** 90 dias, o mesmo prazo que o Pixel usa no _fbc. */
    private const DURACAO_FBC_MINUTOS = 60 * 24 * 90;

    /**
     * _fbc do clique de anúncio guardado na sessão (TrackSiteVisit::guardarFbclid),
     * no formato da Meta (fb.1.<hora do clique em ms>.<fbclid>), no domínio
     * da loja inteiro (.kazakora.com, vale também no seguro.) e sem
     * criptografia (bootstrap/app.php), para o Pixel ler. Não troca um _fbc
     * que o Pixel já criou. Pedido 2026-10-25: com o aceite em 3 s, quem
     * clicava em Comprar antes saía sem o vínculo com o anúncio.
     */
    private static function criarFbc(Request $request): void
    {
        $fbc = self::fbcDaSessao($request);

        if ($fbc === null || (string) ($request->cookie('_fbc') ?? self::doVisitante($request)['_fbc'] ?? '') !== '') {
            return;
        }

        $dominio = preg_replace('/^(www|seguro)\./', '', strtolower($request->getHost()));
        Cookie::queue(Cookie::make('_fbc', $fbc, self::DURACAO_FBC_MINUTOS, '/', '.'.$dominio, true, false, false, 'lax'));
    }

    /** _fbc montado do fbclid da sessão, ou null. Também usado pela API de Conversões. */
    public static function fbcDaSessao(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $clique = $request->session()->get(TrackSiteVisit::SESSAO_FBCLID);

        return is_array($clique) && is_string($clique['valor'] ?? null) && is_int($clique['em'] ?? null)
            ? 'fb.1.'.$clique['em'].'.'.$clique['valor']
            : null;
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
