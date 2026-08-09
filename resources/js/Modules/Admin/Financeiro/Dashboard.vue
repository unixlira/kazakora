<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import ChartCanvas from '@/Shared/Components/ChartCanvas.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    summary: { type: Object, required: true },
    netProfit: { type: Object, required: true },
    adSpendByChannel: { type: Array, default: () => [] },
    adSpendSeries: { type: Array, default: () => [] },
    cashFlowSeries: { type: Array, default: () => [] },
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

const hasCostData = computed(() => props.netProfit.productsWithCost > 0);
</script>

<template>
    <Head title="Dashboard Financeiro" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Dashboard Financeiro</h1>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <CardStats stat-subtitle="SALDO ATUAL" :stat-title="formatPrice(summary.balance)" stat-icon-name="fas fa-scale-balanced" variant="primary" />
            <CardStats stat-subtitle="ENTRADAS NO MÊS" :stat-title="formatPrice(summary.incomeMonth)" stat-icon-name="fas fa-arrow-trend-up" variant="success" />
            <CardStats stat-subtitle="SAÍDAS NO MÊS" :stat-title="formatPrice(summary.expenseMonth)" stat-icon-name="fas fa-arrow-trend-down" variant="error" />
            <CardStats stat-subtitle="LUCRO NO MÊS (fluxo de caixa)" :stat-title="formatPrice(summary.profitMonth)" stat-icon-name="fas fa-coins" variant="warning" />
            <CardStats stat-subtitle="FATURAMENTO EM VENDAS" :stat-title="formatPrice(summary.salesRevenue)" stat-icon-name="fas fa-bag-shopping" variant="info" />
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

        <!-- Lucro líquido de vendas — pedido explícito 2026-08-09 -->
        <h2 class="mb-3 mt-8 text-xl font-bold">Lucro Líquido de Vendas (mês atual)</h2>
        <p class="mb-4 text-sm text-slate-500">
            Receita das vendas menos custo do produto, taxa de marketplace e gasto com anúncio — diferente do "Lucro no mês"
            acima, que é só o que foi lançado manualmente no Fluxo de Caixa.
        </p>

        <div class="mb-3 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <CardStats stat-subtitle="RECEITA DE VENDAS" :stat-title="formatPrice(netProfit.salesRevenueMonth)" stat-icon-name="fas fa-sack-dollar" variant="success" />
            <CardStats stat-subtitle="CUSTO DE PRODUTO" :stat-title="formatPrice(netProfit.productCostMonth)" stat-icon-name="fas fa-box" variant="secondary" />
            <CardStats stat-subtitle="TAXA DE MARKETPLACE" :stat-title="formatPrice(netProfit.marketplaceFeeMonth)" stat-icon-name="fas fa-percent" variant="warning" />
            <CardStats stat-subtitle="GASTO COM ANÚNCIO" :stat-title="formatPrice(netProfit.adSpendMonth)" stat-icon-name="fas fa-bullhorn" variant="error" />
            <CardStats stat-subtitle="LUCRO LÍQUIDO" :stat-title="formatPrice(netProfit.netProfitMonth)" stat-icon-name="fas fa-chart-line" variant="primary" />
        </div>

        <p v-if="!hasCostData" class="mb-6 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700 dark:border-amber-900 dark:bg-amber-900/20 dark:text-amber-400">
            <i class="fas fa-triangle-exclamation mt-0.5"></i>
            <span>
                Nenhum produto ativo tem preço de custo cadastrado ainda ({{ netProfit.productsWithCost }} de {{ netProfit.productsActive }}) —
                "Custo de produto" e "Lucro líquido" estão contando custo zero, não é o valor real. Preencha o custo em
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
        <p class="mb-8 text-xs text-slate-400">
            Taxa de marketplace rastreada de verdade só pra Mercado Livre e Shopee — pedidos de outros canais, ou de antes
            de 09/08, podem não estar contados aqui.
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
