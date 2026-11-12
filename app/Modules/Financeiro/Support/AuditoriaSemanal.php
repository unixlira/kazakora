<?php

namespace App\Modules\Financeiro\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Auditoria semanal do dinheiro que caiu (pedido do Lira em 12/11/2026):
 * toda segunda-feira, para TikTok Shop, Amazon, Mercado Livre e Shopee, soma
 * o que cada canal depositou na semana fechada (segunda a domingo) e diz
 * quanto separar para imposto, fornecedor e insumos, e quanto sobra de lucro.
 *
 * NÃO é a conta de lucro do período (essa é ContributionMargin::periodo(),
 * pela data da venda). Aqui a base é o DEPÓSITO: o repasse líquido que o
 * canal pagou na semana, já sem a comissão dele, e os pedidos pagos nesse
 * repasse. As peças vêm da conta oficial (custoSql, alíquota, insumos por
 * pedido, frete próprio), só mudam os pedidos que entram.
 *
 * De onde vem o depósito de cada canal:
 *  - tiktok_shop: extrato importado (marketplace_settlement_details), linhas
 *    "Pagos" com data de liquidação na semana; inclui o que o extrato desconta
 *    (GMV Max) e os reembolsos de logística.
 *  - amazon: mesmo extrato, linhas RELEASED (DEFERRED ainda não foi pago).
 *  - mercado_livre: pagamentos do Mercado Pago aprovados com liberação na
 *    semana (liquido − reembolsado).
 *  - shopee: carteira da Shopee pela API (get_wallet_transaction_list), tudo
 *    menos saque: repasse de pedido (ESCROW_VERIFIED_ADD), estornos e o que a
 *    Shopee desconta do saldo (recarga de Shopee Ads).
 *
 * Para cada pedido pago no depósito:
 *  - fornecedor = custo dos produtos (ContributionMargin::custoSql);
 *  - imposto = alíquota do Simples × valor da nota de venda (sem nota, o
 *    total do pedido);
 *  - insumos = R$ por pedido embalado (o Full do ML não embala aqui);
 *  - frete próprio = etiqueta dos Correios/Melhor Envio que a loja pagou.
 * Sobra = depositado − fornecedor − imposto − insumos − frete próprio.
 *
 * Um pedido só entra na separação quando o que foi pago por ele na semana
 * (linhas de pedido do extrato, repasse da Shopee, pagamento do ML) fica
 * positivo. Linha zerada, estorno e cancelamento mexem só no valor
 * depositado: não se separa custo, imposto nem insumo por eles.
 */
class AuditoriaSemanal
{
    public const CANAIS = [
        Order::ORIGIN_TIKTOK_SHOP => 'TikTok Shop',
        Order::ORIGIN_AMAZON => 'Amazon',
        Order::ORIGIN_MERCADO_LIVRE => 'Mercado Livre',
        Order::ORIGIN_SHOPEE => 'Shopee',
    ];

    /** Lançamentos da carteira da Shopee que são saque (dinheiro saindo para o banco), não depósito. */
    private const SHOPEE_SAQUE = '/WITHDRAW|PIX_TRANSFER|BANK_TRANSFER/i';

    /** Extrato sem lançamento novo há mais dias que isso vira aviso. */
    private const DIAS_EXTRATO_PARADO = 4;

    public function __construct(private readonly ShopeeClient $shopee)
    {
    }

    /**
     * Semana fechada anterior ao dia dado: de segunda 00:00 a segunda 00:00.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function semanaFechada(?Carbon $referencia = null): array
    {
        $fim = ($referencia ?? now())->copy()->startOfWeek(Carbon::MONDAY);

        return [$fim->copy()->subWeek(), $fim];
    }

    /**
     * @return array{de: Carbon, ate: Carbon, aliquota: float, insumos_por_pedido: float, canais: array<string, array<string, mixed>>, total: array<string, float|int>, avisos: list<string>}
     */
    public function calcular(Carbon $de, Carbon $ate): array
    {
        $canais = [
            Order::ORIGIN_TIKTOK_SHOP => $this->doExtrato(Order::ORIGIN_TIKTOK_SHOP, 'Pagos', 'Pedido', $de, $ate),
            Order::ORIGIN_AMAZON => $this->doExtrato(Order::ORIGIN_AMAZON, 'RELEASED', 'Order', $de, $ate),
            Order::ORIGIN_MERCADO_LIVRE => $this->doMercadoLivre($de, $ate),
            Order::ORIGIN_SHOPEE => $this->daShopee($de, $ate),
        ];

        $avisos = [];
        foreach ($canais as $canal => &$dados) {
            $dados = [...$dados, ...$this->separar($canal, $dados['pedidos_ids'], (float) $dados['depositado'])];
            $dados['nome'] = self::CANAIS[$canal];
            unset($dados['pedidos_ids']);
            foreach ($dados['avisos'] as $aviso) {
                $avisos[] = self::CANAIS[$canal].': '.$aviso;
            }
        }
        unset($dados);

        $campos = ['depositado', 'fornecedor', 'imposto', 'insumos', 'frete_proprio', 'sobra', 'pedidos'];
        $total = [];
        foreach ($campos as $campo) {
            $total[$campo] = round(array_sum(array_column($canais, $campo)), 2);
        }
        $total['pedidos'] = (int) $total['pedidos'];

        return [
            'de' => $de->copy(),
            'ate' => $ate->copy(),
            'aliquota' => ContributionMargin::aliquotaSimples(),
            'insumos_por_pedido' => ContributionMargin::insumosPorPedido(),
            'canais' => $canais,
            'total' => $total,
            'avisos' => $avisos,
        ];
    }

    /**
     * TikTok e Amazon: extrato importado. Depositado = tudo o que foi pago
     * na semana (pedidos, ajustes, descontos do canal); pedidos = as linhas
     * do tipo de pagamento de pedido.
     *
     * @return array{depositado: float, lancamentos: int, descontos: float, pedidos_ids: list<int>, sem_pedido: float, avisos: list<string>}
     */
    private function doExtrato(string $canal, string $statusPago, string $tipoPedido, Carbon $de, Carbon $ate): array
    {
        $linhas = DB::table('marketplace_settlement_details')
            ->where('channel', $canal)
            ->where('status', $statusPago)
            ->where('settlement_date', '>=', $de->toDateString())
            ->where('settlement_date', '<', $ate->toDateString())
            ->get(['transaction_type', 'external_order_id', 'payout_amount']);

        // Pedido pago na semana = o que teve repasse positivo somando as linhas dele (ver pagos()).
        $pagos = self::pagos($linhas->where('transaction_type', $tipoPedido)->map(fn ($l) => [(string) $l->external_order_id, (float) $l->payout_amount]));
        $ids = $this->pedidosPorCodigo($canal, collect(array_keys($pagos)));
        $semPedido = (float) collect($pagos)->reject(fn ($valor, $codigo) => isset($ids[(string) $codigo]))->sum();

        $avisos = [];
        $ultimo = DB::table('marketplace_settlement_details')->where('channel', $canal)->max('created_at');
        if ($ultimo === null) {
            $avisos[] = 'nenhum extrato importado até hoje; o valor depositado não pôde ser lido.';
        } elseif (Carbon::parse($ultimo)->lt(now()->subDays(self::DIAS_EXTRATO_PARADO))) {
            $avisos[] = 'o último lançamento do extrato entrou em '.Carbon::parse($ultimo)->format('d/m H:i').'; se caiu dinheiro depois disso, ele não está nesta conta.';
        }
        if (abs($semPedido) >= 0.01) {
            $avisos[] = 'R$ '.number_format($semPedido, 2, ',', '.').' pagos por pedidos que não estão no sistema: entraram no depositado, mas sem custo, imposto e insumos.';
        }

        return [
            'depositado' => round((float) $linhas->sum('payout_amount'), 2),
            'lancamentos' => $linhas->count(),
            // O que o canal descontou dentro do próprio repasse (GMV Max, estornos).
            'descontos' => round((float) $linhas->where('payout_amount', '<', 0)->sum('payout_amount'), 2),
            'pedidos_ids' => array_values(array_unique($ids)),
            'sem_pedido' => round($semPedido, 2),
            'avisos' => $avisos,
        ];
    }

    /** @return array{depositado: float, lancamentos: int, descontos: float, pedidos_ids: list<int>, sem_pedido: float, avisos: list<string>} */
    private function doMercadoLivre(Carbon $de, Carbon $ate): array
    {
        $pagamentos = DB::table('mercado_pago_pagamentos')
            ->where('canal', 'mercado_livre')
            ->where('status', 'approved')
            ->where('data_liberacao', '>=', $de)
            ->where('data_liberacao', '<', $ate)
            ->get(['order_id', 'liquido', 'reembolsado']);

        $valor = fn ($p) => (float) $p->liquido - (float) $p->reembolsado;
        $semPedido = (float) $pagamentos->whereNull('order_id')->sum($valor);
        $pagos = self::pagos($pagamentos->whereNotNull('order_id')->map(fn ($p) => [(string) $p->order_id, $valor($p)]));

        $avisos = [];
        $ultimo = DB::table('mercado_pago_pagamentos')->max('updated_at');
        if ($ultimo === null || Carbon::parse($ultimo)->lt(now()->subDays(2))) {
            $avisos[] = 'a conciliação do Mercado Pago não roda desde '.($ultimo ? Carbon::parse($ultimo)->format('d/m H:i') : 'sempre').'; liberações mais novas não estão nesta conta.';
        }
        if (abs($semPedido) >= 0.01) {
            $avisos[] = 'R$ '.number_format($semPedido, 2, ',', '.').' liberados sem pedido ligado no sistema: entraram no depositado, mas sem custo, imposto e insumos.';
        }

        return [
            'depositado' => round((float) $pagamentos->sum($valor), 2),
            'lancamentos' => $pagamentos->count(),
            'descontos' => 0.0,
            'pedidos_ids' => array_map('intval', array_keys($pagos)),
            'sem_pedido' => round($semPedido, 2),
            'avisos' => $avisos,
        ];
    }

    /**
     * Shopee: a carteira pela API, em janelas de até 7 dias (limite do
     * endpoint). Falha na API não derruba a auditoria: o canal sai zerado
     * com o aviso, para ninguém ler zero como "não caiu nada".
     *
     * @return array{depositado: float, lancamentos: int, descontos: float, pedidos_ids: list<int>, sem_pedido: float, avisos: list<string>}
     */
    private function daShopee(Carbon $de, Carbon $ate): array
    {
        try {
            $transacoes = $this->carteiraDaShopee($de, $ate);
        } catch (\Throwable $e) {
            return ['depositado' => 0.0, 'lancamentos' => 0, 'descontos' => 0.0, 'pedidos_ids' => [], 'sem_pedido' => 0.0,
                'avisos' => ['a carteira da Shopee não respondeu ('.mb_substr($e->getMessage(), 0, 120).'). O valor da Shopee NÃO está nesta auditoria: rode de novo ou confira no app.']];
        }

        $depositos = $transacoes->reject(fn (array $t) => preg_match(self::SHOPEE_SAQUE, (string) ($t['transaction_type'] ?? '')) === 1
            || ($t['status'] ?? 'COMPLETED') !== 'COMPLETED');
        // Repasse e estorno do mesmo pedido somam: só o que ficou positivo na semana é pedido pago.
        $pagos = self::pagos($depositos->filter(fn (array $t) => filled($t['order_sn'] ?? null))->map(fn (array $t) => [(string) $t['order_sn'], (float) ($t['amount'] ?? 0)]));
        $ids = $this->pedidosPorCodigo(Order::ORIGIN_SHOPEE, collect(array_keys($pagos)));
        $semPedido = (float) collect($pagos)->reject(fn ($valor, $codigo) => isset($ids[(string) $codigo]))->sum();

        $avisos = [];
        if (abs($semPedido) >= 0.01) {
            $avisos[] = 'R$ '.number_format($semPedido, 2, ',', '.').' de repasse de pedidos que não estão no sistema: entraram no depositado, mas sem custo, imposto e insumos.';
        }

        return [
            'depositado' => round((float) $depositos->sum(fn (array $t) => (float) ($t['amount'] ?? 0)), 2),
            'lancamentos' => $depositos->count(),
            // Recarga de Shopee Ads e outros descontos tirados do saldo.
            'descontos' => round((float) $depositos->filter(fn (array $t) => (float) ($t['amount'] ?? 0) < 0)->sum(fn (array $t) => (float) $t['amount']), 2),
            'pedidos_ids' => array_values(array_unique($ids)),
            'sem_pedido' => round($semPedido, 2),
            'avisos' => $avisos,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function carteiraDaShopee(Carbon $de, Carbon $ate): Collection
    {
        $todas = collect();

        for ($inicio = $de->copy(); $inicio->lt($ate); $inicio = $fim) {
            $fim = $inicio->copy()->addDays(7)->min($ate);
            $pagina = 1;
            do {
                $resposta = $this->shopee->get('/api/v2/payment/get_wallet_transaction_list', [
                    'page_no' => $pagina,
                    'page_size' => 100,
                    'create_time_from' => $inicio->timestamp,
                    'create_time_to' => $fim->timestamp - 1,
                ]);
                if (filled($resposta['error'] ?? null)) {
                    throw new \RuntimeException((string) ($resposta['message'] ?? $resposta['error']));
                }
                $todas = $todas->concat($resposta['response']['transaction_list'] ?? []);
                $mais = (bool) ($resposta['response']['more'] ?? false);
                $pagina++;
            } while ($mais && $pagina <= 50);
        }

        return $todas->unique('transaction_id')->values();
    }

    /**
     * Pedidos pagos na semana: soma os lançamentos de cada pedido e fica só
     * com os de saldo positivo. Linha zerada (o TikTok repete o pedido com
     * R$ 0,00), estorno e cancelamento baixam o que caiu, mas não são venda
     * paga: não se separa custo, imposto nem insumo por eles.
     *
     * @param  Collection<int, array{0: string, 1: float}>  $lancamentos  [código do pedido, valor]
     * @return array<string, float> código => valor pago
     */
    private static function pagos(Collection $lancamentos): array
    {
        return $lancamentos
            ->filter(fn (array $l) => $l[0] !== '')
            ->groupBy(fn (array $l) => $l[0])
            ->map(fn (Collection $doPedido) => round((float) $doPedido->sum(fn (array $l) => $l[1]), 2))
            ->filter(fn (float $valor) => $valor > 0)
            ->all();
    }

    /**
     * Código do pedido no canal => id do pedido aqui.
     *
     * @param  Collection<int, mixed>  $codigos
     * @return array<string, int>
     */
    private function pedidosPorCodigo(string $canal, Collection $codigos): array
    {
        $codigos = $codigos->filter()->map(fn ($c) => (string) $c)->unique()->values();
        if ($codigos->isEmpty()) {
            return [];
        }

        return DB::table('orders')
            ->where('origin', $canal)
            ->whereIn('external_order_id', $codigos->all())
            ->pluck('id', 'external_order_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Quanto separar do depósito, pelos pedidos pagos nele.
     *
     * @param  list<int>  $ids
     * @return array{pedidos: int, fornecedor: float, imposto: float, base_imposto: float, insumos: float, frete_proprio: float, sobra: float, sem_custo: int, sem_nota: int}
     */
    private function separar(string $canal, array $ids, float $depositado): array
    {
        $zero = ['pedidos' => 0, 'fornecedor' => 0.0, 'imposto' => 0.0, 'base_imposto' => 0.0, 'insumos' => 0.0, 'frete_proprio' => 0.0, 'sobra' => round($depositado, 2), 'sem_custo' => 0, 'sem_nota' => 0];
        if ($ids === []) {
            return $zero;
        }

        $fornecedor = 0.0;
        $semCusto = 0;
        $baseImposto = 0.0;
        $semNota = 0;
        $embalados = 0;
        $frete = 0.0;

        foreach (array_chunk($ids, 500) as $lote) {
            $itens = DB::table('order_items')
                ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
                ->whereIn('order_items.order_id', $lote)
                ->selectRaw('order_items.order_id, SUM('.ContributionMargin::custoSql().') as custo,'
                    .' SUM(CASE WHEN products.cost_price IS NULL AND order_items.manual_cost_price IS NULL THEN 1 ELSE 0 END) as sem_custo')
                ->groupBy('order_items.order_id')
                ->get();
            $fornecedor += (float) $itens->sum('custo');
            $semCusto += $itens->where('sem_custo', '>', 0)->count();

            $nota = "(SELECT i.valor_total FROM invoices i WHERE i.order_id = orders.id AND i.operation_type = 'saida' AND i.cancelada_em IS NULL"
                ." AND (i.status = 'authorized' OR (i.status = 'pending' AND i.chave_acesso IS NOT NULL AND i.autorizada_em IS NOT NULL)) LIMIT 1)";
            $pedidos = DB::table('orders')->whereIn('orders.id', $lote)
                ->selectRaw("orders.id, {$nota} as nota, orders.total")
                ->get();
            $baseImposto += (float) $pedidos->sum(fn ($p) => $p->nota !== null ? (float) $p->nota : (float) $p->total);
            $semNota += $pedidos->whereNull('nota')->count();

            $embalados += DB::table('orders')->whereIn('orders.id', $lote)->tap(fn ($q) => ContributionMargin::semFull($q))->count();

            $frete += (float) DB::table('correios_pre_postagens')->whereIn('order_id', $lote)->where('devolucao', false)->sum('postage_price');
            $frete += (float) DB::table('melhor_envio_envios')->whereIn('order_id', $lote)->tap(fn ($q) => ContributionMargin::etiquetaMelhorEnvioPaga($q))->sum('preco');
        }

        $imposto = round($baseImposto * ContributionMargin::aliquotaSimples() / 100, 2);
        $insumos = round($embalados * ContributionMargin::insumosPorPedido(), 2);
        $fornecedor = round($fornecedor, 2);
        $frete = round($frete, 2);

        return [
            'pedidos' => count($ids),
            'fornecedor' => $fornecedor,
            'imposto' => $imposto,
            'base_imposto' => round($baseImposto, 2),
            'insumos' => $insumos,
            'frete_proprio' => $frete,
            'sobra' => round($depositado - $fornecedor - $imposto - $insumos - $frete, 2),
            'sem_custo' => $semCusto,
            'sem_nota' => $semNota,
        ];
    }
}
