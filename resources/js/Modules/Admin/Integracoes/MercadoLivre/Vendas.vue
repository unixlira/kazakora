<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import SubNav from './SubNav.vue';
import { Head, Link } from '@inertiajs/vue3';
import { h } from 'vue';

const props = defineProps({
    orders: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
});

const formatPrice = (value) =>
    value === null || value === undefined
        ? '—'
        : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const statusLabels = {
    pending: 'Pendente',
    paid: 'Pago',
    shipped: 'Enviado',
    completed: 'Concluído',
    cancelled: 'Cancelado',
};

const columns = [
    {
        accessorKey: 'id',
        header: 'Pedido',
        cell: ({ row }) => h('div', {}, [
            h(Link, { href: `/admin/pedidos/${row.original.id}`, class: 'hover:text-primary hover:underline' }, () => `#${row.original.id}`),
            row.original.externalOrderId
                ? h('div', { class: 'text-xs text-slate-400' }, row.original.externalOrderId)
                : null,
        ]),
    },
    { id: 'customer', header: 'Cliente', accessorFn: (row) => row.customer ?? '—' },
    { accessorKey: 'itemsCount', header: 'Itens' },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) => h(StatusBadge, { status: row.original.status, label: statusLabels[row.original.status] ?? row.original.status }),
    },
    { accessorKey: 'gross', header: 'Bruto', cell: ({ row }) => formatPrice(row.original.gross) },
    {
        accessorKey: 'fee',
        header: 'Taxa Mercado Livre',
        cell: ({ row }) => h('span', { class: row.original.fee ? 'text-error' : 'text-slate-400' }, formatPrice(row.original.fee)),
    },
    {
        accessorKey: 'net',
        header: 'Líquido',
        cell: ({ row }) => h('span', { class: 'font-semibold text-success' }, formatPrice(row.original.net)),
    },
];
</script>

<template>
    <Head title="Vendas — Mercado Livre" />

    <AdminLayout>
        <SubNav />

        <div class="mb-6">
            <h1 class="mb-1 text-2xl font-bold">Vendas — Mercado Livre</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ props.summary.count ?? 0 }} pedido(s) do canal, valor bruto/taxa/líquido conforme informado pela própria API do Mercado Livre.
            </p>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <p class="text-sm text-slate-500 dark:text-slate-400">Total bruto</p>
                <p class="mt-1 text-2xl font-bold">{{ formatPrice(props.summary.grossTotal) }}</p>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <p class="text-sm text-slate-500 dark:text-slate-400">Total em taxas do Mercado Livre</p>
                <p class="mt-1 text-2xl font-bold text-error">{{ formatPrice(props.summary.feeTotal) }}</p>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <p class="text-sm text-slate-500 dark:text-slate-400">Total líquido</p>
                <p class="mt-1 text-2xl font-bold text-success">{{ formatPrice(props.summary.netTotal) }}</p>
            </div>
        </div>

        <p v-if="props.summary.withFeeDataCount < props.summary.count" class="mb-4 text-xs text-slate-400">
            Taxa/líquido somados só sobre os {{ props.summary.withFeeDataCount }} pedido(s) com dado real de taxa vindo da API do Mercado Livre — os
            demais ainda não tiveram o `sale_fee` importado (pedidos antigos, ou taxa não devolvida pelo canal).
        </p>

        <DataTable :columns="columns" :data="props.orders" empty-message="Nenhuma venda do Mercado Livre ainda." />
    </AdminLayout>
</template>
