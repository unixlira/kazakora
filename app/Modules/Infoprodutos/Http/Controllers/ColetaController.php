<?php

namespace App\Modules\Infoprodutos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\TrackSiteVisit;
use App\Modules\Analytics\Support\GeoDoVisitante;
use App\Modules\Infoprodutos\Models\InfoprodutoEvento;
use App\Modules\Infoprodutos\Support\EquipeDaLoja;
use App\Modules\Infoprodutos\Support\OrigemInfoproduto;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api-infoprodutos/evento (pedido do Lira em 2026-11-19): o script
 * das páginas de infoproduto (digitalmktseller.com, elcodigodivinoamen.com)
 * manda em lote o que a pessoa fez na página, por navigator.sendBeacon
 * (JSON em text/plain, sem preflight).
 *
 * Privacidade (docs/privacidade-e-cookies.md, seção Infoprodutos):
 *  - Nenhum cookie é lido nem criado; a sessão é um código aleatório que o
 *    navegador guarda só na aba (sessionStorage).
 *  - O IP NÃO é gravado: só o país (CF-IPCountry pela Cloudflare ou a
 *    base DB-IP do servidor, consultada em memória).
 *  - Lista FECHADA de eventos e campos; qualquer coisa fora dela = 422.
 *
 * Corpo:
 *   {"s":"<sessão>","p":"<produto>","pg":"landing|oferta|oferta-2","l":"es|pt|en",
 *    "e":[{"t":"<tipo>","v":<valor>,"ms":<int>}],
 *    (clique: {"t":"clk","x":%|null,"y":px|null,"w","k","sec","el","n","o":"t"|"p"}),
 *    "pv":{"r":"<host>","us":..,"um":..,"uc":..,"ut":..,"uk":..,"fb":0|1}}
 */
class ColetaController extends Controller
{
    public const LIMITE_BYTES = 6144;

    public const LIMITE_EVENTOS = 30;

    /** Tipo => valores aceitos (null = sem valor; 'int' = inteiro; 'texto' = até 40 caracteres). */
    public const TIPOS = [
        'scroll' => [25, 50, 75, 100],
        'play' => null,
        'video' => [25, 50, 75, 100],
        'preview' => 'texto',
        'faq' => 'int',
        'buy' => ['hero', 'meio', 'final', 'barra', 'oferta', 'produto'],
        'recusa' => null,
        'exit' => 'int',
        'clk' => 'clique',
        // Interação com nome estável (data-kzm / window.kzm.acao), ex.: variacao:kit-2.
        'acao' => 'nome',
    ];

    public const NOME = '/^[a-z0-9:_-]{1,40}$/';

    public const PAGINAS = ['landing', 'oferta', 'oferta-2', 'produto'];

    /** Página de produto das lojas: kz/<slug> (kazakora.com) ou fk/<slug> (ferrakora.com). */
    public const PRODUTO_DE_LOJA = '/^(kz|fk)\/[a-z0-9-]{1,120}$/';

    public const IDIOMAS = ['es', 'pt', 'en'];

    public function registrar(Request $request): Response
    {
        if (! OrigemInfoproduto::permitida($request)) {
            return response()->noContent(403);
        }

        $corpo = (string) $request->getContent();
        if (strlen($corpo) > self::LIMITE_BYTES) {
            return response()->noContent(413);
        }

        $dados = self::validar(json_decode($corpo, true));
        if ($dados === null) {
            return response()->noContent(422);
        }

        $userAgent = (string) $request->userAgent();
        if (! str_starts_with($userAgent, 'Mozilla/') || preg_match(TrackSiteVisit::BOT_PATTERN, $userAgent) === 1) {
            return response()->noContent();
        }

        $base = [
            'sessao' => $dados['s'],
            'produto' => $dados['p'],
            'pagina' => $dados['pg'],
            'idioma' => $dados['l'],
        ];
        $agora = now();
        $linhas = [];

        // Equipe (EquipeDaLoja, 2026-11-20): o IP só é conferido, nunca gravado;
        // a visita entra como teste e fica fora da tela Comportamento.
        $daEquipe = rescue(fn () => EquipeDaLoja::ip($request->ip()), false, false);
        $teste = false;
        if ($dados['pv'] !== null) {
            $pv = $dados['pv'];
            $teste = $daEquipe || ($pv['us'] ?? null) === config('infoprodutos.utm_teste');
            $linhas[] = $base + [
                'tipo' => 'pageview',
                'valor' => null,
                'ms' => 0,
                'x' => null, 'y' => null, 'largura' => null, 'alvo' => null, 'secao' => null, 'elemento' => null, 'nome' => null, 'origem' => null, 'rolagem_max' => null,
                'pais' => self::pais($request),
                'dispositivo' => TrackSiteVisit::dispositivo($userAgent),
                'referrer' => $pv['r'] ?? null,
                'utm_source' => $pv['us'] ?? null,
                'utm_medium' => $pv['um'] ?? null,
                'utm_campaign' => $pv['uc'] ?? null,
                'utm_content' => $pv['ut'] ?? null,
                'utm_term' => $pv['uk'] ?? null,
                'fbclid' => (bool) ($pv['fb'] ?? false),
                'teste' => $teste,
                'created_at' => $agora,
            ];
        } else {
            // A marca de teste vale para a sessão inteira.
            $teste = $daEquipe || InfoprodutoEvento::query()->where('sessao', $dados['s'])->where('tipo', 'pageview')->where('teste', true)->exists();
        }

        foreach ($dados['e'] as $evento) {
            $linhas[] = $base + [
                'tipo' => $evento['t'],
                'valor' => isset($evento['v']) ? (string) $evento['v'] : null,
                'ms' => $evento['ms'] ?? null,
                'x' => $evento['x'] ?? null,
                'y' => $evento['y'] ?? null,
                'largura' => $evento['w'] ?? null,
                'alvo' => $evento['k'] ?? null,
                'secao' => $evento['sec'] ?? null,
                'elemento' => $evento['el'] ?? null,
                'nome' => $evento['n'] ?? null,
                'origem' => $evento['o'] ?? null,
                'rolagem_max' => $evento['sm'] ?? null,
                'pais' => null,
                'dispositivo' => null,
                'referrer' => null,
                'utm_source' => null,
                'utm_medium' => null,
                'utm_campaign' => null,
                'utm_content' => null,
                'utm_term' => null,
                'fbclid' => false,
                'teste' => $teste,
                'created_at' => $agora,
            ];
        }

        if ($linhas !== []) {
            InfoprodutoEvento::query()->insert($linhas);
        }

        return response()->noContent();
    }

    /**
     * Corpo válido ou null. Nada fora da lista passa.
     *
     * @return array{s: string, p: string, pg: string, l: ?string, e: list<array<string, mixed>>, pv: ?array<string, mixed>}|null
     */
    public static function validar(mixed $dados): ?array
    {
        if (! is_array($dados)) {
            return null;
        }
        if (array_diff(array_keys($dados), ['s', 'p', 'pg', 'l', 'e', 'pv']) !== []) {
            return null;
        }

        $s = $dados['s'] ?? null;
        $p = $dados['p'] ?? null;
        $pg = $dados['pg'] ?? 'landing';
        $l = $dados['l'] ?? null;
        $e = $dados['e'] ?? [];
        $pv = $dados['pv'] ?? null;

        if (! is_string($s) || preg_match('/^[A-Za-z0-9]{16,32}$/', $s) !== 1) {
            return null;
        }
        if (! is_string($p) || ! self::produtoValido($p)) {
            return null;
        }
        if (! in_array($pg, self::PAGINAS, true) || ($l !== null && ! in_array($l, self::IDIOMAS, true))) {
            return null;
        }
        if (! is_array($e) || ! array_is_list($e) || count($e) > self::LIMITE_EVENTOS || ($e === [] && $pv === null)) {
            return null;
        }

        $eventos = [];
        foreach ($e as $evento) {
            $valido = self::evento($evento);
            if ($valido === null) {
                return null;
            }
            $eventos[] = $valido;
        }

        if ($pv !== null) {
            $pv = self::pageview($pv);
            if ($pv === null) {
                return null;
            }
        }

        return ['s' => $s, 'p' => $p, 'pg' => $pg, 'l' => $l, 'e' => $eventos, 'pv' => $pv];
    }

    public static function produtoValido(string $p): bool
    {
        return array_key_exists($p, (array) config('infoprodutos.produtos')) || preg_match(self::PRODUTO_DE_LOJA, $p) === 1;
    }

    /** @return array<string, int|string>|null */
    private static function evento(mixed $evento): ?array
    {
        if (! is_array($evento)) {
            return null;
        }
        $t = $evento['t'] ?? null;
        if (! is_string($t) || ! array_key_exists($t, self::TIPOS)) {
            return null;
        }
        $permitidos = match ($t) {
            'clk' => ['t', 'x', 'y', 'w', 'k', 'sec', 'el', 'n', 'o', 'ms'],
            'exit' => ['t', 'v', 'ms', 'sm'],
            default => ['t', 'v', 'ms'],
        };
        if (array_diff(array_keys($evento), $permitidos) !== []) {
            return null;
        }

        $saida = ['t' => $t];
        if ($t === 'clk') {
            $clique = self::clique($evento);
            if ($clique === null) {
                return null;
            }
            $saida += $clique;
        }
        if ($t === 'exit' && array_key_exists('sm', $evento)) {
            if (! is_int($evento['sm']) || $evento['sm'] < 0 || $evento['sm'] > 100) {
                return null;
            }
            $saida['sm'] = $evento['sm'];
        }
        $regra = self::TIPOS[$t];
        $v = $evento['v'] ?? null;

        if (is_array($regra)) {
            if (! in_array($v, $regra, true)) {
                return null;
            }
            $saida['v'] = $v;
        } elseif ($regra === 'int') {
            if (! is_int($v) || $v < 0 || $v > 3600) {
                return null;
            }
            $saida['v'] = $v;
        } elseif ($regra === 'nome') {
            if (! is_string($v) || preg_match(self::NOME, $v) !== 1) {
                return null;
            }
            $saida['v'] = $v;
        } elseif ($regra === 'texto') {
            if ($v !== null) {
                if (! is_string($v) || preg_match('/^[\p{L}\p{N} _.-]{1,40}$/u', $v) !== 1) {
                    return null;
                }
                $saida['v'] = $v;
            }
        } elseif ($v !== null) {
            return null;
        }

        if (array_key_exists('ms', $evento)) {
            $ms = $evento['ms'];
            if (! is_int($ms) || $ms < 0 || $ms > 86_400_000) {
                return null;
            }
            $saida['ms'] = $ms;
        }

        return $saida;
    }

    /**
     * Clique do mapa: posição, largura da janela, tipo de alvo, seção, texto
     * curto do elemento (o script nunca manda o que foi digitado) e origem.
     *
     * Posição desconhecida é null, nunca 0: x/y ausentes ou null (alvo
     * escondido) e o 0,0 do script antigo, que era o clique sem ponteiro
     * (teclado ou el.click() de robô: 80 dos 108 cliques do Jarvis em
     * 2026-11-19, todos do robô de revisão do Meta), viram null.
     * Origem: null = mouse/toque; 't' = teclado; 'p' = clique de programa
     * (el.click()/dispatchEvent, robô), com o centro do alvo como posição.
     *
     * @param  array<string, mixed>  $e
     * @return array{x: ?int, y: ?int, w: int, k: string, sec: ?string, el: ?string, n: ?string, o: ?string}|null
     */
    private static function clique(array $e): ?array
    {
        $x = $e['x'] ?? null;
        $y = $e['y'] ?? null;
        $w = $e['w'] ?? null;
        $k = $e['k'] ?? null;
        $o = $e['o'] ?? null;
        if (($x !== null && (! is_int($x) || $x < 0 || $x > 100)) || ($y !== null && (! is_int($y) || $y < 0 || $y > 50000))
            || ! is_int($w) || $w < 200 || $w > 4000 || ! in_array($k, ['a', 'b', 'm'], true) || ! in_array($o, [null, 't', 'p'], true)) {
            return null;
        }
        if ($x === null || $y === null || ($x === 0 && $y === 0)) {
            $x = $y = null;
        }

        $sec = $e['sec'] ?? '';
        if (! is_string($sec) || ($sec !== '' && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $sec) !== 1)) {
            return null;
        }
        $el = $e['el'] ?? '';
        if (! is_string($el) || mb_strlen($el) > 40 || preg_match('/[\x00-\x1F<>]/', $el) === 1) {
            return null;
        }

        $n = $e['n'] ?? '';
        if (! is_string($n) || ($n !== '' && preg_match(self::NOME, $n) !== 1)) {
            return null;
        }

        return ['x' => $x, 'y' => $y, 'w' => $w, 'k' => $k, 'sec' => $sec !== '' ? $sec : null,
            'el' => trim($el) !== '' ? trim($el) : null, 'n' => $n !== '' ? $n : null, 'o' => $o];
    }

    /** @return array<string, mixed>|null */
    private static function pageview(mixed $pv): ?array
    {
        if (! is_array($pv) || array_diff(array_keys($pv), ['r', 'us', 'um', 'uc', 'ut', 'uk', 'fb']) !== []) {
            return null;
        }

        $saida = [];
        foreach (['us', 'um', 'uc', 'ut', 'uk'] as $campo) {
            $valor = $pv[$campo] ?? null;
            if ($valor === null || $valor === '') {
                continue;
            }
            if (! is_string($valor) || mb_strlen($valor) > 150 || preg_match('/[\x00-\x1F<>]/', $valor) === 1) {
                return null;
            }
            $saida[$campo] = $valor;
        }

        $r = $pv['r'] ?? '';
        if (! is_string($r) || ($r !== '' && preg_match('/^[a-z0-9.-]{1,100}$/i', $r) !== 1)) {
            return null;
        }
        $saida['r'] = $r !== '' ? strtolower($r) : null;

        $fb = $pv['fb'] ?? 0;
        if (! in_array($fb, [0, 1, true, false], true)) {
            return null;
        }
        $saida['fb'] = (bool) $fb;

        return $saida;
    }

    /**
     * País da visita: CF-IPCountry quando a chamada chega de IP da Cloudflare
     * e, senão, a base DB-IP do próprio servidor (GeoDoVisitante::localizar,
     * a mesma das visitas da loja). O IP é usado só nesta consulta, em
     * memória, e nunca é gravado.
     */
    private static function pais(Request $request): ?string
    {
        try {
            $pais = strtoupper((string) (app(GeoDoVisitante::class)->localizar($request)['pais'] ?? ''));
        } catch (\Throwable) {
            return null;
        }

        return preg_match('/^[A-Z]{2}$/', $pais) === 1 && ! in_array($pais, ['XX', 'T1'], true) ? $pais : null;
    }
}
