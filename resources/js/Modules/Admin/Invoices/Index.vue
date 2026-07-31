<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import { Head, Link } from '@inertiajs/vue3';
import { computed, h, ref } from 'vue';

const props = defineProps({
    invoices: {
        type: Array,
        default: () => [],
    },
    summary: {
        type: Object,
        default: () => ({}),
    },
});

const formatPrice = (value) =>
    value === null || value === undefined
        ? '—'
        : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const formatDate = (value) => (value ? new Date(value).toLocaleDateString('pt-BR') : '—');

const originLabels = {
    loja: 'Loja',
};

// Mesma paleta usada em Orders/Index.vue e Orders/Show.vue — reaproveitada
// aqui pra manter a mesma leitura visual de status em todo o admin.
const invoiceBadge = {
    pending: { color: 'pending', label: 'Pendente' },
    signed: { color: 'shipped', label: 'Assinada' },
    sent: { color: 'shipped', label: 'Enviada à SEFAZ' },
    authorized: { color: 'completed', label: 'Emitida' },
    rejected: { color: 'cancelled', label: 'Rejeitada' },
    denied: { color: 'cancelled', label: 'Denegada' },
    cancelled: { color: 'cancelled', label: 'Cancelada' },
    error: { color: 'cancelled', label: 'Erro' },
};

const tabs = [
    { label: 'Todas', value: 'all' },
    { label: 'Emitidas', value: 'authorized' },
    { label: 'Canceladas', value: 'cancelled' },
    { label: 'Pendentes', value: 'pending_group' },
    { label: 'Com problema', value: 'failed_group' },
];

const activeTab = ref('all');

const filteredInvoices = computed(() => {
    switch (activeTab.value) {
        case 'authorized':
            return props.invoices.filter((invoice) => invoice.status === 'authorized');
        case 'cancelled':
            return props.invoices.filter((invoice) => invoice.status === 'cancelled');
        case 'pending_group':
            return props.invoices.filter((invoice) => ['pending', 'signed', 'sent'].includes(invoice.status));
        case 'failed_group':
            return props.invoices.filter((invoice) => ['rejected', 'denied', 'error'].includes(invoice.status));
        default:
            return props.invoices;
    }
});

const columns = [
    {
        id: 'numero',
        header: 'Nota',
        accessorFn: (row) => `${row.numero}/${row.serie}`,
    },
    {
        id: 'order_id',
        header: 'Pedido',
        accessorKey: 'order_id',
        cell: ({ row }) => h(Link, { href: `/admin/pedidos/${row.original.order_id}`, class: 'hover:text-primary hover:underline' }, () => `#${row.original.order_id}`),
    },
    { id: 'customer', header: 'Cliente', accessorFn: (row) => row.order?.user?.name ?? '—' },
    {
        id: 'origin',
        header: 'Plataforma',
        accessorFn: (row) => originLabels[row.order?.origin] ?? row.order?.origin ?? '—',
    },
    {
        id: 'external_order_id',
        header: 'Pedido na plataforma',
        accessorFn: (row) => row.order?.external_order_id ?? '—',
    },
    {
        accessorKey: 'valor_total',
        header: 'Valor',
        cell: ({ row }) => formatPrice(row.original.valor_total),
    },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) => {
            const badge = invoiceBadge[row.original.status] ?? { color: row.original.status, label: row.original.status };
            return h(StatusBadge, { status: badge.color, label: badge.label });
        },
    },
    { accessorKey: 'chave_acesso', header: 'Chave de acesso', cell: ({ row }) => h('span', { class: 'font-mono text-xs' }, row.original.chave_acesso ?? '—') },
    {
        id: 'autorizada_em',
        header: 'Emitida em',
        accessorFn: (row) => formatDate(row.autorizada_em),
    },
];
</script>

<template>
    <Head title="Notas Fiscais" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Notas Fiscais</h1>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-400">Notas emitidas</p>
                <p class="mt-1 text-2xl font-bold">{{ summary.authorized_count ?? 0 }}</p>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-400">Valor total emitido</p>
                <p class="mt-1 text-2xl font-bold">{{ formatPrice(summary.authorized_total ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-400">Canceladas</p>
                <p class="mt-1 text-2xl font-bold">{{ summary.cancelled_count ?? 0 }}</p>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-400">Pendentes / com problema</p>
                <p class="mt-1 text-2xl font-bold">{{ (summary.pending_count ?? 0) + (summary.failed_count ?? 0) }}</p>
            </div>
        </div>

        <DataTable
            :columns="columns"
            :data="filteredInvoices"
            :filter-tabs="tabs"
            search-placeholder="Buscar por pedido, cliente, chave de acesso..."
            empty-message="Nenhuma nota fiscal encontrada."
            @update:active-tab="activeTab = $event"
        />
    </AdminLayout>
</template>
