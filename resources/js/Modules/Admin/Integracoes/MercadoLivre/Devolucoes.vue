<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import SubNav from './SubNav.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { h } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    claims: { type: Array, default: () => [] },
});

const formatPrice = (value) =>
    value === null || value === undefined
        ? '—'
        : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const formatDate = (value) => (value ? new Date(value).toLocaleString('pt-BR') : '—');

const statusBadge = {
    opened: { color: 'open', label: 'Aberta' },
    closed: { color: 'completed', label: 'Fechada' },
};

const revertStock = async (claim) => {
    if (!claim.canRevertStock) {
        return;
    }

    if (await confirmDelete({
        title: `Reverter estoque do pedido #${claim.orderId}?`,
        text: 'Isso devolve pro estoque a quantidade de cada item desse pedido, como uma devolução real. Não desfaz sozinho — confira o pedido antes.',
        confirmButtonText: 'Sim, reverter estoque',
    })) {
        router.post(`/admin/integracoes/mercado-livre/devolucoes/${claim.id}/reverter-estoque`, {}, { preserveScroll: true });
    }
};

const columns = [
    {
        id: 'order',
        header: 'Pedido',
        cell: ({ row }) => row.original.orderId
            ? h('div', {}, [
                h(Link, { href: `/admin/pedidos/${row.original.orderId}`, class: 'hover:text-primary hover:underline' }, () => `#${row.original.orderId}`),
                row.original.externalOrderId
                    ? h('div', { class: 'text-xs text-slate-400' }, row.original.externalOrderId)
                    : null,
            ])
            : h('span', { class: 'text-xs text-slate-400' }, 'Pedido não localizado'),
    },
    { id: 'customer', header: 'Cliente', accessorFn: (row) => row.customer ?? '—' },
    { accessorKey: 'total', header: 'Total', cell: ({ row }) => formatPrice(row.original.total) },
    { accessorKey: 'typeLabel', header: 'Tipo' },
    { accessorKey: 'stageLabel', header: 'Etapa' },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) => {
            const badge = statusBadge[row.original.status] ?? { color: row.original.status, label: row.original.statusLabel };
            return h(StatusBadge, { status: badge.color, label: badge.label });
        },
    },
    { accessorKey: 'claimCreatedAt', header: 'Aberta em', cell: ({ row }) => formatDate(row.original.claimCreatedAt) },
    {
        id: 'stock',
        header: 'Estoque',
        cell: ({ row }) => {
            if (row.original.stockRestoredAt) {
                return h('span', { class: 'text-xs text-success' }, `Revertido em ${formatDate(row.original.stockRestoredAt)}`);
            }
            if (!row.original.orderId) {
                return h('span', { class: 'text-xs text-slate-400' }, '—');
            }
            return h('button', {
                type: 'button',
                class: 'rounded-lg border border-error px-3 py-1.5 text-xs font-medium text-error hover:bg-error/10',
                onClick: () => revertStock(row.original),
            }, 'Reverter estoque');
        },
    },
];
</script>

<template>
    <Head title="Devoluções — Mercado Livre" />

    <AdminLayout>
        <SubNav />

        <div class="mb-6">
            <h1 class="mb-1 text-2xl font-bold">Devoluções — Mercado Livre</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Reclamações/devoluções vindas do canal (tópico `post_purchase`). Não mexe em estoque nem em receita sozinho — a reversão de estoque é
                manual, botão por linha.
            </p>
        </div>

        <DataTable :columns="columns" :data="props.claims" empty-message="Nenhuma reclamação/devolução do Mercado Livre até agora." />
    </AdminLayout>
</template>
