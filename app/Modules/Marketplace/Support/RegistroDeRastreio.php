<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Marketplace\Models\Rastreio;
use App\Modules\Marketplace\Models\RastreioEvento;
use App\Services\Correios\CorreiosRastroService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Guarda o que o SRO Rastro devolveu de um objeto (pedido do Lira
 * 2026-10-19): a situação atual em `rastreios` e cada evento em
 * `rastreio_eventos`, sem duplicar (chave única por objeto, código, tipo e
 * data do evento). Objeto entregue ou devolvido à loja fica "encerrado" e
 * sai da consulta agendada.
 *
 * LGPD: recebedor, destinatário, remetente, carteiro e coordenadas que a API
 * devolve não são gravados.
 */
class RegistroDeRastreio
{
    public const SITUACOES = [
        'aguardando_postagem' => 'Aguardando postagem',
        'postado' => 'Postado',
        'em_transito' => 'A caminho',
        'saiu_para_entrega' => 'Saiu para entrega',
        'aguardando_retirada' => 'Aguardando retirada',
        'nao_entregue' => 'Entrega não realizada',
        'em_devolucao' => 'Voltando para a loja',
        'devolvido' => 'Devolvido à loja',
        'entregue' => 'Entregue',
        'erro' => 'Não encontrado nos Correios',
    ];

    /** Situações em que não adianta mais consultar. */
    public const FINAIS = ['entregue', 'devolvido'];

    /**
     * Grava o objeto e devolve quantos eventos eram novos.
     *
     * @param  array  $objeto  objeto cru da API (codObjeto, dtPrevista, eventos, mensagem)
     * @param  bool  $devolvido  entrega numa cidade diferente da do pedido (devolução ao remetente)
     */
    public function registrar(string $codigo, ?int $orderId, array $objeto, bool $devolvido = false): int
    {
        $rastreio = Rastreio::query()->firstOrCreate(['codigo' => strtoupper($codigo)], ['order_id' => $orderId]);
        $eventos = array_values(array_filter($objeto['eventos'] ?? [], fn ($e) => filled($e['codigo'] ?? null) && filled($e['dtHrCriado'] ?? null)));

        $agora = now();
        $linhas = array_map(fn (array $e) => [
            'rastreio_id' => $rastreio->id,
            'codigo' => Str::limit(strtoupper((string) $e['codigo']), 5, ''),
            'tipo' => Str::limit((string) ($e['tipo'] ?? ''), 5, ''),
            'ocorrido_em' => Carbon::parse($e['dtHrCriado'], 'America/Sao_Paulo'),
            'descricao' => Str::limit((string) ($e['descricao'] ?? ''), 252),
            'detalhe' => filled($e['detalhe'] ?? null) ? Str::limit(trim((string) $e['detalhe']), 252) : null,
            'unidade' => self::texto($e['unidade']['nome'] ?? ($e['unidade']['tipo'] ?? null), 120),
            'cidade' => self::texto($e['unidade']['endereco']['cidade'] ?? null, 80),
            'uf' => self::texto($e['unidade']['endereco']['uf'] ?? null, 2),
            'destino_cidade' => self::texto($e['unidadeDestino']['endereco']['cidade'] ?? null, 80),
            'destino_uf' => self::texto($e['unidadeDestino']['endereco']['uf'] ?? null, 2),
            'created_at' => $agora,
            'updated_at' => $agora,
        ], $eventos);

        $novos = $linhas === [] ? 0 : RastreioEvento::query()->insertOrIgnore($linhas);

        $r = CorreiosRastroService::interpretar($objeto);
        $situacao = $devolvido ? 'devolvido' : self::situacao($objeto);
        // Nunca anda para trás: se a API devolver menos eventos depois de
        // encerrado, a situação final fica.
        if (in_array($rastreio->situacao, self::FINAIS, true) && ! in_array($situacao, self::FINAIS, true)) {
            $situacao = $rastreio->situacao;
        }
        $ultimo = $eventos[0] ?? null;

        $rastreio->forceFill([
            'order_id' => $rastreio->order_id ?? $orderId,
            'situacao' => $situacao,
            'ultimo_evento' => $ultimo ? Str::limit((string) ($ultimo['descricao'] ?? ''), 252) : ($objeto['mensagem'] ?? $rastreio->ultimo_evento),
            'ultimo_evento_em' => $ultimo ? Carbon::parse($ultimo['dtHrCriado'], 'America/Sao_Paulo') : $rastreio->ultimo_evento_em,
            'previsao_entrega' => $r['previsao']?->toDateString() ?? $rastreio->previsao_entrega,
            'postado_em' => $rastreio->postado_em ?? ($r['postado'] ? $r['postado_em'] : null),
            'entregue_em' => $rastreio->entregue_em ?? ($situacao === 'entregue' ? $r['entregue_em'] : null),
            'encerrado_em' => $rastreio->encerrado_em ?? (in_array($situacao, self::FINAIS, true) ? $agora : null),
            'consultado_em' => $agora,
            'consultas' => $rastreio->consultas + 1,
            'ultimo_erro' => filled($objeto['mensagem'] ?? null) && $eventos === [] ? Str::limit((string) $objeto['mensagem'], 252) : null,
        ])->save();

        return $novos;
    }

    /** Falha da consulta (HTTP): guarda o erro sem mexer na situação. */
    public function registrarFalha(string $codigo, ?int $orderId, string $erro): void
    {
        $rastreio = Rastreio::query()->firstOrCreate(['codigo' => strtoupper($codigo)], ['order_id' => $orderId]);
        $rastreio->forceFill(['consultado_em' => now(), 'ultimo_erro' => Str::limit($erro, 252)])->save();
    }

    /** Situação atual pelo evento mais recente com significado conhecido. */
    public static function situacao(array $objeto): string
    {
        $eventos = $objeto['eventos'] ?? [];
        if ($eventos === []) {
            return filled($objeto['mensagem'] ?? null) ? 'erro' : 'aguardando_postagem';
        }

        // A API devolve do mais recente para o mais antigo.
        foreach ($eventos as $e) {
            $s = self::situacaoDoEvento((string) ($e['codigo'] ?? ''), (string) ($e['tipo'] ?? ''), (string) ($e['descricao'] ?? ''));
            if ($s !== null) {
                return $s;
            }
        }

        return 'em_transito';
    }

    /**
     * Situação que um evento indica, ou null quando o código não é conhecido.
     * BDE/BDI/BDR tipo 01 é entrega; os outros tipos são tentativa, retirada
     * ou devolução, lidos pela descrição.
     */
    public static function situacaoDoEvento(string $codigo, string $tipo, string $descricao): ?string
    {
        $codigo = strtoupper($codigo);
        $desc = mb_strtolower($descricao);

        if (in_array($codigo, ['BDE', 'BDI', 'BDR'], true)) {
            return match (true) {
                str_contains($desc, 'remetente') && (str_contains($desc, 'entregue') || str_contains($desc, 'devolvido')) => 'devolvido',
                $tipo === '01' || str_starts_with($desc, 'objeto entregue') => 'entregue',
                str_contains($desc, 'retirada') || str_contains($desc, 'retirar') => 'aguardando_retirada',
                str_contains($desc, 'devol') => 'em_devolucao',
                default => 'nao_entregue',
            };
        }

        return match (true) {
            $codigo === 'PO' => 'postado',
            $codigo === 'OEC' => 'saiu_para_entrega',
            in_array($codigo, ['LDI', 'LDE'], true) => 'aguardando_retirada',
            in_array($codigo, ['RO', 'DO', 'TRI', 'PAR', 'CO', 'CD', 'PMT', 'TRA'], true) => 'em_transito',
            $codigo === 'FC' && str_contains($desc, 'devol') => 'em_devolucao',
            $codigo === 'FC' => 'aguardando_postagem',
            default => null,
        };
    }

    private static function texto(mixed $valor, int $max): ?string
    {
        return filled($valor) ? mb_substr(trim((string) $valor), 0, $max) : null;
    }
}
