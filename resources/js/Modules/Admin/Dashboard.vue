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

const chartPalette = ['#0ea5e9', '#f97316', '#10b981', '#a855f7', '#f43f5e', '#64748b'];

const orderStatusChartData = computed(() => ({
    labels: props.orderStatusBreakdown.map((item) => item.label),
    datasets: [
        {
            data: props.orderStatusBreakdown.map((item) => item.total),
            backgroundColor: chartPalette,
        },
    ],
}));

const visitsChartData = computed(() => ({
    labels: props.visitsSeries.map((item) => formatShortDate(item.date)),
    datasets: [
        {
            label: 'Visualizações',
            data: props.visitsSeries.map((item) => item.views),
            borderColor: '#0ea5e9',
            backgroundColor: '#0ea5e9',
            tension: 0.3,
        },
        {
            label: 'Visitantes únicos',
            data: props.visitsSeries.map((item) => item.visitors),
            borderColor: '#a855f7',
            backgroundColor: '#a855f7',
            tension: 0.3,
        },
    ],
}));

const revenueChartData = computed(() => ({
    labels: props.revenueSeries.map((item) => formatShortDate(item.date)),
    datasets: [
        {
            label: 'Faturamento',
            data: props.revenueSeries.map((item) => item.revenue),
            backgroundColor: '#10b981',
        },
    ],
}));

const chartCardClass = 'w-full px-4 xl:w-4/12';
</script>

<template>
    <Head title="Dashboard" />

    <AdminLayout>
        <div class="flex flex-wrap">
            <div class="w-full px-4 lg:w-6/12 xl:w-3/12">
                <CardStats stat-subtitle="PEDIDOS" :stat-title="String(stats.ordersCount)"
                    stat-icon-name="fas fa-receipt" stat-icon-color="bg-red-500" />
            </div>
            <div class="w-full px-4 lg:w-6/12 xl:w-3/12">
                <CardStats stat-subtitle="FATURAMENTO" :stat-title="formatPrice(stats.revenue)"
                    stat-icon-name="fas fa-sack-dollar" stat-icon-color="bg-orange-500" />
            </div>
            <div class="w-full px-4 lg:w-6/12 xl:w-3/12">
                <CardStats stat-subtitle="PRODUTOS" :stat-title="String(stats.productsCount)"
                    stat-icon-name="fas fa-couch" stat-icon-color="bg-emerald-500" />
            </div>
            <div class="w-full px-4 lg:w-6/12 xl:w-3/12">
                <CardStats stat-subtitle="ESTOQUE BAIXO" :stat-title="String(stats.lowStockCount)"
                    stat-icon-name="fas fa-triangle-exclamation" stat-icon-color="bg-rose-600" />
            </div>
        </div>

        <div class="mt-4 flex flex-wrap">
            <div class="w-full px-4 lg:w-6/12 xl:w-3/12">
                <CardStats stat-subtitle="VISITAS HOJE" :stat-title="String(stats.visitsToday)"
                    stat-icon-name="fas fa-eye" stat-icon-color="bg-sky-500" />
            </div>
            <div class="w-full px-4 lg:w-6/12 xl:w-3/12">
                <CardStats stat-subtitle="PEDIDOS HOJE" :stat-title="String(stats.ordersToday)"
                    stat-icon-name="fas fa-cart-shopping" stat-icon-color="bg-indigo-500" />
            </div>
            <div class="w-full px-4 lg:w-6/12 xl:w-3/12">
                <CardStats stat-subtitle="PEDIDOS NO MÊS" :stat-title="String(stats.ordersMonth)"
                    stat-icon-name="fas fa-calendar-check" stat-icon-color="bg-purple-500" />
            </div>
            <div class="w-full px-4 lg:w-6/12 xl:w-3/12">
                <CardStats stat-subtitle="FATURADO HOJE" :stat-title="formatPrice(stats.revenueToday)"
                    stat-icon-name="fas fa-money-bill-wave" stat-icon-color="bg-teal-500" />
            </div>
        </div>

        <div class="mt-4 flex flex-wrap">
            <div class="w-full px-4 lg:w-6/12 xl:w-3/12">
                <CardStats stat-subtitle="DEVOLUÇÕES NO MÊS" :stat-title="String(stats.returnsMonth)"
                    stat-icon-name="fas fa-rotate-left" stat-icon-color="bg-amber-600" />
            </div>
        </div>

        <div class="mt-8 flex flex-wrap">
            <div :class="chartCardClass">
                <div class="rounded bg-white shadow-lg">
                    <div class="border-b border-slate-200 px-4 py-4">
                        <h3 class="text-base font-semibold text-slate-700">Pedidos por status</h3>
                    </div>
                    <div class="p-4">
                        <ChartCanvas type="pie" :data="orderStatusChartData" />
                    </div>
                </div>
            </div>

            <div :class="chartCardClass">
                <div class="rounded bg-white shadow-lg">
                    <div class="border-b border-slate-200 px-4 py-4">
                        <h3 class="text-base font-semibold text-slate-700">Visitas (últimos 14 dias)</h3>
                    </div>
                    <div class="p-4">
                        <ChartCanvas type="line" :data="visitsChartData" />
                    </div>
                </div>
            </div>

            <div :class="chartCardClass">
                <div class="rounded bg-white shadow-lg">
                    <div class="border-b border-slate-200 px-4 py-4">
                        <h3 class="text-base font-semibold text-slate-700">Faturamento diário (últimos 14 dias)</h3>
                    </div>
                    <div class="p-4">
                        <ChartCanvas type="bar" :data="revenueChartData" />
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-8 flex flex-wrap">
            <div class="w-full px-4 lg:w-6/12">
                <div class="rounded bg-white shadow-lg">
                    <div class="border-b border-slate-200 px-4 py-4">
                        <h3 class="text-base font-semibold text-slate-700">Pedidos recentes</h3>
                    </div>
                    <div class="p-4">
                        <p v-if="recentOrders.length === 0" class="text-sm text-slate-500">Nenhum pedido ainda.</p>
                        <ul v-else class="space-y-2 text-sm">
                            <li v-for="order in recentOrders" :key="order.id"
                                class="flex justify-between border-b border-slate-100 pb-2">
                                <Link :href="`/admin/pedidos/${order.id}`" class="text-slate-600 hover:underline">
                                    #{{ order.id }} — {{ order.user?.name }}
                                </Link>
                                <span class="font-medium">{{ formatPrice(order.total) }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="w-full px-4 lg:w-6/12">
                <div class="rounded bg-white shadow-lg">
                    <div class="border-b border-slate-200 px-4 py-4">
                        <h3 class="text-base font-semibold text-slate-700">Estoque baixo</h3>
                    </div>
                    <div class="p-4">
                        <p v-if="lowStockProducts.length === 0" class="text-sm text-slate-500">Nenhum produto com estoque baixo.</p>
                        <ul v-else class="space-y-2 text-sm">
                            <li v-for="product in lowStockProducts" :key="product.id"
                                class="flex justify-between border-b border-slate-100 pb-2">
                                <Link :href="`/admin/produtos/${product.id}/editar`" class="text-slate-600 hover:underline">
                                    {{ product.name }}
                                </Link>
                                <span class="font-medium text-rose-600">{{ product.stock }} un.</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
