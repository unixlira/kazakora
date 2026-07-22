<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { DataTable } from '@/Shared/Components/DataTable';
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    filters: { type: Object, required: true },
    salesByDay: { type: Array, default: () => [] },
    topProducts: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
});

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const filterState = reactive({ from: props.filters.from, to: props.filters.to });

const applyFilters = () => {
    router.get('/admin/relatorios', filterState, { preserveState: true });
};

const salesColumns = [
    { accessorKey: 'date', header: 'Data', cell: ({ row }) => new Date(`${row.original.date}T00:00:00`).toLocaleDateString('pt-BR') },
    { accessorKey: 'orders_count', header: 'Pedidos' },
    { accessorKey: 'revenue', header: 'Faturamento', cell: ({ row }) => formatPrice(row.original.revenue) },
];

const productColumns = [
    { accessorKey: 'product_name', header: 'Produto' },
    { accessorKey: 'quantity_sold', header: 'Qtd. vendida' },
    { accessorKey: 'revenue', header: 'Faturamento', cell: ({ row }) => formatPrice(row.original.revenue) },
];
</script>

<template>
    <Head title="Relatórios" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Relatórios de vendas</h1>

        <div class="mb-6 flex flex-wrap items-end gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <div>
                <label class="block text-xs font-medium text-slate-400">De</label>
                <input v-model="filterState.from" type="date" class="mt-1 rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400">Até</label>
                <input v-model="filterState.to" type="date" class="mt-1 rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <button type="button" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis" @click="applyFilters">
                Filtrar
            </button>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <CardStats stat-subtitle="PEDIDOS NO PERÍODO" :stat-title="String(summary.ordersCount)" stat-icon-name="fas fa-receipt" stat-icon-color="bg-primary" />
            <CardStats stat-subtitle="FATURAMENTO NO PERÍODO" :stat-title="formatPrice(summary.revenue)" stat-icon-name="fas fa-sack-dollar" stat-icon-color="bg-success" />
        </div>

        <h2 class="mb-2 text-lg font-semibold">Vendas por dia</h2>
        <DataTable :columns="salesColumns" :data="props.salesByDay" search-placeholder="Buscar por data..." empty-message="Nenhuma venda no período." />

        <h2 class="mb-2 mt-8 text-lg font-semibold">Produtos mais vendidos</h2>
        <DataTable :columns="productColumns" :data="props.topProducts" search-placeholder="Buscar produto..." empty-message="Nenhum produto vendido no período." />
    </AdminLayout>
</template>
