<?php

namespace App\Services\Correios;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Rastreio de objeto nos Correios (API SRO Rastro). Os Correios não mandam
 * webhook para o nosso contrato: o pedido do site é acompanhado por consulta
 * (loja:acompanhar-rastreio). Usa o token do Preço/Prazo, o único com acesso
 * à API (o da pré-postagem volta GTW-012).
 */
class CorreiosRastroService
{
    /** Eventos que não são movimento físico: só a etiqueta/pré-postagem. */
    private const SEM_MOVIMENTO = ['FC'];

    /** Entregue ao destinatário (BDE/BDI/BDR tipo 01). */
    private const ENTREGUE = ['BDE', 'BDI', 'BDR'];

    public function __construct(private readonly CorreiosTokenService $tokens)
    {
    }

    /**
     * `eventos` (2026-11-16): a lista toda, do mais recente para o mais
     * antigo, para o histórico da pré-postagem (correios:acompanhar-pre-postagens).
     *
     * @return array{postado: bool, postado_em: ?Carbon, entregue: bool, entregue_em: ?Carbon, recebedor: ?string, previsao: ?Carbon, ultimo: ?string, eventos: array<int, array{codigo: ?string, tipo: ?string, descricao: ?string, data: ?string, local: ?string}>}
     */
    public function consultar(string $codigo): array
    {
        $response = Http::withToken($this->tokens->tokenForPrecoPrazo())
            ->acceptJson()
            ->timeout(20)
            ->get(rtrim((string) config('services.correios.rastro_base_url'), '/').'/v1/objetos/'.urlencode($codigo), ['resultado' => 'T']);

        if ($response->failed()) {
            throw new RuntimeException("Rastreio dos Correios falhou (HTTP {$response->status()}): ".json_encode($response->json('msgs') ?? $response->body()));
        }

        $objeto = $response->json('objetos.0') ?? [];
        $eventos = $objeto['eventos'] ?? [];
        // "Etiqueta expirada" (2026-11-16, AP623701379BR) também não é
        // movimento: o objeto nunca chegou à agência.
        $movimento = array_values(array_filter($eventos, fn ($e) => ! in_array($e['codigo'] ?? '', self::SEM_MOVIMENTO, true)
            && ! self::ehExpiracao($e['descricao'] ?? null)));
        // A API devolve do mais recente para o mais antigo.
        $primeiro = end($movimento) ?: null;

        $entrega = collect($eventos)->first(fn ($e) => in_array($e['codigo'] ?? '', self::ENTREGUE, true) && ($e['tipo'] ?? '') === '01');

        return [
            'postado' => $movimento !== [],
            'postado_em' => isset($primeiro['dtHrCriado']) ? Carbon::parse($primeiro['dtHrCriado'], 'America/Sao_Paulo') : null,
            'entregue' => $entrega !== null,
            'entregue_em' => isset($entrega['dtHrCriado']) ? Carbon::parse($entrega['dtHrCriado'], 'America/Sao_Paulo') : null,
            // O SRO do nosso contrato não traz o nome de quem recebeu (conferido
            // em 17/10/2026); fica pronto caso passe a vir.
            'recebedor' => filled($entrega['recebedor']['nome'] ?? null) ? trim($entrega['recebedor']['nome']) : null,
            'previsao' => filled($objeto['dtPrevista'] ?? null) ? Carbon::parse($objeto['dtPrevista'], 'America/Sao_Paulo') : null,
            'ultimo' => $eventos[0]['descricao'] ?? null,
            'eventos' => array_map(fn ($e) => [
                'codigo' => $e['codigo'] ?? null,
                'tipo' => $e['tipo'] ?? null,
                'descricao' => $e['descricao'] ?? null,
                'data' => isset($e['dtHrCriado']) ? Carbon::parse($e['dtHrCriado'], 'America/Sao_Paulo')->toIso8601String() : null,
                'local' => self::local($e['unidade']['endereco'] ?? null),
            ], array_values($eventos)),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $endereco
     */
    private static function local(?array $endereco): ?string
    {
        $cidade = trim(implode('/', array_filter([$endereco['cidade'] ?? null, $endereco['uf'] ?? null])));

        return $cidade !== '' ? $cidade : null;
    }

    /** Evento de etiqueta vencida sem postagem ("Etiqueta expirada"). */
    public static function ehExpiracao(?string $descricao): bool
    {
        return $descricao !== null && preg_match('/expirad/iu', $descricao) === 1;
    }
}
