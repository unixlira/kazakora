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
 * Uma chamada só (ranking geral, 20 produtos) e cache de 24 h: o Kalodata é
 * pago por crédito e tem teto diário (docs/kalodata-api.md). Por isso o
 * botão "Consultar de novo" do Radar não força nova consulta aqui.
 */
class MaisVendidosTikTok
{
    public const LIMITE = 20;

    public const PERIODO = 'last7Day';

    private const CACHE_HORAS = 24;

    public function __construct(private readonly KalodataService $kalodata) {}

    public function analisar(): array
    {
        if (! $this->kalodata->configurado()) {
            return ['configurado' => false, 'produtos' => [], 'atualizado_em' => null, 'erro' => null];
        }

        try {
            return Cache::remember('oportunidades:tiktok:'.self::PERIODO, now()->addHours(self::CACHE_HORAS), fn () => $this->coletar());
        } catch (KalodataException $e) {
            report($e);
            $erro = match ($e->tipo) {
                'teto' => 'O limite diário de consultas ao Kalodata acabou. Volta amanhã.',
                'limite' => 'O Kalodata recusou por falta de crédito ou limite do plano.',
                'auth' => 'O Kalodata recusou a chave de acesso.',
                default => 'Não foi possível consultar o Kalodata agora. Tente de novo mais tarde.',
            };

            return ['configurado' => true, 'produtos' => [], 'atualizado_em' => null, 'erro' => $erro];
        } catch (Throwable $e) {
            report($e);

            return ['configurado' => true, 'produtos' => [], 'atualizado_em' => null,
                'erro' => 'Não foi possível consultar o Kalodata agora. Tente de novo mais tarde.'];
        }
    }

    private function coletar(): array
    {
        $produtos = [];
        foreach ($this->kalodata->rank('product', [], self::PERIODO, 1, self::LIMITE) as $p) {
            $produtos[] = [
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

        return ['configurado' => true, 'produtos' => $produtos, 'atualizado_em' => now()->toIso8601String(), 'erro' => null];
    }
}
