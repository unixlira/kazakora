<?php

namespace App\Modules\Marketplace\Support\Oportunidades;

use App\Services\Kalodata\Exceptions\KalodataException;
use App\Services\Kalodata\KalodataService;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Radar de Oportunidades (pedido do Lira 2026-10-12): produtos que mais
 * faturaram no TikTok Shop Brasil nos últimos 7 dias, pelo Kalodata.
 *
 * Ranking geral completo (pedido do Lira 2026-10-12: não só os 20
 * primeiros): o /rank devolve no máximo 100 itens por página e não informa
 * o total, então busca página a página (KALODATA_RADAR_PAGINAS, padrão 10 =
 * 1.000 produtos), parando na primeira página incompleta. Cada página é uma
 * chamada, uma vez por dia (cache de 24 h): o Kalodata é pago por crédito e
 * tem teto diário (docs/kalodata-api.md). Por isso o botão "Consultar de
 * novo" do Radar não força nova consulta aqui. A tela pagina e filtra a
 * lista sem nova consulta.
 */
class MaisVendidosTikTok
{
    public const POR_PAGINA = 100;

    public const PERIODO = 'last7Day';

    private const CACHE_HORAS = 24;

    /** Se uma página do meio falhar, o que veio fica só 1 h no cache. */
    private const CACHE_HORAS_PARCIAL = 1;

    public function __construct(private readonly KalodataService $kalodata) {}

    public function analisar(): array
    {
        if (! $this->kalodata->configurado()) {
            return ['configurado' => false, 'produtos' => [], 'atualizado_em' => null, 'erro' => null];
        }

        $chave = 'oportunidades:tiktok:'.self::PERIODO.':completo';
        $guardado = Cache::get($chave);
        if (is_array($guardado)) {
            return $guardado;
        }

        try {
            [$resultado, $completo] = $this->coletar();
        } catch (KalodataException $e) {
            report($e);

            return ['configurado' => true, 'produtos' => [], 'atualizado_em' => null, 'erro' => self::mensagem($e)];
        } catch (Throwable $e) {
            report($e);

            return ['configurado' => true, 'produtos' => [], 'atualizado_em' => null,
                'erro' => 'Não foi possível consultar o Kalodata agora. Tente de novo mais tarde.'];
        }

        Cache::put($chave, $resultado, now()->addHours($completo ? self::CACHE_HORAS : self::CACHE_HORAS_PARCIAL));

        return $resultado;
    }

    /**
     * Página a página. Erro na primeira sobe; erro numa página seguinte
     * devolve o que já veio (completo = false).
     *
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function coletar(): array
    {
        $produtos = [];
        $completo = true;

        $paginas = max(1, (int) config('services.kalodata.radar_paginas', 10));

        for ($pagina = 1; $pagina <= $paginas; $pagina++) {
            try {
                $lote = $this->kalodata->rank('product', [], self::PERIODO, $pagina, self::POR_PAGINA);
            } catch (KalodataException $e) {
                if ($pagina === 1) {
                    throw $e;
                }
                report($e);
                $completo = false;
                break;
            }

            $this->juntar($produtos, $lote);

            if (count($lote) < self::POR_PAGINA) {
                break;
            }
        }

        return [['configurado' => true, 'produtos' => array_values($produtos), 'atualizado_em' => now()->toIso8601String(), 'erro' => null], $completo];
    }

    /**
     * @param  array<string, array<string, mixed>>  $produtos  por id, para não repetir produto entre páginas
     * @param  array<int, array<string, mixed>>  $lote
     */
    private function juntar(array &$produtos, array $lote): void
    {
        foreach ($lote as $p) {
            $id = (string) ($p['product_id'] ?? '');
            if ($id === '' || isset($produtos[$id])) {
                continue;
            }
            $produtos[$id] = [
                'id' => (string) ($p['product_id'] ?? ''),
                'posicao' => count($produtos) + 1,
                'nome' => (string) ($p['product_name'] ?? ''),
                'imagem' => $p['master_image_url'] ?? null,
                'receita' => isset($p['revenue']) ? (float) $p['revenue'] : null,
                'vendas' => isset($p['sales_volumn']) ? (int) $p['sales_volumn'] : null,
                'preco' => isset($p['unit_price']) ? (float) $p['unit_price'] : null,
                'crescimento' => is_numeric($p['revenue_growth_rate'] ?? null) ? (float) $p['revenue_growth_rate'] : null,
                'comissao' => is_numeric($p['commission_rate'] ?? null) ? (float) $p['commission_rate'] : null,
                'lancamento' => $p['launch_date'] ?? null,
            ];
        }
    }

    private static function mensagem(KalodataException $e): string
    {
        return match ($e->tipo) {
            'teto' => 'O limite diário de consultas ao Kalodata acabou. Volta amanhã.',
            'limite' => 'O Kalodata recusou por falta de crédito ou limite do plano.',
            'auth' => 'O Kalodata recusou a chave de acesso.',
            default => 'Não foi possível consultar o Kalodata agora. Tente de novo mais tarde.',
        };
    }
}
