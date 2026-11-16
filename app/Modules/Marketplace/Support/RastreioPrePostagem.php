<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Services\Correios\CorreiosRastroService;
use Illuminate\Support\Carbon;

/**
 * Grava na pré-postagem o que o rastreio dos Correios diz dela (pedido do
 * Lira 2026-11-16). Antes, uma etiqueta que nunca foi postada e expirou
 * (AP623701379BR, pedido Amazon #3181, o power bank foi na caixa do #3180)
 * continuava "gerada" para sempre sem ninguém ver.
 *
 * Recebe o retorno de CorreiosRastroService::consultar() e não chama a API:
 * quem decide quando consultar é o comando correios:acompanhar-pre-postagens.
 */
class RastreioPrePostagem
{
    /** Objeto devolvido, suspenso ou a caminho de volta ao remetente. */
    private const DEVOLVIDA = '/devolv|devolu[cç][aã]o|suspens|retorno ao remetente|estorn/iu';

    /**
     * @param  array<string, mixed>  $rastreio  retorno de CorreiosRastroService::consultar()
     */
    public function aplicar(CorreiosPrePostagem $pre, array $rastreio, ?Carbon $agora = null): CorreiosPrePostagem
    {
        $agora ??= now();
        $eventos = is_array($rastreio['eventos'] ?? null) ? $rastreio['eventos'] : [];
        $ultimo = $rastreio['ultimo'] ?? null;

        $expiracao = collect($eventos)->first(fn ($e) => CorreiosRastroService::ehExpiracao($e['descricao'] ?? null));
        $expirou = $expiracao !== null || CorreiosRastroService::ehExpiracao($ultimo);

        $entregue = (bool) ($rastreio['entregue'] ?? false);
        $postado = (bool) ($rastreio['postado'] ?? false);

        $pre->rastreio_ultimo_evento = $ultimo !== null ? mb_substr($ultimo, 0, 255) : $pre->rastreio_ultimo_evento;
        $pre->rastreio_consultado_em = $agora;

        if ($eventos !== []) {
            $pre->rastreio_eventos = $eventos;
        }

        if ($postado && ($rastreio['postado_em'] ?? null)) {
            $pre->postada_em ??= $rastreio['postado_em'];
        }

        if ($entregue) {
            $pre->entregue_em = $rastreio['entregue_em'] ?? $pre->entregue_em ?? $agora;
            $pre->rastreio_situacao = CorreiosPrePostagem::SITUACAO_ENTREGUE;

            return $pre;
        }

        if ($expirou && ! $postado) {
            $pre->expirada_em ??= isset($expiracao['data'])
                ? Carbon::parse($expiracao['data'])->timezone(config('app.timezone'))
                : $agora;
            $pre->rastreio_situacao = CorreiosPrePostagem::SITUACAO_EXPIRADA;

            return $pre;
        }

        if ($ultimo !== null && preg_match(self::DEVOLVIDA, $ultimo) === 1) {
            $pre->rastreio_situacao = CorreiosPrePostagem::SITUACAO_DEVOLVIDA;

            return $pre;
        }

        $pre->rastreio_situacao = $postado
            ? CorreiosPrePostagem::SITUACAO_POSTADA
            : CorreiosPrePostagem::SITUACAO_PRE_POSTADA;

        return $pre;
    }
}
