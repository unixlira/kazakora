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

    /** Objeto postado (PO): marca a data da postagem. */
    private const POSTADO = 'PO';

    /** Entregue ao destinatário (BDE/BDI/BDR tipo 01). */
    private const ENTREGUE = ['BDE', 'BDI', 'BDR'];

    /** Saiu para entrega ao destinatário (OEC/01). */
    private const SAIU_PARA_ENTREGA = 'OEC';

    /** Aguardando retirada no endereço indicado (LDI/01). */
    private const AGUARDANDO_RETIRADA = 'LDI';

    public function __construct(private readonly CorreiosTokenService $tokens)
    {
    }

    /**
     * @return array{postado: bool, postado_em: ?Carbon, em_entrega: bool, saiu_em: ?Carbon, aguardando_retirada: bool, retirada_em: ?Carbon, retirada_local: ?string, entregue: bool, entregue_em: ?Carbon, entregue_cidade: ?string, recebedor: ?string, previsao: ?Carbon, ultimo: ?string}
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
        $movimento = array_values(array_filter($eventos, fn ($e) => ! in_array($e['codigo'] ?? '', self::SEM_MOVIMENTO, true)));
        // A API devolve do mais recente para o mais antigo. A data da postagem
        // vem do evento PO; sem ele, do primeiro movimento físico.
        $postagem = collect($movimento)->last(fn ($e) => ($e['codigo'] ?? '') === self::POSTADO) ?? (end($movimento) ?: null);

        $entrega = collect($eventos)->first(fn ($e) => in_array($e['codigo'] ?? '', self::ENTREGUE, true) && ($e['tipo'] ?? '') === '01');
        // Saiu para entrega e aguardando retirada só valem enquanto são o
        // último evento: uma tentativa sem sucesso (BDE/20) ou a entrega
        // depois encerram a etapa.
        $atual = $movimento[0] ?? null;
        $etapaAtual = fn (string $codigo) => $entrega === null && ($atual['codigo'] ?? '') === $codigo && ($atual['tipo'] ?? '') === '01';
        $emEntrega = $etapaAtual(self::SAIU_PARA_ENTREGA);
        $retirada = $etapaAtual(self::AGUARDANDO_RETIRADA);
        $local = $retirada ? trim(implode(', ', array_filter([$atual['unidade']['nome'] ?? null, $atual['unidade']['endereco']['cidade'] ?? null]))) : '';

        return [
            'postado' => $movimento !== [],
            'postado_em' => isset($postagem['dtHrCriado']) ? Carbon::parse($postagem['dtHrCriado'], 'America/Sao_Paulo') : null,
            'em_entrega' => $emEntrega,
            'saiu_em' => $emEntrega && isset($atual['dtHrCriado']) ? Carbon::parse($atual['dtHrCriado'], 'America/Sao_Paulo') : null,
            'aguardando_retirada' => $retirada,
            'retirada_em' => $retirada && isset($atual['dtHrCriado']) ? Carbon::parse($atual['dtHrCriado'], 'America/Sao_Paulo') : null,
            'retirada_local' => $local !== '' ? $local : null,
            'entregue' => $entrega !== null,
            'entregue_em' => isset($entrega['dtHrCriado']) ? Carbon::parse($entrega['dtHrCriado'], 'America/Sao_Paulo') : null,
            // Cidade da unidade que entregou: devolução ao remetente também
            // aparece como BDE/01 "entregue ao destinatário" (AP475425146BR).
            'entregue_cidade' => filled($entrega['unidade']['endereco']['cidade'] ?? null) ? trim($entrega['unidade']['endereco']['cidade']) : null,
            // O SRO do nosso contrato não traz o nome de quem recebeu (conferido
            // em 17/10/2026); fica pronto caso passe a vir.
            'recebedor' => filled($entrega['recebedor']['nome'] ?? null) ? trim($entrega['recebedor']['nome']) : null,
            'previsao' => filled($objeto['dtPrevista'] ?? null) ? Carbon::parse($objeto['dtPrevista'], 'America/Sao_Paulo') : null,
            'ultimo' => $eventos[0]['descricao'] ?? null,
        ];
    }
}
