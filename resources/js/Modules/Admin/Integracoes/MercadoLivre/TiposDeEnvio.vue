<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import SubNav from './SubNav.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { h } from 'vue';

const props = defineProps({
    shipments: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    methodOptions: { type: Array, default: () => [] },
    totalCount: { type: Number, default: 0 },
});

const formatPrice = (value) =>
    value === null || value === undefined
        ? '—'
        : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const formatDate = (value) => (value ? new Date(value).toLocaleString('pt-BR') : '—');

const shipmentStatusBadge = {
    pending: { color: 'pending', label: 'Pendente' },
    confirmed: { color: 'processing', label: 'Confirmado' },
    label_ready: { color: 'shipped', label: 'Etiqueta pronta' },
    label_downloaded: { color: 'completed', label: 'Etiqueta baixada' },
    error: { color: 'cancelled', label: 'Erro' },
};

const filterByTipo = (tipo) => {
    router.get('/admin/integracoes/mercado-livre/envios', tipo ? { tipo } : {}, { preserveState: true });
};

const columns = [
    {
        id: 'order',
        header: 'Pedido',
        cell: ({ row }) => h('div', {}, [
            h(Link, { href: `/admin/pedidos/${row.original.orderId}`, class: 'hover:text-primary hover:underline' }, () => `#${row.original.orderId}`),
            row.original.externalOrderId
                ? h('div', { class: 'text-xs text-slate-400' }, row.original.externalOrderId)
                : null,
        ]),
    },
    { id: 'customer', header: 'Cliente', accessorFn: (row) => row.customer ?? '—' },
    {
        id: 'destino',
        header: 'Destino',
        accessorFn: (row) => [row.city, row.state].filter(Boolean).join('/') || '—',
    },
    {
        id: 'shippingMethod',
        header: 'Tipo de envio',
        cell: ({ row }) => h(StatusBadge, { status: row.original.shippingMethod ?? 'unknown', label: row.original.shippingMethodLabel }),
    },
    {
        accessorKey: 'status',
        header: 'Status do envio',
        cell: ({ row }) => {
            const badge = shipmentStatusBadge[row.original.status] ?? { color: row.original.status, label: row.original.status };
            return h(StatusBadge, { status: badge.color, label: badge.label });
        },
    },
    { accessorKey: 'total', header: 'Total', cell: ({ row }) => formatPrice(row.original.total) },
    { accessorKey: 'confirmedAt', header: 'Frete confirmado em', cell: ({ row }) => formatDate(row.original.confirmedAt) },
];
</script>

<template>
    <Head title="Tipos de envio — Mercado Livre" />

    <AdminLayout>
        <SubNav />

        <div class="mb-6">
            <h1 class="mb-1 text-2xl font-bold">Tipos de envio — Mercado Livre</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Flex, Full ou coleta (Correios/PAC) por venda — direto do que o Mercado Livre confirmou no envio de cada pedido.
            </p>
        </div>

        <div class="mb-6 flex flex-wrap gap-2">
            <button type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                :class="!filters.tipo ? 'bg-primary text-white' : 'border border-[var(--surface-border)] hover:bg-lightprimary'"
                @click="filterByTipo(null)">
                Todos ({{ totalCount }})
            </button>
            <button v-for="option in methodOptions" :key="option.value" type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                :class="filters.tipo === option.value ? 'bg-primary text-white' : 'border border-[var(--surface-border)] hover:bg-lightprimary'"
                @click="filterByTipo(option.value)">
                {{ option.label }} ({{ option.count }})
            </button>
        </div>

        <DataTable :columns="columns" :data="props.shipments" empty-message="Nenhum envio do Mercado Livre encontrado." />
    </AdminLayout>
</template>
