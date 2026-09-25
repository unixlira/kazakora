<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import ChartCanvas from '@/Shared/Components/ChartCanvas.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    summary: { type: Object, required: true },
    netProfit: { type: Object, required: true },
    walletBalances: { type: Object, default: () => ({}) },
    adSpendByChannel: { type: Array, default: () => [] },
    adSpendSeries: { type: Array, default: () => [] },
    cashFlowSeries: { type: Array, default: () => [] },
    settlementSummary: { type: Object, default: () => ({ available: false, month: { channels: [], totals: {} }, allTime: { channels: [], totals: {} } }) },
    marketplaceMetrics: { type: Object, default: () => ({ month: [] }) },
});

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
const formatShortDate = (date) => new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit' }).format(new Date(`${date}T00:00:00`));

const chartData = computed(() => ({
    labels: props.cashFlowSeries.map((item) => formatShortDate(item.date)),
    datasets: [
        { label: 'Entradas', data: props.cashFlowSeries.map((item) => item.income), backgroundColor: '#13deb9' },
        { label: 'Saídas', data: props.cashFlowSeries.map((item) => item.expense), backgroundColor: '#ef4444' },
    ],
}));

// Mesma paleta usada em Invoices/Index.vue e na calculadora de precificação
// — cor real de cada plataforma, pedido explícito 2026-08-09.
const CHANNEL_STYLES = {
    shopee: { label: 'Shopee', color: '#EE4D2D' },
    mercado_livre: { label: 'Mercado Livre', color: '#2968C8' },
    tiktok_shop: { label: 'TikTok Shop', color: '#111827' },
    amazon: { label: 'Amazon', color: '#FF9900' },
    nota_fiscal_avulsa: { label: 'NF Avulsa', color: '#64748b' },
};

const hexToRgba = (hex, alpha) => {
    const value = hex.replace('#', '');
    const r = parseInt(value.substring(0, 2), 16);
    const g = parseInt(value.substring(2, 4), 16);
    const b = parseInt(value.substring(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
};

const adSpendChartData = computed(() => ({
    labels: props.adSpendSeries.map((item) => formatShortDate(item.date)),
    datasets: [
        {
            label: 'Shopee Ads',
            data: props.adSpendSeries.map((item) => item.shopee),
            borderColor: CHANNEL_STYLES.shopee.color,
            backgroundColor: hexToRgba(CHANNEL_STYLES.shopee.color, 0.15),
            tension: 0.3,
        },
        {
            label: 'Mercado Ads',
            data: props.adSpendSeries.map((item) => item.mercado_livre),
            borderColor: CHANNEL_STYLES.mercado_livre.color,
            backgroundColor: hexToRgba(CHANNEL_STYLES.mercado_livre.color, 0.15),
            tension: 0.3,
        },
    ],
}));

const totalAdSpend14Days = computed(() => props.adSpendSeries.reduce((sum, item) => sum + item.shopee + item.mercado_livre, 0));
const platformCostsMonth = computed(() => props.summary.platformCostsMonth ?? ((props.summary.marketplaceFeesMonth ?? 0) + (props.summary.flexCostMonth ?? 0)));
const grossProfitMonth = computed(() => props.summary.grossProfitMonth ?? ((props.summary.grossRevenueMonth ?? 0) - (props.summary.productCostMonth ?? 0)));
const netProfitMarginMonth = computed(() => props.summary.netProfitMarginMonth ?? 0);
const formatPercent = (value) => `${new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value)}%`;
const settlementRows = computed(() => props.settlementSummary?.allTime?.channels ?? []);
const settlementTotals = computed(() => props.settlementSummary?.allTime?.totals ?? {});
const marketplaceMetricsRows = computed(() => props.marketplaceMetrics?.month ?? []);

const hasCostData = computed(() => props.netProfit.productsWithCost > 0);

// "Saldo Atual" = soma dos saldos disponíveis nas duas plataformas —
// pedido explícito 2026-08-10 (deixou de ser fluxo de caixa lançado à
// mão). Se qualquer uma vier indisponível, o total também fica
// indisponível (não dá pra somar um número real com "não sei").
const totalWalletBalance = computed(() => {
    const { shopee, mercado_livre: mercadoLivre } = props.walletBalances;
    return shopee !== null && shopee !== undefined && mercadoLivre !== null && mercadoLivre !== undefined
        ? shopee + mercadoLivre
        : null;
});

// Pedido explícito 2026-08-27: percentual só fica vermelho quando o lucro líquido
// estiver negativo. Margem positiva, mesmo baixa, fica verde para não sugerir prejuízo.
const profitVariant = computed(() => (props.summary.profitMonth >= 0 ? 'success' : 'error'));
const profitMarginBadgeClass = computed(() => (
    props.summary.profitMonth >= 0 ? 'bg-lightsuccess text-success' : 'bg-lighterror text-error'
));
const channelStyle = (channel) => CHANNEL_STYLES[channel] ?? { label: channel, color: '#64748b' };
const metricMarginVariant = (netProfit) => (
    netProfit >= 0 ? 'bg-lightsuccess text-success' : 'bg-lighterror text-error'
);
const metricProfitClass = (value) => value >= 0 ? 'text-success' : 'text-error';
</script>

<template>
    <Head title="Financeiro" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Financeiro</h1>

        <!-- Pedido explícito 2026-08-27: cards do topo em duas linhas de 3,
             para não ficar espremido. Ordem atual: valor bruto vitalício no
             canto superior esquerdo, seguido por lucro bruto do mês. -->
        <h2 class="mb-3 text-xl font-bold">Resumo Financeiro</h2>
        <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <CardStats stat-subtitle="VALOR BRUTO TOTAL · DESDE O INÍCIO" :stat-title="formatPrice(summary.grossRevenueAllTime)" stat-icon-name="fas fa-chart-line" variant="info" />
            <CardStats stat-subtitle="LUCRO BRUTO DO MÊS · VENDAS − CUSTO DO PRODUTO" :stat-title="formatPrice(grossProfitMonth)" stat-icon-name="fas fa-scale-balanced" :variant="grossProfitMonth >= 0 ? 'success' : 'error'" />
            <CardStats stat-subtitle="ADS + CAMPANHAS" :stat-title="formatPrice(summary.adSpendMonth)" stat-icon-name="fas fa-bullhorn" variant="warning" />
            <CardStats stat-subtitle="TAXAS + FRETE + PLATAFORMAS" :stat-title="formatPrice(platformCostsMonth)" stat-icon-name="fas fa-receipt" variant="warning" />
            <CardStats stat-subtitle="CUSTO PRODUTO VENDIDO" :stat-title="formatPrice(summary.productCostMonth)" stat-icon-name="fas fa-box" variant="info" />
            <div class="relative flex min-w-0 flex-col break-words rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm transition-shadow hover:shadow-md">
                <div class="flex items-center gap-4">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full" :class="profitVariant === 'success' ? 'bg-lightsuccess text-success' : 'bg-lighterror text-error'">
                        <i class="fas fa-coins text-xl"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="min-w-0 break-words text-xl font-bold leading-tight tracking-tight tabular-nums sm:text-2xl">{{ formatPrice(summary.profitMonth) }}</p>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-bold leading-none" :class="profitMarginBadgeClass">{{ formatPercent(netProfitMarginMonth) }}</span>
                        </div>
                        <p class="mt-1 text-xs font-semibold uppercase leading-snug tracking-wide text-slate-500 dark:text-slate-400 sm:text-sm">MARGEM DE CONTRIBUIÇÃO DO MÊS · SOBRA NO BOLSO</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 text-sm text-slate-500 shadow-sm dark:text-slate-400">
            Base do mês: vendas líquidas {{ formatPrice(summary.grossRevenueMonth) }} – custo produto {{ formatPrice(summary.productCostMonth) }} = lucro bruto {{ formatPrice(grossProfitMonth) }}. Depois abate ADS/campanhas {{ formatPrice(summary.adSpendMonth) }} e taxas/frete/plataformas {{ formatPrice(platformCostsMonth) }} para chegar na margem de contribuição — o que sobra no bolso.
        </div>

        <h2 class="mb-3 text-xl font-bold">Margem de contribuição por Marketplace · Mês Atual</h2>
        <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">
            Conta de bolso: vendas líquidas do canal menos ADS/campanhas, taxas/frete/plataforma e custo dos itens vendidos.
        </p>
        <div class="mb-6 grid grid-cols-1 gap-4 xl:grid-cols-4">
            <div v-for="market in marketplaceMetricsRows" :key="market.channel" class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm" :class="market.isEmpty ? 'opacity-70' : ''">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="mt-1 h-4 w-4 shrink-0 rounded-full" :style="{ backgroundColor: channelStyle(market.channel).color }"></span>
                        <div class="min-w-0">
                            <p class="truncate text-base font-bold">{{ market.label }}</p>
                            <p class="text-[11px] uppercase tracking-wide text-slate-400">{{ market.source }} · {{ market.ordersCount }} pedidos</p>
                        </div>
                    </div>
                    <span class="shrink-0 rounded-full px-2 py-1 text-[11px] font-bold leading-none" :class="metricMarginVariant(market.netProfit)">{{ market.grossRevenue > 0 ? formatPercent(market.netMargin) : '—' }}</span>
                </div>

                <div class="mb-4 rounded-xl p-4" :style="{ background: hexToRgba(channelStyle(market.channel).color, 0.08) }">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Margem de contribuição · sobra no bolso</p>
                    <p class="mt-1 break-words text-2xl font-black leading-tight tracking-tight tabular-nums sm:text-3xl" :class="metricProfitClass(market.netProfit)">{{ formatPrice(market.netProfit) }}</p>
                </div>

                <div class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">Vendas líquidas</span><span class="font-semibold">{{ formatPrice(market.grossRevenue) }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">(–) ADS/campanhas</span><span class="font-semibold text-error">{{ formatPrice(market.adSpend) }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">(–) Taxas/frete/plataforma</span><span class="font-semibold text-error">{{ formatPrice(market.platformCosts) }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">(–) Custo dos itens</span><span class="font-semibold text-error">{{ formatPrice(market.productCost) }}</span></div>
                    <div class="flex justify-between gap-3 border-t border-[var(--surface-border)] pt-2"><span class="font-semibold">Lucro bruto</span><span class="font-bold">{{ formatPrice(market.grossProfit) }}</span></div>
                </div>

                <p v-if="market.isEmpty" class="mt-4 rounded-lg border border-dashed border-[var(--surface-border)] px-3 py-2 text-xs text-slate-400">
                    Sem métrica confiável ainda. Mantido vazio para receber os dados quando o canal começar a operar.
                </p>
            </div>
        </div>

        <!-- Saldo disponível pra saque em cada plataforma — a soma das duas
             (Saldo Atual) mudou pra Visão Geral, pedido explícito
             2026-08-10. -->
        <h2 class="mb-3 text-xl font-bold">Saldo em Conta</h2>
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full" :style="{ color: CHANNEL_STYLES.shopee.color, background: hexToRgba(CHANNEL_STYLES.shopee.color, 0.12) }">
                        <i class="fas fa-wallet"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Saldo disponível — Shopee</p>
                        <p class="mt-0.5 break-words text-xl font-bold leading-tight tracking-tight tabular-nums sm:text-2xl">{{ walletBalances.shopee !== null ? formatPrice(walletBalances.shopee) : 'Indisponível' }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full" :style="{ color: CHANNEL_STYLES.mercado_livre.color, background: hexToRgba(CHANNEL_STYLES.mercado_livre.color, 0.12) }">
                        <i class="fas fa-wallet"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Saldo disponível — Mercado Livre</p>
                        <p class="mt-0.5 break-words text-xl font-bold leading-tight tracking-tight tabular-nums sm:text-2xl">{{ walletBalances.mercado_livre !== null ? formatPrice(walletBalances.mercado_livre) : 'Indisponível' }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="border-b border-[var(--surface-border)] px-4 py-4">
                <h3 class="text-base font-semibold">Entradas x Saídas (últimos 14 dias)</h3>
                <p class="text-xs text-slate-400">Lançamentos do Fluxo de Caixa — não inclui o faturamento em vendas.</p>
            </div>
            <div class="p-4">
                <ChartCanvas type="bar" :data="chartData" />
            </div>
        </div>

        <!-- Reorganizado 2026-08-10 — trocado o grid de 4 cards (que
             repetia "Receita de Vendas"/"Lucro Líquido" já mostrados na
             Visão Geral) por um extrato de único painel, em passos, na
             ordem real da conta: Bruto → (–) Ads → Líquido do Mês → (–)
             Custo → Lucro Líquido. Isso também resolve a confusão entre
             "Faturamento Líquido do Mês" e "Lucro Líquido do Mês": aqui
             fica claro que um é passo intermediário do outro, não duas
             coisas soltas parecidas. -->
        <h2 class="mb-3 mt-8 text-xl font-bold">Como a Margem de Contribuição do Mês é calculada</h2>

        <div class="mb-3 max-w-xl rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
            <div class="flex items-center justify-between py-1.5 text-sm">
                <span class="text-slate-500 dark:text-slate-400">Vendas líquidas do mês</span>
                <span class="font-semibold">{{ formatPrice(netProfit.salesRevenueMonth) }}</span>
            </div>
            <!-- Pedido explícito 2026-08-15: frete não é receita nem custo
                 do vendedor (quem cobra/paga a transportadora é o canal —
                 Shopee Xpress etc.), então fica fora da conta de cima pra
                 baixo — mas visível, pra quem quiser conferir. -->
            <div class="flex items-center justify-between py-1.5 text-sm text-slate-400 dark:text-slate-500">
                <span>Frete pago pelo comprador (informativo — não afeta a margem; na Amazon ele já entra nas vendas)</span>
                <span class="font-medium">{{ formatPrice(netProfit.shippingCostMonth) }}</span>
            </div>
            <div class="flex items-center justify-between py-1.5 text-sm">
                <span class="text-slate-500 dark:text-slate-400">(–) Custo de Produto Vendido</span>
                <span class="font-semibold text-error">{{ formatPrice(netProfit.productCostMonth) }}</span>
            </div>
            <div class="flex items-center justify-between border-t border-[var(--surface-border)] py-2 text-sm">
                <span class="font-medium">(=) Lucro Bruto do Mês</span>
                <span class="font-semibold">{{ formatPrice(grossProfitMonth) }}</span>
            </div>
            <div class="flex items-center justify-between py-1.5 text-sm">
                <span class="text-slate-500 dark:text-slate-400">(–) ADS + Campanhas</span>
                <span class="font-semibold text-error">{{ formatPrice(netProfit.adSpendMonth) }}</span>
            </div>
            <!-- Pedido explícito 2026-08-14: taxa do marketplace (comissão
                 real Shopee/ML) passou a entrar na conta — antes ficava só
                 informativa (nota de rodapé, "não entra nessa conta") por
                 um pedido de 2026-08-10, mas o usuário reconsiderou: é um
                 custo real (~12-20% da receita), não faz sentido de fora.
                 Agora é uma linha normal do extrato, igual as outras. -->
            <div class="flex items-center justify-between py-1.5 text-sm">
                <span class="text-slate-500 dark:text-slate-400">(–) Taxas das plataformas</span>
                <span class="font-semibold text-error">{{ formatPrice(netProfit.marketplaceFeeMonth) }}</span>
            </div>
            <!-- Frete que a LOJA paga (2026-09-25): pré-postagem dos Correios
                 (Amazon), entregas Flex e frete cobrado no extrato. -->
            <div class="flex items-center justify-between py-1.5 text-sm">
                <span class="text-slate-500 dark:text-slate-400">(–) Frete pago pela loja (Correios {{ formatPrice(netProfit.correiosCostMonth ?? 0) }} · Flex {{ formatPrice(netProfit.flexCostMonth ?? 0) }} · extrato {{ formatPrice(netProfit.settlementShippingCostMonth ?? 0) }})</span>
                <span class="font-semibold text-error">{{ formatPrice((netProfit.correiosCostMonth ?? 0) + (netProfit.flexCostMonth ?? 0) + (netProfit.settlementShippingCostMonth ?? 0)) }}</span>
            </div>
            <div class="flex items-center justify-between border-t-2 border-[var(--surface-border)] py-2">
                <span class="font-bold">(=) Margem de Contribuição do Mês</span>
                <span class="text-xl font-bold" :class="profitVariant === 'success' ? 'text-success' : 'text-error'">{{ formatPrice(netProfit.netProfitMonth) }} · {{ formatPercent(netProfitMarginMonth) }}</span>
            </div>

            <Link href="/admin/integracoes/mercado-livre/flex" class="mt-3 block border-t border-dashed border-[var(--surface-border)] pt-3 text-xs text-primary hover:underline">
                Ver detalhes do custo Flex →
            </Link>
        </div>

        <p v-if="!hasCostData" class="mb-6 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700 dark:border-amber-900 dark:bg-amber-900/20 dark:text-amber-400">
            <i class="fas fa-triangle-exclamation mt-0.5"></i>
            <span>
                Nenhum produto ativo tem preço de custo cadastrado ainda ({{ netProfit.productsWithCost }} de {{ netProfit.productsActive }}) —
                "Custo de produto" e "Margem de contribuição" estão contando custo zero, não é o valor real. Preencha o custo em
                cada produto (aba Dados fiscais) pra esses números ficarem precisos sozinhos.
            </span>
        </p>
        <p v-else-if="netProfit.productsWithCost < netProfit.productsActive" class="mb-6 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700 dark:border-amber-900 dark:bg-amber-900/20 dark:text-amber-400">
            <i class="fas fa-triangle-exclamation mt-0.5"></i>
            <span>
                {{ netProfit.productsActive - netProfit.productsWithCost }} de {{ netProfit.productsActive }} produtos ativos ainda sem custo cadastrado —
                "Custo de produto" está subestimado até completar o cadastro.
            </span>
        </p>


        <h2 class="mb-3 mt-8 text-xl font-bold">Extratos reais de Marketplace</h2>
        <div v-if="settlementRows.length" class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="border-b border-[var(--surface-border)] px-4 py-4">
                <h3 class="text-base font-semibold">Conciliação por relatório financeiro importado</h3>
                <p class="text-xs text-slate-400">Valores liquidados pela plataforma: descontos, frete líquido, taxas, ajustes, custo conciliado e lucro conhecido.</p>
            </div>
            <div class="grid grid-cols-1 gap-4 p-4 xl:grid-cols-2">
                <div v-for="row in settlementRows" :key="row.channel" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface-muted)] p-4">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <span class="inline-block rounded-full px-2.5 py-1 text-xs font-bold" :style="{ color: CHANNEL_STYLES[row.channel]?.color ?? '#64748B', background: hexToRgba(CHANNEL_STYLES[row.channel]?.color ?? '#64748B', 0.12) }">
                            {{ CHANNEL_STYLES[row.channel]?.label ?? row.channel }}
                        </span>
                        <span class="text-xs text-slate-400">{{ row.matchedOrders }}/{{ row.uniqueOrders }} pedidos conciliados</span>
                    </div>

                    <div class="space-y-1.5 text-sm">
                        <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">Subtotal anunciado</span><strong>{{ formatPrice(row.itemSubtotalBeforeDiscounts) }}</strong></div>
                        <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">Vendas líquidas dos produtos</span><strong>{{ formatPrice(row.productNetSales) }}</strong></div>
                        <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">Recebido</span><strong class="text-success">{{ formatPrice(row.paidPayoutAmount ?? 0) }}</strong></div>
                        <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">A receber</span><strong class="text-warning">{{ formatPrice(row.pendingPayoutAmount ?? 0) }}</strong></div>
                        <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">Total liquidável</span><strong>{{ formatPrice(row.payoutAmount) }}</strong></div>
                        <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">(–) Taxas da plataforma</span><strong class="text-error">{{ formatPrice(row.platformFeesTaxes) }}</strong></div>
                        <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">(–) Descontos do vendedor</span><strong class="text-error">{{ formatPrice(row.sellerDiscounts) }}</strong></div>
                        <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">Desconto produto pago pela plataforma</span><strong class="text-success">{{ formatPrice(row.platformProductDiscounts ?? 0) }}</strong></div>
                        <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">Cupom plataforma</span><strong class="text-success">{{ formatPrice(row.platformCouponDiscounts ?? 0) }}</strong></div>
                        <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">Frete líquido</span><strong>{{ formatPrice(row.netShippingImpact) }}</strong></div>
                        <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">Desconto frete TikTok ao cliente</span><strong class="text-success">{{ formatPrice(row.platformShippingDiscounts ?? 0) }}</strong></div>
                        <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">Ajustes</span><strong>{{ formatPrice(row.adjustmentAmount) }}</strong></div>
                        <div class="flex justify-between gap-3 border-t border-[var(--surface-border)] pt-2"><span class="text-slate-500 dark:text-slate-400">Vendas conciliadas com pedido</span><strong>{{ formatPrice(row.matchedProductNetSales) }}</strong></div>
                        <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">Valor liquidado conciliado</span><strong>{{ formatPrice(row.matchedPayoutAmount) }}</strong></div>
                        <div class="flex justify-between gap-3"><span class="text-slate-500 dark:text-slate-400">(–) Custo de produto conciliado</span><strong class="text-error">{{ formatPrice(row.productCostMatched) }}</strong></div>
                        <div class="flex justify-between gap-3"><span class="font-medium">Lucro bruto conhecido</span><strong>{{ formatPrice(row.grossProfitKnown) }}</strong></div>
                        <div class="flex justify-between gap-3 text-base"><span class="font-bold">Margem de contribuição conhecida</span><strong :class="row.netProfitKnown >= 0 ? 'text-success' : 'text-error'">{{ formatPrice(row.netProfitKnown) }}</strong></div>
                    </div>

                    <p v-if="row.missingOrders > 0 || row.costMissingItems > 0" class="mt-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700 dark:border-amber-900 dark:bg-amber-900/20 dark:text-amber-400">
                        <i class="fas fa-triangle-exclamation mt-0.5"></i>
                        <span>
                            {{ row.missingOrders > 0 ? `${row.missingOrders} pedido(s) do extrato ainda não existem no KazaKora. ` : '' }}
                            {{ row.costMissingItems > 0 ? `${row.costMissingItems} item(ns) conciliado(s) sem custo cadastrado. ` : '' }}
                            O lucro fica conhecido só até onde houve conciliação com custo real.
                        </span>
                    </p>
                </div>
            </div>
            <div class="border-t border-[var(--surface-border)] px-4 py-3 text-sm text-slate-500 dark:text-slate-400">
                Total importado: {{ settlementTotals.lineItems ?? 0 }} linhas · {{ settlementTotals.uniqueOrders ?? 0 }} pedidos · recebido {{ formatPrice(settlementTotals.paidPayoutAmount ?? 0) }} · a receber {{ formatPrice(settlementTotals.pendingPayoutAmount ?? 0) }} · total liquidável {{ formatPrice(settlementTotals.payoutAmount ?? 0) }} · desconto plataforma {{ formatPrice((settlementTotals.platformProductDiscounts ?? 0) + (settlementTotals.platformCouponDiscounts ?? 0)) }} · margem de contribuição conhecida {{ formatPrice(settlementTotals.netProfitKnown ?? 0) }}.
            </div>
        </div>
        <p v-else class="mb-6 rounded-xl border border-dashed border-[var(--surface-border)] bg-[var(--surface)] p-4 text-sm text-slate-400">
            Nenhum relatório financeiro de marketplace importado ainda.
        </p>

        <!-- Gasto com anúncio por canal -->
        <h2 class="mb-3 text-xl font-bold">Gasto com Anúncio</h2>

        <div v-if="adSpendByChannel.length" class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div v-for="row in adSpendByChannel" :key="row.channel"
                class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="inline-block rounded-full px-2.5 py-1 text-xs font-bold"
                        :style="{ color: CHANNEL_STYLES[row.channel]?.color ?? '#64748B', background: hexToRgba(CHANNEL_STYLES[row.channel]?.color ?? '#64748B', 0.12) }">
                        {{ CHANNEL_STYLES[row.channel]?.label ?? row.channel }}
                    </span>
                    <span class="text-xs text-slate-400">{{ row.impressions.toLocaleString('pt-BR') }} impressões · {{ row.clicks.toLocaleString('pt-BR') }} cliques</span>
                </div>
                <div class="mt-3 flex items-end justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-400">Gasto no mês</p>
                        <p class="text-2xl font-bold">{{ formatPrice(row.spend) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Vendas atribuídas</p>
                        <p class="text-sm font-semibold">{{ formatPrice(row.attributedGmv) }}</p>
                        <p class="text-xs text-slate-400">ROAS {{ row.spend > 0 ? (row.attributedGmv / row.spend).toFixed(2) : '—' }}</p>
                    </div>
                </div>
            </div>
        </div>
        <p v-else class="mb-4 text-sm text-slate-400">
            Nenhum gasto com anúncio sincronizado ainda — roda <code class="rounded bg-[var(--surface-muted)] px-1">php artisan ads:sync-spend</code> ou aguarda a sincronização automática das 6h.
        </p>

        <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="border-b border-[var(--surface-border)] px-4 py-4">
                <h3 class="text-base font-semibold">Gasto com anúncio por dia (últimos 14 dias)</h3>
                <p class="text-xs text-slate-400">Total do período: {{ formatPrice(totalAdSpend14Days) }}</p>
            </div>
            <div class="p-4">
                <ChartCanvas type="line" :data="adSpendChartData" />
            </div>
        </div>
    </AdminLayout>
</template>
