<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\TrackSiteVisit;
use App\Modules\Analytics\Models\CheckoutEvento;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /finalizacao/eventos (pedido do Lira 2026-10-23): o checkout manda
 * em lote o que a pessoa fez na tela (navigator.sendBeacon ao sair).
 *
 * - Corpo: JSON em text/plain (o sendBeacon não manda cabeçalho próprio),
 *   no máximo 8 KB e 30 eventos: {"pagina": "...", "eventos": [{...}]}.
 * - Lista FECHADA de eventos e campos; qualquer chave fora do previsto
 *   recusa o lote inteiro (422). Assim nenhum valor digitado entra.
 * - Fora do CSRF (bootstrap/app.php) porque o sendBeacon não manda o
 *   X-XSRF-TOKEN e a página não tem o token em texto. Em troca: só aceita
 *   chamada da própria loja (Origin/Sec-Fetch-Site), tem limite por minuto e
 *   não muda nada da conta nem do pedido de ninguém, só grava contagem.
 * - Robô e acesso por outro domínio: responde 204 e não grava.
 */
class CheckoutEventosController extends Controller
{
    public const LIMITE_BYTES = 8192;

    public const LIMITE_EVENTOS = 30;

    public function store(Request $request): Response
    {
        // Resposta direta (sem abort): o 403 da loja vira tela de login.
        if (! $this->mesmaOrigem($request)) {
            return response()->noContent(403);
        }

        $corpo = (string) $request->getContent();
        if (strlen($corpo) > self::LIMITE_BYTES) {
            return response()->noContent(413);
        }

        $dados = json_decode($corpo, true);
        $eventos = $this->validar($dados);
        if ($eventos === null) {
            return response()->noContent(422);
        }

        if (! TrackSiteVisit::dominioDaLoja($request) || TrackSiteVisit::naoENavegador($request)) {
            return response()->noContent();
        }

        $visitante = CheckoutEvento::visitante($request);
        $base = [
            'visitor_id' => $visitante,
            'user_id' => $request->user()?->id,
            'pagina_id' => $dados['pagina'],
            'dispositivo' => TrackSiteVisit::dispositivo((string) $request->userAgent()),
        ];
        $origem = collect($eventos)->contains('evento', 'abriu') ? CheckoutEvento::origemDaVisita($visitante) : null;

        foreach ($eventos as $evento) {
            CheckoutEvento::create([
                ...$base,
                'evento' => $evento['evento'],
                'campo' => $evento['campo'] ?? null,
                'valor' => $evento['valor'] ?? $evento['segundos'] ?? null,
                'rolagem' => $evento['rolagem'] ?? null,
                'origem' => $evento['evento'] === 'abriu' ? $origem : null,
            ]);
        }

        return response()->noContent();
    }

    /** Navegador de outro site não manda evento para cá. */
    private function mesmaOrigem(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        if ($site !== null && ! in_array($site, ['same-origin', 'none'], true)) {
            return false;
        }

        $origem = $request->headers->get('Origin');

        return $origem === null || parse_url($origem, PHP_URL_HOST) === $request->getHost();
    }

    /**
     * Lote válido ou null. Cada evento só pode ter as chaves previstas para
     * ele, com valores da lista fechada.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function validar(mixed $dados): ?array
    {
        if (! is_array($dados) || array_diff(array_keys($dados), ['pagina', 'eventos']) !== []
            || ! is_string($dados['pagina'] ?? null) || preg_match('/^[a-z0-9]{8,16}$/', $dados['pagina']) !== 1
            || ! is_array($dados['eventos'] ?? null) || ! array_is_list($dados['eventos'])
            || $dados['eventos'] === [] || count($dados['eventos']) > self::LIMITE_EVENTOS) {
            return null;
        }

        foreach ($dados['eventos'] as $evento) {
            if (! is_array($evento) || ! $this->eventoValido($evento)) {
                return null;
            }
        }

        return $dados['eventos'];
    }

    /** @param  array<string, mixed>  $e */
    private function eventoValido(array $e): bool
    {
        $nome = $e['evento'] ?? null;
        if (! is_string($nome) || ! in_array($nome, CheckoutEvento::EVENTOS_DO_NAVEGADOR, true)) {
            return false;
        }

        $chaves = array_keys($e);
        $so = fn (array $permitidas) => array_diff($chaves, ['evento', ...$permitidas]) === [];
        $campoEm = fn (array $lista) => is_string($e['campo'] ?? null) && in_array($e['campo'], $lista, true);
        $inteiro = fn (string $chave, int $max) => is_int($e[$chave] ?? null) && $e[$chave] >= 0 && $e[$chave] <= $max;

        return match ($nome) {
            'rolagem' => $so(['valor']) && is_int($e['valor'] ?? null) && in_array($e['valor'], CheckoutEvento::MARCOS_ROLAGEM, true),
            'foco', 'preencheu' => $so(['campo']) && $campoEm(CheckoutEvento::CAMPOS),
            'pagamento' => $so(['campo']) && $campoEm(CheckoutEvento::FORMAS_PAGAMENTO),
            'erro' => $so(['campo']) && $campoEm([...CheckoutEvento::CAMPOS, ...CheckoutEvento::ERROS_EXTRAS]),
            'saiu' => $so(['segundos', 'rolagem']) && $inteiro('segundos', 86400) && $inteiro('rolagem', 100),
            default => $so([]),
        };
    }
}
