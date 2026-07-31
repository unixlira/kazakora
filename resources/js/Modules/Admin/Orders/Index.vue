<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import { Head, Link } from '@inertiajs/vue3';
import { h } from 'vue';

const props = defineProps({
    orders: {
        type: Array,
        default: () => [],
    },
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const statusLabels = {
    pending: 'Pendente',
    paid: 'Pago',
    shipped: 'Enviado',
    completed: 'Concluído',
    cancelled: 'Cancelado',
};

// StatusBadge escolhe a cor pelo valor de "status" — reaproveitamos as
// cores já definidas lá (não são específicas de pedido) pra nota fiscal e
// e-mail em vez de duplicar uma paleta nova.
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

const emailBadge = {
    sent: { color: 'completed', label: 'Enviado' },
    failed: { color: 'cancelled', label: 'Falhou' },
};

const columns = [
    {
        accessorKey: 'id',
        header: 'Pedido',
        cell: ({ row }) => h(Link, { href: `/admin/pedidos/${row.original.id}`, class: 'hover:text-primary hover:underline' }, () => `#${row.original.id}`),
    },
    { id: 'customer', header: 'Cliente', accessorFn: (row) => row.user?.name ?? '—' },
    { accessorKey: 'items_count', header: 'Itens' },
    { accessorKey: 'total', header: 'Total', cell: ({ row }) => formatPrice(row.original.total) },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) => h(StatusBadge, { status: row.original.status, label: statusLabels[row.original.status] ?? row.original.status }),
    },
    {
        id: 'invoice_status',
        header: 'Nota Fiscal',
        cell: ({ row }) => {
            const status = row.original.invoice?.status;
            if (!status) return h('span', { class: 'text-xs text-slate-400' }, '—');
            const badge = invoiceBadge[status] ?? { color: status, label: status };
            return h(StatusBadge, { status: badge.color, label: badge.label });
        },
    },
    {
        id: 'email_status',
        header: 'E-mail',
        cell: ({ row }) => {
            const log = row.original.latest_email_log;
            if (!log) return h('span', { class: 'text-xs text-slate-400' }, '—');
            const badge = emailBadge[log.status] ?? { color: log.status, label: log.status };
            return h('div', { class: 'flex items-center gap-1.5' }, [
                h(StatusBadge, { status: badge.color, label: badge.label }),
                log.status === 'sent' && !log.invoice_attached
                    ? h('span', { class: 'text-xs text-amber-600 dark:text-amber-400', title: 'Enviado sem a nota fiscal em anexo' }, 'sem anexo')
                    : null,
            ]);
        },
    },
    {
        accessorKey: 'created_at',
        header: 'Data',
        cell: ({ row }) => new Date(row.original.created_at).toLocaleDateString('pt-BR'),
    },
];
</script>

<template>
    <Head title="Pedidos" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Pedidos</h1>

        <DataTable
            :columns="columns"
            :data="props.orders"
            search-placeholder="Buscar pedido..."
            empty-message="Nenhum pedido encontrado."
        />
    </AdminLayout>
</template>
