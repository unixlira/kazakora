<?php

namespace App\Modules\Financeiro\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Financeiro\Models\SaldoCanal;
use App\Modules\Marketplace\Models\ChannelWalletBalance;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Modules\Marketplace\Support\TaxasAmazonSpApi;
use App\Services\Amazon\AmazonClient;
use App\Services\Shopee\ShopeeWalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Saldo disponível e repasses a receber de cada marketplace, por empresa
 * (pedido do Lira em 2026-10-19, aba "Saldos nos canais" do Fluxo de
 * Caixa). coletar() roda de hora em hora (financeiro:coletar-saldos) e
 * grava uma foto por canal em saldos_canais; painel() monta a tela a
 * partir das fotos, sem chamar API nenhuma.
 *
 * Fontes (todas só leitura, conferidas ao vivo em 2026-10-19):
 * - Shopee: saldo da carteira (current_balance da última transação do
 *   extrato) + payment.get_income_overview (pendente) e
 *   payment.get_income_detail status 2 (pedido a pedido, com data quando o
 *   pagamento já foi iniciado).
 * - Mercado Livre: o dinheiro cai no Mercado Pago, mesma conta do site.
 *   Saldo = relatório de liberações (ads:sync-wallet-balance, a API não
 *   tem "saldo agora": /users/me/mercadopago_account/balance devolve 403).
 *   A liberar = pagamentos aprovados com money_release_status diferente de
 *   released, separados em ML / site / outros como a conciliação faz.
 * - Amazon: grupos financeiros ainda não transferidos (Finances v0, Open e
 *   Pending: o que já está liberado na conta) + transações DEFERRED da
 *   Finances 2024-06-19 (retidas até a entrega, com maturityDate).
 * - TikTok Shop: não temos a API financeira do TikTok (pedido via Bling).
 *   Sem saldo; a receber é ESTIMADO pelos pedidos que ainda não apareceram
 *   no demonstrativo (receita − taxa estimada).
 *
 * FerraKora e Beauty Kora não têm conta própria em nenhum marketplace:
 * MarketplaceAccount guarda uma conta por canal, todas da KazaKora.
 */
class SaldosCanais
{
    public const EMPRESAS = [
        'kazakora' => 'KazaKora',
        'ferrakora' => 'FerraKora',
        'beautykora' => 'Beauty Kora',
    ];

    public const CANAIS = [
        'shopee' => 'Shopee',
        'mercado_livre' => 'Mercado Livre',
        'tiktok_shop' => 'TikTok Shop',
        'amazon' => 'Amazon',
    ];

    /** Foto com mais de 2 h é marcada como desatualizada na tela. */
    public const HORAS_DESATUALIZADO = 2;

    /** Fotos guardadas para o gráfico. */
    public const DIAS_HISTORICO = 90;

    private const MP_BUSCA = 'https://api.mercadopago.com/v1/payments/search';

    public function __construct(
        private readonly ShopeeWalletService $shopee,
        private readonly AmazonClient $amazon,
        private readonly TaxasAmazonSpApi $amazonTransacoes,
    ) {}

    /**
     * Canais com integração, por empresa. Hoje só a KazaKora tem conta
     * (MarketplaceAccount não tem empresa: uma conta por canal). TikTok
     * chega pelo Bling.
     *
     * @return array<string, array<string, bool>>
     */
    public function integracoes(): array
    {
        $contas = MarketplaceAccount::query()->get(['channel', 'status'])->keyBy('channel');
        $conectado = fn (string $canal) => ($contas[$canal] ?? null)?->status === MarketplaceAccount::STATUS_CONNECTED;

        return [
            'kazakora' => [
                'shopee' => $conectado(MarketplaceAccount::CHANNEL_SHOPEE),
                'mercado_livre' => $conectado(MarketplaceAccount::CHANNEL_MERCADO_LIVRE) || (bool) config('services.mercadopago.access_token'),
                'tiktok_shop' => $conectado(MarketplaceAccount::CHANNEL_BLING),
                'amazon' => $conectado(MarketplaceAccount::CHANNEL_AMAZON),
            ],
            'ferrakora' => array_fill_keys(array_keys(self::CANAIS), false),
            'beautykora' => array_fill_keys(array_keys(self::CANAIS), false),
        ];
    }

    // ------------------------------------------------------------------
    // Coleta
    // ------------------------------------------------------------------

    /**
     * Coleta todos os canais integrados. Erro num canal não para os outros.
     *
     * @param  list<string>|null  $canais
     * @return list<array<string, mixed>>
     */
    public function coletar(?array $canais = null, bool $gravar = true): array
    {
        $agora = now();
        $fotos = [];

        foreach ($this->integracoes() as $empresa => $porCanal) {
            foreach ($porCanal as $canal => $integrado) {
                if (! $integrado || ($canais !== null && ! in_array($canal, $canais, true))) {
                    continue;
                }

                try {
                    $foto = match ($canal) {
                        'shopee' => $this->coletarShopee(),
                        'mercado_livre' => $this->coletarMercadoLivre(),
                        'amazon' => $this->coletarAmazon(),
                        'tiktok_shop' => $this->coletarTikTok(),
                    };
                    $foto['erro'] ??= null;
                } catch (Throwable $e) {
                    $foto = [
                        'status' => SaldoCanal::STATUS_ERRO,
                        'disponivel' => null,
                        'a_receber' => null,
                        'repasses' => null,
                        'detalhe' => null,
                        'erro' => Str::limit($this->semSegredo($e->getMessage()), 480, '…'),
                    ];
                }

                $foto = ['empresa' => $empresa, 'canal' => $canal, 'coletado_em' => $agora] + $foto;

                if ($gravar) {
                    SaldoCanal::query()->create($foto);
                }

                $fotos[] = $foto;
            }
        }

        if ($gravar) {
            SaldoCanal::query()->where('coletado_em', '<', $agora->copy()->subDays(self::DIAS_HISTORICO))->delete();
        }

        return $fotos;
    }

    /** @return array<string, mixed> */
    public function coletarShopee(): array
    {
        $saldo = $this->shopee->currentBalance();
        $resumo = $this->shopee->incomeOverview();

        $pedidos = collect($this->shopee->pendingIncome())->unique('order_sn')->values();
        $porData = [];
        $semData = 0.0;
        $emDevolucao = 0.0;

        foreach ($pedidos as $p) {
            $valor = (float) ($p['estimated_escrow_amount'] ?? 0);
            $quando = (int) ($p['estimated_payout_time'] ?? 0);

            if ($quando > 0) {
                $dia = Carbon::createFromTimestamp($quando, config('app.timezone'))->toDateString();
                $porData[$dia] = ($porData[$dia] ?? 0) + $valor;
            } else {
                $semData += $valor;
            }

            if (str_contains(mb_strtolower((string) ($p['status'] ?? '')), 'devolu')) {
                $emDevolucao += $valor;
            }
        }

        ksort($porData);
        $repasses = collect($porData)->map(fn ($v, $d) => ['data' => $d, 'valor' => round($v, 2), 'rotulo' => 'Pagamento iniciado'])->values()->all();

        if ($semData > 0.004) {
            $repasses[] = ['data' => null, 'valor' => round($semData, 2), 'rotulo' => 'Aguardando o pedido ser concluído (a Shopee não dá data)'];
        }

        $somaPedidos = round((float) $pedidos->sum(fn ($p) => (float) ($p['estimated_escrow_amount'] ?? 0)), 2);

        return [
            'status' => $saldo === null ? SaldoCanal::STATUS_PARCIAL : SaldoCanal::STATUS_OK,
            'disponivel' => $saldo,
            'a_receber' => $resumo['pendente'],
            'repasses' => $repasses,
            'detalhe' => [
                'fonte' => 'API da Shopee: carteira, get_income_overview e get_income_detail',
                'pedidos_pendentes' => $pedidos->count(),
                'soma_pedidos' => $somaPedidos,
                'em_devolucao' => round($emDevolucao, 2),
                'liberado_total' => $resumo['liberado'],
                'aviso' => $saldo === null ? 'Sem transação na carteira nos últimos 28 dias: saldo não informado.' : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function coletarMercadoLivre(): array
    {
        $token = config('services.mercadopago.access_token');
        if (! $token) {
            throw new RuntimeException('MERCADOPAGO_ACCESS_TOKEN não configurado.');
        }

        $saldo = ChannelWalletBalance::query()->where('channel', 'mercado_livre')->first();
        $doSite = Payment::query()->whereNotNull('mercadopago_payment_id')->pluck('order_id', 'mercadopago_payment_id');

        $partes = ['mercado_livre' => 0.0, 'site' => 0.0, 'outros' => 0.0];
        $porData = [];
        $atrasado = 0.0;
        $pagamentos = 0;
        $hoje = Carbon::today();

        // 90 dias de pagamentos criados, em janelas de 10 (a busca pagina por
        // offset e fica instável em offsets altos). money_release_status não
        // é filtro aceito pela busca: filtra aqui.
        $fimGeral = now();
        for ($inicio = $fimGeral->copy()->subDays(90); $inicio->lt($fimGeral); $inicio->addDays(10)) {
            $fim = $inicio->copy()->addDays(10)->min($fimGeral);
            $offset = 0;

            do {
                $resposta = Http::withToken($token)->timeout(60)->retry(3, 2000, throw: false)->get(self::MP_BUSCA, [
                    'range' => 'date_created',
                    'begin_date' => $inicio->format('Y-m-d\TH:i:s.vP'),
                    'end_date' => $fim->format('Y-m-d\TH:i:s.vP'),
                    'status' => 'approved',
                    'limit' => 100,
                    'offset' => $offset,
                ]);

                if ($resposta->failed()) {
                    throw new RuntimeException('Mercado Pago recusou a busca de pagamentos: HTTP '.$resposta->status());
                }

                $resultados = $resposta->json('results') ?? [];

                foreach ($resultados as $p) {
                    if (($p['status'] ?? null) !== 'approved' || ($p['money_release_status'] ?? null) === 'released') {
                        continue;
                    }

                    $liquido = (float) ($p['transaction_details']['net_received_amount'] ?? 0) - (float) ($p['transaction_amount_refunded'] ?? 0);
                    if ($liquido <= 0) {
                        continue;
                    }

                    $parte = $doSite->has((string) $p['id']) ? 'site' : ((($p['order']['type'] ?? null) === 'mercadolibre') ? 'mercado_livre' : 'outros');
                    $partes[$parte] += $liquido;
                    $pagamentos++;

                    $libera = ! empty($p['money_release_date']) ? Carbon::parse($p['money_release_date'])->setTimezone(config('app.timezone'))->startOfDay() : null;
                    if ($libera === null || $libera->lt($hoje)) {
                        $atrasado += $liquido;
                    } else {
                        $porData[$libera->toDateString()] = ($porData[$libera->toDateString()] ?? 0) + $liquido;
                    }
                }

                $offset += 100;
                $total = (int) $resposta->json('paging.total', 0);
            } while ($resultados && $offset < $total);
        }

        ksort($porData);
        $repasses = collect($porData)->map(fn ($v, $d) => ['data' => $d, 'valor' => round($v, 2), 'rotulo' => 'Liberação prevista'])->values()->all();
        if ($atrasado > 0.004) {
            $repasses[] = ['data' => null, 'valor' => round($atrasado, 2), 'rotulo' => 'Data prevista já passou e ainda não liberou (retido ou em reclamação)'];
        }

        $saldoAntigo = $saldo === null || $saldo->updated_at === null || $saldo->updated_at->lt(now()->subHours(3));

        return [
            'status' => $saldoAntigo ? SaldoCanal::STATUS_PARCIAL : SaldoCanal::STATUS_OK,
            'disponivel' => $saldo !== null ? (float) $saldo->balance : null,
            'a_receber' => round(array_sum($partes), 2),
            'repasses' => $repasses,
            'detalhe' => [
                'fonte' => 'API do Mercado Pago: relatório de liberações (saldo) e busca de pagamentos (a liberar)',
                'conta_compartilhada' => true,
                'a_receber_ml' => round($partes['mercado_livre'], 2),
                'a_receber_site' => round($partes['site'], 2),
                'a_receber_outros' => round($partes['outros'], 2),
                'pagamentos_pendentes' => $pagamentos,
                'saldo_de' => $saldo?->updated_at?->toDateTimeString(),
                'aviso' => $saldo === null
                    ? 'Saldo do Mercado Pago ainda não coletado (ads:sync-wallet-balance).'
                    : ($saldoAntigo ? 'Saldo do Mercado Pago de '.$saldo->updated_at->format('d/m H:i').': o relatório de liberações não atualizou na última hora.' : null),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function coletarAmazon(): array
    {
        $resposta = $this->amazon->get('/finances/v0/financialEventGroups', [
            'MaxResultsPerPage' => 100,
            'FinancialEventGroupStartedAfter' => now()->subDays(90)->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
        $grupos = $resposta['payload']['FinancialEventGroupList'] ?? [];

        $acumulando = 0.0;
        $emTransferencia = 0.0;
        foreach ($grupos as $g) {
            if (($g['FundTransferStatus'] ?? null) === 'Succeeded') {
                continue;
            }
            $valor = (float) ($g['OriginalTotal']['CurrencyAmount'] ?? 0);
            if (($g['ProcessingStatus'] ?? null) === 'Open') {
                $acumulando += $valor;
            } else {
                $emTransferencia += $valor;
            }
        }

        // Retido até a entrega (DD+7): DEFERRED que ainda não ganhou o
        // lançamento liberado (DEFERRED_RELEASED aponta para ele).
        $transacoes = $this->amazonTransacoes->buscar(now()->subDays(60));
        $liberados = [];
        foreach ($transacoes as $t) {
            foreach ($t['relatedIdentifiers'] ?? [] as $id) {
                if (($id['relatedIdentifierName'] ?? null) === 'DEFERRED_TRANSACTION_ID') {
                    $liberados[$id['relatedIdentifierValue']] = true;
                }
            }
        }

        $porData = [];
        $semData = 0.0;
        $retidos = 0;
        foreach ($transacoes as $t) {
            if (($t['transactionStatus'] ?? null) !== 'DEFERRED' || isset($liberados[$t['transactionId'] ?? ''])) {
                continue;
            }
            $valor = (float) ($t['totalAmount']['currencyAmount'] ?? 0);
            $retidos++;
            $vence = null;
            foreach ($t['contexts'] ?? [] as $c) {
                if (($c['contextType'] ?? null) === 'DeferredContext' && ! empty($c['maturityDate'])) {
                    $vence = Carbon::parse($c['maturityDate'])->setTimezone(config('app.timezone'))->toDateString();
                }
            }
            if ($vence === null) {
                $semData += $valor;
            } else {
                $porData[$vence] = ($porData[$vence] ?? 0) + $valor;
            }
        }

        ksort($porData);
        $repasses = [];
        if ($emTransferencia > 0.004) {
            $repasses[] = ['data' => null, 'valor' => round($emTransferencia, 2), 'rotulo' => 'Período fechado, transferência para o banco em andamento'];
        }
        foreach ($porData as $d => $v) {
            $repasses[] = ['data' => $d, 'valor' => round($v, 2), 'rotulo' => 'Liberação após a entrega (DD+7)'];
        }
        if ($semData > 0.004) {
            $repasses[] = ['data' => null, 'valor' => round($semData, 2), 'rotulo' => 'Retido até a entrega, sem data'];
        }

        $retido = round(array_sum($porData) + $semData, 2);

        return [
            'status' => SaldoCanal::STATUS_OK,
            'disponivel' => round($acumulando + $emTransferencia, 2),
            'a_receber' => $retido,
            'repasses' => $repasses,
            'detalhe' => [
                'fonte' => 'SP-API da Amazon: financialEventGroups (saldo) e transactions DEFERRED (retido até a entrega)',
                'acumulando' => round($acumulando, 2),
                'em_transferencia' => round($emTransferencia, 2),
                'pedidos_retidos' => $retidos,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function coletarTikTok(): array
    {
        $desde = now()->subDays(60);

        $pedidos = Order::query()
            ->vendaEfetiva()
            ->where('orders.origin', Order::ORIGIN_TIKTOK_SHOP)
            ->whereIn('orders.status', ContributionMargin::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $desde)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('marketplace_settlement_details as msd')
                ->where('msd.channel', Order::ORIGIN_TIKTOK_SHOP)
                ->where('msd.transaction_type', 'Pedido')
                ->whereColumn('msd.external_order_id', 'orders.external_order_id'))
            ->leftJoin('order_channel_fees as ocf', fn ($j) => $j->on('ocf.order_id', '=', 'orders.id')->where('ocf.channel', Order::ORIGIN_TIKTOK_SHOP))
            ->selectRaw('COUNT(*) as pedidos, COALESCE(SUM('.ContributionMargin::receitaSql().'), 0) as receita, COALESCE(SUM(ocf.fee_amount), 0) as taxa, MIN(orders.created_at) as mais_antigo')
            ->toBase()
            ->first();

        // Repasse com data futura no demonstrativo (status diferente de
        // "Pagos"): hoje o arquivo só traz o que já foi pago, mas se vier
        // entra com data.
        $futuros = DB::table('marketplace_settlement_details')
            ->where('channel', Order::ORIGIN_TIKTOK_SHOP)
            ->whereNotNull('settlement_date')
            ->where('settlement_date', '>=', Carbon::today()->toDateString())
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 'Pagos'))
            ->selectRaw('settlement_date, SUM(payout_amount) as valor')
            ->groupBy('settlement_date')
            ->orderBy('settlement_date')
            ->get();

        $estimado = round(max(0, (float) $pedidos->receita - (float) $pedidos->taxa), 2);
        $repasses = $futuros->map(fn ($r) => ['data' => Carbon::parse($r->settlement_date)->toDateString(), 'valor' => round((float) $r->valor, 2), 'rotulo' => 'Demonstrativo do TikTok'])->all();
        if ($estimado > 0) {
            $repasses[] = ['data' => null, 'valor' => $estimado, 'rotulo' => 'Pedidos ainda fora do demonstrativo (estimativa)'];
        }

        $ultimoArquivo = DB::table('tiktok_report_uploads')->max('created_at');

        return [
            'status' => SaldoCanal::STATUS_PARCIAL,
            'disponivel' => null,
            'a_receber' => round($estimado + (float) $futuros->sum('valor'), 2),
            'repasses' => $repasses,
            'detalhe' => [
                'fonte' => 'Pedidos do TikTok (via Bling) que ainda não apareceram no demonstrativo',
                'estimado' => true,
                'pedidos_sem_repasse' => (int) $pedidos->pedidos,
                'receita' => round((float) $pedidos->receita, 2),
                'taxa_estimada' => round((float) $pedidos->taxa, 2),
                'mais_antigo' => $pedidos->mais_antigo ? Carbon::parse($pedidos->mais_antigo)->toDateString() : null,
                'ultimo_demonstrativo' => $ultimoArquivo ? Carbon::parse($ultimoArquivo)->toDateTimeString() : null,
                'aviso' => 'Sem saldo: não temos a API financeira do TikTok (os pedidos chegam pelo Bling). A receber é estimativa pelos pedidos.',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Tela
    // ------------------------------------------------------------------

    /**
     * Tudo o que a aba precisa: por empresa, um card por canal com a
     * última foto boa, a última tentativa (erro/atraso) e a série de 7 dias.
     *
     * @return array<string, mixed>
     */
    public function painel(int $diasGrafico = 7): array
    {
        $integracoes = $this->integracoes();
        $agora = now();
        $limiteAtraso = $agora->copy()->subHours(self::HORAS_DESATUALIZADO);

        $fotos = SaldoCanal::query()
            ->where('coletado_em', '>=', $agora->copy()->subDays($diasGrafico))
            ->orderBy('coletado_em')
            ->get()
            ->groupBy(fn (SaldoCanal $s) => $s->empresa.'|'.$s->canal);

        $empresas = [];
        $geral = ['disponivel' => 0.0, 'a_receber' => 0.0];

        foreach (self::EMPRESAS as $empresa => $nomeEmpresa) {
            $cards = [];
            $totais = ['disponivel' => 0.0, 'a_receber' => 0.0];

            foreach (self::CANAIS as $canal => $nomeCanal) {
                $integrado = $integracoes[$empresa][$canal] ?? false;
                $doCanal = $fotos->get($empresa.'|'.$canal, collect());
                $ultima = $doCanal->last() ?? ($integrado ? SaldoCanal::query()->where('empresa', $empresa)->where('canal', $canal)->latest('coletado_em')->first() : null);

                if (! $integrado && $ultima === null) {
                    $cards[] = ['canal' => $canal, 'nome' => $nomeCanal, 'integrado' => false];

                    continue;
                }

                $boa = $doCanal->reverse()->first(fn (SaldoCanal $s) => $s->status !== SaldoCanal::STATUS_ERRO)
                    ?? ($ultima?->status !== SaldoCanal::STATUS_ERRO ? $ultima : SaldoCanal::query()->where('empresa', $empresa)->where('canal', $canal)->where('status', '<>', SaldoCanal::STATUS_ERRO)->latest('coletado_em')->first());

                $disponivel = $boa?->disponivel !== null ? (float) $boa->disponivel : null;
                $aReceber = $boa?->a_receber !== null ? (float) $boa->a_receber : null;
                $totais['disponivel'] += $disponivel ?? 0;
                $totais['a_receber'] += $aReceber ?? 0;

                $cards[] = [
                    'canal' => $canal,
                    'nome' => $nomeCanal,
                    'integrado' => true,
                    'disponivel' => $disponivel,
                    'a_receber' => $aReceber,
                    'repasses' => $boa?->repasses ?? [],
                    'detalhe' => $boa?->detalhe ?? [],
                    'status' => $boa?->status,
                    'coletado_em' => $boa?->coletado_em?->toIso8601String(),
                    'ultima_tentativa' => $ultima?->coletado_em?->toIso8601String(),
                    'erro' => $ultima?->status === SaldoCanal::STATUS_ERRO ? $ultima->erro : null,
                    'desatualizado' => $boa === null || $boa->coletado_em->lt($limiteAtraso),
                    'serie' => $doCanal->where('status', '<>', SaldoCanal::STATUS_ERRO)->map(fn (SaldoCanal $s) => [
                        't' => $s->coletado_em->toIso8601String(),
                        'disponivel' => $s->disponivel !== null ? (float) $s->disponivel : null,
                        'a_receber' => $s->a_receber !== null ? (float) $s->a_receber : null,
                    ])->values()->all(),
                ];
            }

            $temIntegracao = collect($cards)->contains('integrado', true);
            $empresas[] = [
                'empresa' => $empresa,
                'nome' => $nomeEmpresa,
                'tem_integracao' => $temIntegracao,
                'disponivel' => round($totais['disponivel'], 2),
                'a_receber' => round($totais['a_receber'], 2),
                'cards' => $cards,
            ];
            $geral['disponivel'] += $totais['disponivel'];
            $geral['a_receber'] += $totais['a_receber'];
        }

        return [
            'empresas' => $empresas,
            'geral' => ['disponivel' => round($geral['disponivel'], 2), 'a_receber' => round($geral['a_receber'], 2)],
            'agora' => $agora->toIso8601String(),
            'horasDesatualizado' => self::HORAS_DESATUALIZADO,
            'diasGrafico' => $diasGrafico,
        ];
    }

    /** Mensagem de erro de API pode trazer token na URL: corta. */
    private function semSegredo(string $mensagem): string
    {
        return (string) preg_replace('/(access_token|token|sign|refresh_token|client_secret)=[^&\s"]+/i', '$1=***', $mensagem);
    }
}
