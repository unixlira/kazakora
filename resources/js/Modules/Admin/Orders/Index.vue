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
