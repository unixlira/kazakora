<?php

namespace App\Modules\Financeiro\Support;

use App\Modules\Financeiro\Models\CashFlowEntry;
use App\Modules\Marketplace\Models\ChannelWalletBalance;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Services\Shopee\ShopeeWalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Dinheiro que vai entrar e sair nos próximos dias (Fluxo de Caixa,
 * 2026-10-19). Saldo das carteiras e repasses previstos saíram de
 * FinancialDashboardController (eram privados) para as duas telas usarem a
 * mesma conta; o calendário do DAS e as saídas lançadas à mão são novos.
 */
class PrevisaoDeCaixa
{
    public const WALLET_CACHE_MINUTES = 5;

    private const CANAIS = [
        'shopee' => 'Shopee',
        'mercado_livre' => 'Mercado Livre',
        'tiktok_shop' => 'TikTok Shop',
        'amazon' => 'Amazon',
    ];

    public function __construct(private ShopeeWalletService $shopeeWallet) {}

    /**
     * Saldo disponível nas carteiras. Shopee ao vivo (5 min de cache, falha
     * não fica em cache, ?refresh=1 busca de novo); Mercado Livre pelo saldo
     * do Mercado Pago que o ads:sync-wallet-balance deixa pronto.
     *
     * @return array{shopee: float|null, mercado_livre: float|null, mercado_livre_as_of: string|null}
     */
    public function walletBalances(): array
    {
        $shopeeAccount = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->first();
        $shopee = null;

        if ($shopeeAccount?->isConnected()) {
            try {
                $cacheKey = 'shopee:wallet_balance:'.$shopeeAccount->seller_id;

                if (request()->boolean('refresh')) {
                    Cache::forget($cacheKey);
                }

                $shopee = Cache::remember($cacheKey, now()->addMinutes(self::WALLET_CACHE_MINUTES), fn () => $this->shopeeWallet->currentBalance());
            } catch (Throwable) {
                // A tela não pode cair porque a consulta ao vivo falhou.
                $shopee = null;
            }
        }

        $mlBalance = ChannelWalletBalance::query()->where('channel', 'mercado_livre')->first();

        return [
            'shopee' => $shopee,
            'mercado_livre' => $mlBalance?->balance !== null ? (float) $mlBalance->balance : null,
            'mercado_livre_as_of' => $mlBalance?->balance_as_of?->toDateTimeString(),
        ];
    }

    /**
     * Repasses previstos em janelas de 15 dias, pela data de liquidação dos
     * extratos importados ainda não pagos.
     *
     * @param array{shopee: float|null, mercado_livre: float|null, mercado_livre_as_of: string|null} $walletBalances
     */
    public function receivableProjection(array $walletBalances): array
    {
        $today = Carbon::today();

        $periods = collect(range(0, 3))->map(function (int $index) use ($today) {
            $start = $today->copy()->addDays($index * 15);
            $end = $today->copy()->addDays((($index + 1) * 15) - 1);

            return [
                'index' => $index,
                'label' => $index === 0 ? 'Próximos 15 dias' : 'Dias '.(($index * 15) + 1).'–'.(($index + 1) * 15),
                'startDate' => $start->toDateString(),
                'endDate' => $end->toDateString(),
                'total' => 0.0,
                'channels' => [],
            ];
        });

        $availableNow = collect(self::CANAIS)->map(function (string $label, string $channel) use ($walletBalances) {
            return [
                'channel' => $channel,
                'label' => $label,
                'amount' => isset($walletBalances[$channel]) && $walletBalances[$channel] !== null ? round((float) $walletBalances[$channel], 2) : null,
            ];
        })->values()->all();

        if (Schema::hasTable('marketplace_settlement_details')) {
            $horizon = $today->copy()->addDays(59);
            $rows = DB::table('marketplace_settlement_details')
                ->whereNotNull('settlement_date')
                ->where('settlement_date', '<=', $horizon->toDateString())
                ->where(function ($query) {
                    $query->whereNull('status')
                        ->orWhere('status', '<>', 'Pagos');
                })
                ->selectRaw('channel, settlement_date, COALESCE(SUM(payout_amount), 0) as amount')
                ->groupBy('channel', 'settlement_date')
                ->get();

            foreach ($rows as $row) {
                $settlementDate = Carbon::parse($row->settlement_date);
                $daysUntilSettlement = (int) floor($today->diffInDays($settlementDate, false));
                $index = intdiv(max(0, $daysUntilSettlement), 15);

                if ($index < 0 || $index > 3) {
                    continue;
                }

                $amount = round((float) $row->amount, 2);
                $period = $periods[$index];
                $current = collect($period['channels'])->firstWhere('channel', $row->channel);

                if ($current) {
                    $period['channels'] = collect($period['channels'])->map(function ($channelRow) use ($row, $amount) {
                        if ($channelRow['channel'] === $row->channel) {
                            $channelRow['amount'] = round($channelRow['amount'] + $amount, 2);
                        }

                        return $channelRow;
                    })->values()->all();
                } else {
                    $period['channels'][] = [
                        'channel' => $row->channel,
                        'label' => self::CANAIS[$row->channel] ?? $row->channel,
                        'amount' => $amount,
                    ];
                }

                $period['total'] = round($period['total'] + $amount, 2);
                $periods[$index] = $period;
            }
        }

        return [
            'asOf' => $today->toDateString(),
            'availableNow' => $availableNow,
            'periods' => $periods->values()->all(),
            'source' => Schema::hasTable('marketplace_settlement_details') ? 'Extratos financeiros importados + carteiras disponíveis' : 'Carteiras disponíveis',
        ];
    }

    /**
     * Vencimento do DAS de uma competência: dia 20 do mês seguinte; caindo
     * em sábado ou domingo, o Simples antecipa para o dia útil anterior.
     * Feriado não entra (sem calendário de feriados no sistema): confirmar
     * com o contador.
     */
    public static function vencimentoDas(Carbon $competencia): Carbon
    {
        $vence = $competencia->copy()->startOfMonth()->addMonthNoOverflow()->day(20);
        while ($vence->isWeekend()) {
            $vence->subDay();
        }

        return $vence;
    }

    /**
     * DAS ainda não vencidos: o da competência passada (se vence a partir
     * de hoje) e o do mês corrente (parcial). Valor = imposto() do mês,
     * estimativa do sistema; o oficial é a guia do contador.
     *
     * @return list<array{competencia: string, vence: string, valor: float, base: float, parcial: bool}>
     */
    public function das(?Carbon $hoje = null): array
    {
        $hoje = ($hoje ?? Carbon::today())->copy()->startOfDay();
        $lista = [];
        foreach ([$hoje->copy()->startOfMonth()->subMonthNoOverflow(), $hoje->copy()->startOfMonth()] as $competencia) {
            $vence = self::vencimentoDas($competencia);
            if ($vence->lt($hoje)) {
                continue;
            }
            $fim = $competencia->copy()->addMonthNoOverflow()->min($hoje->copy()->addDay());
            $valor = round(array_sum(ContributionMargin::imposto($competencia, $fim)), 2);
            if ($valor <= 0) {
                continue;
            }
            $lista[] = [
                'competencia' => $competencia->format('Y-m'),
                'vence' => $vence->toDateString(),
                'valor' => $valor,
                'base' => round(array_sum(ContributionMargin::baseImposto($competencia->copy()->max(Carbon::parse((string) config('loja.simples.desde', '2026-09-01'))), $fim)), 2),
                'parcial' => $fim->lt($competencia->copy()->addMonthNoOverflow()),
            ];
        }

        return $lista;
    }

    /**
     * Saídas lançadas à mão com data futura (aluguel, contador...).
     *
     * @return list<array{data: string, descricao: string, valor: float}>
     */
    public function saidasFuturas(int $dias = 30, ?Carbon $hoje = null): array
    {
        $hoje = ($hoje ?? Carbon::today())->copy()->startOfDay();

        return CashFlowEntry::query()
            ->where('type', CashFlowEntry::TYPE_EXPENSE)
            ->where('entry_date', '>=', $hoje->copy()->addDay()->toDateString())
            ->where('entry_date', '<', $hoje->copy()->addDays($dias + 1)->toDateString())
            ->orderBy('entry_date')
            ->get(['entry_date', 'description', 'amount'])
            ->map(fn (CashFlowEntry $e) => [
                'data' => Carbon::parse($e->entry_date)->toDateString(),
                'descricao' => (string) $e->description,
                'valor' => round((float) $e->amount, 2),
            ])
            ->all();
    }

    /**
     * Agenda dos próximos 30 dias do Resumo (bloco 6), em ordem de data.
     *
     * @return array{itens: list<array<string, mixed>>, carteiras: array<string, mixed>}
     */
    public function proximosDias(): array
    {
        $hoje = Carbon::today();
        $carteiras = $this->walletBalances();
        $projecao = $this->receivableProjection($carteiras);
        $itens = [];

        foreach ($this->das($hoje) as $das) {
            $mes = Carbon::createFromFormat('!Y-m', $das['competencia'])->locale('pt_BR')->translatedFormat('F');
            $itens[] = [
                'data' => $das['vence'],
                'tipo' => 'saida',
                'titulo' => 'DAS de '.$mes.($das['parcial'] ? ' (parcial)' : ''),
                'detalhe' => 'Estimativa: '.str_replace('.', ',', (string) ContributionMargin::aliquotaSimples()).'% das notas de '.$mes.'. Conferir com o contador.',
                'valor' => -$das['valor'],
                'estimado' => true,
            ];
        }

        $disponivel = collect($projecao['availableNow'])->whereNotNull('amount');
        if ($disponivel->isNotEmpty()) {
            $itens[] = [
                'data' => $hoje->toDateString(),
                'tipo' => 'entrada',
                'titulo' => 'Disponível agora nas carteiras',
                'detalhe' => $disponivel->map(fn ($c) => $c['label'].' '.number_format($c['amount'], 2, ',', '.'))->implode(' · '),
                'valor' => round((float) $disponivel->sum('amount'), 2),
                'estimado' => false,
            ];
        }

        foreach (array_slice($projecao['periods'], 0, 2) as $periodo) {
            if ($periodo['total'] == 0.0) {
                continue;
            }
            $itens[] = [
                'data' => $periodo['startDate'],
                'ate' => $periodo['endDate'],
                'tipo' => $periodo['total'] >= 0 ? 'entrada' : 'saida',
                'titulo' => 'Repasses previstos',
                'detalhe' => collect($periodo['channels'])->map(fn ($c) => $c['label'].' '.number_format($c['amount'], 2, ',', '.'))->implode(' · '),
                'valor' => $periodo['total'],
                'estimado' => true,
            ];
        }

        foreach ($this->saidasFuturas(30, $hoje) as $saida) {
            $itens[] = [
                'data' => $saida['data'],
                'tipo' => 'saida',
                'titulo' => $saida['descricao'],
                'detalhe' => 'Lançamento manual',
                'valor' => -$saida['valor'],
                'estimado' => false,
            ];
        }

        usort($itens, fn ($a, $b) => strcmp($a['data'], $b['data']));

        return ['itens' => $itens, 'carteiras' => $carteiras, 'fonte' => $projecao['source']];
    }
}
