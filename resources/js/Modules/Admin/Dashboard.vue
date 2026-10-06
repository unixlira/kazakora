<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import ChartCanvas from '@/Shared/Components/ChartCanvas.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
    topProducts: {
        type: Array,
        default: () => [],
    },
    abcCurve: {
        type: Object,
        default: () => ({ total: 0, a: [], b: [] }),
    },
    orderStatusBreakdown: {
        type: Array,
        default: () => [],
    },
    visitsSeries: {
        type: Array,
        default: () => [],
    },
    revenueSeries: {
        type: Array,
        default: () => [],
    },
    revenueByChannel: {
        type: Array,
        default: () => [],
    },
});

// Curva ABC (pedido do usuário 2026-10-06): A = produtos que somam até 80%
// do faturamento, B = os seguintes até 95%. Ver DashboardController::curvaAbc().
const abcCards = computed(() => [
    { key: 'a', description: 'Somam 80% do faturamento', items: props.abcCurve?.a ?? [] },
    { key: 'b', description: 'Os próximos 15% do faturamento', items: props.abcCurve?.b ?? [] },
]);

const CHANNEL_LABELS = {
    loja: 'Site',
    mercado_livre: 'Mercado Livre',
    shopee: 'Shopee',
    tiktok_shop: 'TikTok Shop',
};

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const formatShortDate = (date) =>
    new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit' }).format(new Date(`${date}T00:00:00`));

// Same hues as the CSS design tokens (--color-primary/secondary/success/warning/error/info).
const chartPalette = ['#6d28d9', '#a855f7', '#c084fc', '#8b5cf6', '#dc2626', '#7c3aed'];

const orderStatusChartData = computed(() => ({
    labels: props.orderStatusBreakdown.map((item) => item.label),
    datasets: [
        {
            data: props.orderStatusBreakdown.map((item) => item.total),
            backgroundColor: chartPalette,
            borderWidth: 0,
        },
    ],
}));

const orderStatusChartOptions = computed(() => {
    const total = props.orderStatusBreakdown.reduce((sum, item) => sum + item.total, 0);

    return {
        plugins: {
            legend: {
                position: 'right',
                labels: {
                    generateLabels: (chart) => chart.data.labels.map((label, i) => {
                        const value = chart.data.datasets[0].data[i];
                        const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                        return {
                            text: `${label} — ${value} (${percentage}%)`,
                            fillStyle: chart.data.datasets[0].backgroundColor[i],
                            index: i,
                        };
                    }),
                },
            },
        },
    };
});

const visitsChartData = computed(() => ({
    labels: props.visitsSeries.map((item) => formatShortDate(item.date)),
    datasets: [
        {
            label: 'Visualizações',
            data: props.visitsSeries.map((item) => item.views),
            borderColor: '#6d28d9',
            backgroundColor: '#6d28d933',
            fill: true,
            tension: 0.4,
        },
        {
            label: 'Visitantes únicos',
            data: props.visitsSeries.map((item) => item.visitors),
            borderColor: '#a855f7',
            backgroundColor: '#a855f733',
            fill: true,
            tension: 0.4,
        },
    ],
}));

const revenueChartData = computed(() => ({
    labels: props.revenueSeries.map((item) => formatShortDate(item.date)),
    datasets: [
        {
            label: 'Faturamento',
            data: props.revenueSeries.map((item) => item.revenue),
            backgroundColor: '#7c3aed',
            borderRadius: 6,
        },
    ],
}));

const chartCardClass = 'w-full px-4 xl:w-4/12';
</script>

<template>
    <Head title="Dashboard" />

    <AdminLayout>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <CardStats stat-subtitle="FATURAMENTO TOTAL" :stat-title="formatPrice(stats.revenueTotal)"
                stat-icon-name="fas fa-sack-dollar" variant="success" />
            <CardStats stat-subtitle="FATURAMENTO DO MÊS" :stat-title="formatPrice(stats.revenue)"
                stat-icon-name="fas fa-calendar-days" variant="primary" />
            <CardStats stat-subtitle="FATURAMENTO HOJE" :stat-title="formatPrice(stats.revenueToday)"
                stat-icon-name="fas fa-money-bill-wave" variant="info" />
            <CardStats stat-subtitle="MARGEM DE CONTRIBUIÇÃO DO MÊS" :stat-title="formatPrice(stats.contributionMarginMonth)"
                stat-icon-name="fas fa-wallet" variant="warning" />
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <CardStats stat-subtitle="PEDIDOS" :stat-title="String(stats.ordersCount)"
                stat-icon-name="fas fa-receipt" variant="primary" />
            <CardStats stat-subtitle="PEDIDOS NO MÊS" :stat-title="String(stats.ordersMonth)"
                stat-icon-name="fas fa-calendar-check" variant="secondary" />
            <CardStats stat-subtitle="PEDIDOS HOJE" :stat-title="String(stats.ordersToday)"
                stat-icon-name="fas fa-cart-shopping" variant="primary" />
            <CardStats stat-subtitle="DEVOLUÇÕES NO MÊS" :stat-title="String(stats.returnsMonth)"
                stat-icon-name="fas fa-rotate-left" variant="warning" />
        </div>

        <div v-if="revenueByChannel.length" class="mt-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-500 dark:text-slate-400">Faturamento por canal</h3>
            <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                <span v-for="row in revenueByChannel" :key="row.origin">
                    <span class="text-slate-500 dark:text-slate-400">{{ CHANNEL_LABELS[row.origin] ?? row.origin }}:</span>
                    <span class="font-semibold">{{ formatPrice(row.total) }}</span>
                </span>
            </div>
        </div>

        <div class="mt-8 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm transition-shadow hover:shadow-md">
                <div class="border-b border-[var(--surface-border)] px-4 py-4">
                    <h3 class="text-base font-semibold">Produtos mais vendidos</h3>
                    <p class="text-xs text-slate-400">Unidades vendidas nos últimos 30 dias</p>
                </div>
                <div class="p-4">
                    <p v-if="topProducts.length === 0" class="text-sm text-slate-500">Nenhuma venda nos últimos 30 dias.</p>
                    <ol v-else class="space-y-2 text-sm">
                        <li v-for="(product, index) in topProducts" :key="`${product.id}-${product.name}`"
                            class="flex items-center justify-between gap-3 border-b border-[var(--surface-border)] pb-2">
                            <span class="min-w-0 truncate">
                                <span class="me-1 text-slate-400">{{ index + 1 }}.</span>
                                <Link v-if="product.id" :href="`/admin/produtos/${product.id}/editar`" class="hover:text-primary hover:underline">{{ product.name }}</Link>
                                <span v-else>{{ product.name }}</span>
                                <span v-if="product.color" class="block text-xs font-semibold uppercase text-slate-500">{{ product.color }}</span>
                            </span>
                            <span class="shrink-0 text-right">
                                <span class="block font-semibold">{{ product.quantity }} un.</span>
                                <span class="block text-xs text-slate-400">{{ formatPrice(product.revenue) }}</span>
                            </span>
                        </li>
                    </ol>
                </div>
            </div>

            <div v-for="curva in abcCards" :key="curva.key"
                class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm transition-shadow hover:shadow-md">
                <div class="border-b border-[var(--surface-border)] px-4 py-4">
                    <h3 class="text-base font-semibold">Produtos curva {{ curva.key.toUpperCase() }}</h3>
                    <p class="text-xs text-slate-400">{{ curva.description }} · últimos 90 dias</p>
                </div>
                <div class="p-4">
                    <p v-if="curva.items.length === 0" class="text-sm text-slate-500">Nenhum produto nesta curva.</p>
                    <ul v-else class="space-y-2 text-sm">
                        <li v-for="product in curva.items.slice(0, 10)" :key="`${product.id}-${product.name}`"
                            class="flex items-center justify-between gap-3 border-b border-[var(--surface-border)] pb-2">
                            <span class="min-w-0 truncate">
                                <Link v-if="product.id" :href="`/admin/produtos/${product.id}/editar`" class="hover:text-primary hover:underline">{{ product.name }}</Link>
                                <span v-else>{{ product.name }}</span>
                                <span v-if="product.color" class="block text-xs font-semibold uppercase text-slate-500">{{ product.color }}</span>
                            </span>
                            <span class="shrink-0 text-right">
                                <span class="block font-semibold">{{ formatPrice(product.revenue) }}</span>
                                <span class="block text-xs text-slate-400">{{ product.share.toLocaleString('pt-BR') }}% do faturamento</span>
                            </span>
                        </li>
                    </ul>
                    <p v-if="curva.items.length > 10" class="mt-2 text-xs text-slate-400">+ {{ curva.items.length - 10 }} produto(s) nesta curva</p>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
