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
    recentOrders: {
        type: Array,
        default: () => [],
    },
    lowStockProducts: {
        type: Array,
        default: () => [],
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
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const formatShortDate = (date) =>
    new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit' }).format(new Date(`${date}T00:00:00`));

// Same hues as the CSS design tokens (--color-primary/secondary/success/warning/error/info).
const chartPalette = ['#5d87ff', '#49beff', '#13deb9', '#f6b51e', '#ef4444', '#8754ec'];

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
            borderColor: '#5d87ff',
            backgroundColor: '#5d87ff33',
            fill: true,
            tension: 0.4,
        },
        {
            label: 'Visitantes únicos',
            data: props.visitsSeries.map((item) => item.visitors),
            borderColor: '#8754ec',
            backgroundColor: '#8754ec33',
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
            backgroundColor: '#13deb9',
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
            <CardStats stat-subtitle="PEDIDOS" :stat-title="String(stats.ordersCount)"
                stat-icon-name="fas fa-receipt" variant="primary" />
            <CardStats stat-subtitle="FATURAMENTO" :stat-title="formatPrice(stats.revenue)"
                stat-icon-name="fas fa-sack-dollar" variant="success" />
            <CardStats stat-subtitle="PRODUTOS" :stat-title="String(stats.productsCount)"
                stat-icon-name="fas fa-couch" variant="secondary" />
            <CardStats stat-subtitle="ESTOQUE BAIXO" :stat-title="String(stats.lowStockCount)"
                stat-icon-name="fas fa-triangle-exclamation" variant="error" />
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <CardStats stat-subtitle="VISITAS HOJE" :stat-title="String(stats.visitsToday)"
                stat-icon-name="fas fa-eye" variant="info" />
            <CardStats stat-subtitle="PEDIDOS HOJE" :stat-title="String(stats.ordersToday)"
                stat-icon-name="fas fa-cart-shopping" variant="primary" />
            <CardStats stat-subtitle="PEDIDOS NO MÊS" :stat-title="String(stats.ordersMonth)"
                stat-icon-name="fas fa-calendar-check" variant="secondary" />
            <CardStats stat-subtitle="FATURADO HOJE" :stat-title="formatPrice(stats.revenueToday)"
                stat-icon-name="fas fa-money-bill-wave" variant="success" />
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <CardStats stat-subtitle="DEVOLUÇÕES NO MÊS" :stat-title="String(stats.returnsMonth)"
                stat-icon-name="fas fa-rotate-left" variant="warning" />
            <CardStats stat-subtitle="CARRINHOS ATIVOS" :stat-title="String(stats.activeCartsCount)"
                stat-icon-name="fas fa-cart-arrow-down" variant="info" />
            <CardStats stat-subtitle="CLIQUES EM PRODUTOS" :stat-title="String(stats.productViewsCount)"
                stat-icon-name="fas fa-computer-mouse" variant="secondary" />
            <div class="flex min-w-0 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-[var(--surface-border)] p-4 text-center text-slate-400">
                <i class="fas fa-plus text-xl"></i>
                <span class="text-sm">Métrica reservada</span>
            </div>
        </div>

        <div class="mt-8 flex flex-wrap">
            <div :class="chartCardClass">
                <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm transition-shadow hover:shadow-md">
                    <div class="border-b border-[var(--surface-border)] px-4 py-4">
                        <h3 class="text-lg font-semibold">Pedidos por status</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Distribuição de todos os pedidos</p>
                    </div>
                    <div class="p-4">
                        <ChartCanvas type="pie" :data="orderStatusChartData" :options="orderStatusChartOptions" />
                    </div>
                </div>
            </div>

            <div :class="chartCardClass">
                <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm transition-shadow hover:shadow-md">
                    <div class="border-b border-[var(--surface-border)] px-4 py-4">
                        <h3 class="text-lg font-semibold">Visitas</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Últimos 14 dias</p>
                    </div>
                    <div class="p-4">
                        <ChartCanvas type="line" :data="visitsChartData" />
                    </div>
                </div>
            </div>

            <div :class="chartCardClass">
                <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm transition-shadow hover:shadow-md">
                    <div class="border-b border-[var(--surface-border)] px-4 py-4">
                        <h3 class="text-lg font-semibold">Faturamento diário</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Últimos 14 dias</p>
                    </div>
                    <div class="p-4">
                        <ChartCanvas type="bar" :data="revenueChartData" />
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-8 flex flex-wrap">
            <div class="w-full px-4 lg:w-6/12">
                <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm transition-shadow hover:shadow-md">
                    <div class="border-b border-[var(--surface-border)] px-4 py-4">
                        <h3 class="text-base font-semibold">Pedidos recentes</h3>
                    </div>
                    <div class="p-4">
                        <p v-if="recentOrders.length === 0" class="text-sm text-slate-500">Nenhum pedido ainda.</p>
                        <ul v-else class="space-y-2 text-sm">
                            <li v-for="order in recentOrders" :key="order.id"
                                class="flex justify-between border-b border-[var(--surface-border)] pb-2">
                                <Link :href="`/admin/pedidos/${order.id}`" class="hover:text-primary hover:underline">
                                    #{{ order.id }} — {{ order.user?.name }}
                                </Link>
                                <span class="font-medium">{{ formatPrice(order.total) }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="w-full px-4 lg:w-6/12">
                <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm transition-shadow hover:shadow-md">
                    <div class="border-b border-[var(--surface-border)] px-4 py-4">
                        <h3 class="text-base font-semibold">Estoque baixo</h3>
                    </div>
                    <div class="p-4">
                        <p v-if="lowStockProducts.length === 0" class="text-sm text-slate-500">Nenhum produto com estoque baixo.</p>
                        <ul v-else class="space-y-2 text-sm">
                            <li v-for="product in lowStockProducts" :key="product.id"
                                class="flex justify-between border-b border-[var(--surface-border)] pb-2">
                                <Link :href="`/admin/produtos/${product.id}/editar`" class="hover:text-primary hover:underline">
                                    {{ product.name }}
                                </Link>
                                <span class="font-medium text-error">{{ product.stock }} un.</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
