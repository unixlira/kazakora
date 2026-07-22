<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link, router } from '@inertiajs/vue3';
import { h } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    purchaseOrders: { type: Array, default: () => [] },
});

const { can } = usePermissions();

const statusLabels = { draft: 'Rascunho', sent: 'Enviado', received: 'Recebido', cancelled: 'Cancelado' };
const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const destroy = async (po) => {
    if (await confirmDelete({ title: `Remover o pedido de compra #${po.id}?` })) {
        router.delete(`/admin/pedidos-de-compra/${po.id}`);
    }
};

const columns = [
    { accessorKey: 'id', header: 'Nº', cell: ({ row }) => `#${row.original.id}` },
    { id: 'supplier', header: 'Fornecedor', accessorFn: (row) => row.supplier?.name ?? '—' },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) => h(StatusBadge, { status: row.original.status, label: statusLabels[row.original.status] ?? row.original.status }),
    },
    {
        accessorKey: 'expected_date',
        header: 'Previsão',
        cell: ({ row }) => (row.original.expected_date ? new Date(`${row.original.expected_date}T00:00:00`).toLocaleDateString('pt-BR') : '—'),
    },
    { accessorKey: 'total', header: 'Total', cell: ({ row }) => formatPrice(row.original.total) },
    {
        id: 'actions',
        header: 'Ações',
        enableSorting: false,
        cell: ({ row }) => {
            const children = [
                h(Link, { href: `/admin/pedidos-de-compra/${row.original.id}`, class: 'text-sm hover:text-primary hover:underline' }, () => 'Ver'),
            ];
            if (can('operacional.delete') && row.original.status !== 'received') {
                children.push(h('button', { type: 'button', class: 'text-sm text-error hover:underline', onClick: () => destroy(row.original) }, 'Remover'));
            }
            return h('div', { class: 'flex items-center justify-end gap-3' }, children);
        },
    },
];
</script>

<template>
    <Head title="Pedidos de Compra" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Pedidos de Compra</h1>

        <DataTable
            :columns="columns"
            :data="props.purchaseOrders"
            search-placeholder="Buscar pedido de compra..."
            empty-message="Nenhum pedido de compra registrado."
            :create-label="can('operacional.create') ? 'Novo pedido de compra' : null"
            :create-href="can('operacional.create') ? '/admin/pedidos-de-compra/criar' : null"
        />
    </AdminLayout>
</template>
