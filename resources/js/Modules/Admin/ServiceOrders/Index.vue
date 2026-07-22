<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link, router } from '@inertiajs/vue3';
import { h } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    serviceOrders: { type: Array, default: () => [] },
});

const { can } = usePermissions();
const statusLabels = { open: 'Aberta', in_progress: 'Em andamento', completed: 'Concluída', cancelled: 'Cancelada' };

const destroy = async (so) => {
    if (await confirmDelete({ title: `Remover a ordem de serviço #${so.id}?` })) {
        router.delete(`/admin/ordens-de-servico/${so.id}`);
    }
};

const columns = [
    { accessorKey: 'id', header: 'Nº', cell: ({ row }) => `#${row.original.id}` },
    { accessorKey: 'customer_name', header: 'Cliente' },
    { accessorKey: 'description', header: 'Descrição', cell: ({ row }) => row.original.description.slice(0, 60) },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) => h(StatusBadge, { status: row.original.status, label: statusLabels[row.original.status] ?? row.original.status }),
    },
    { id: 'assignee', header: 'Responsável', accessorFn: (row) => row.assignee?.name ?? '—' },
    {
        id: 'actions',
        header: 'Ações',
        enableSorting: false,
        cell: ({ row }) => {
            const children = [];
            if (can('operacional.edit')) {
                children.push(h(Link, { href: `/admin/ordens-de-servico/${row.original.id}/editar`, class: 'text-sm hover:text-primary hover:underline' }, () => 'Editar'));
            }
            if (can('operacional.delete')) {
                children.push(h('button', { type: 'button', class: 'text-sm text-error hover:underline', onClick: () => destroy(row.original) }, 'Remover'));
            }
            return h('div', { class: 'flex items-center justify-end gap-3' }, children);
        },
    },
];
</script>

<template>
    <Head title="Ordens de Serviço" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Ordens de Serviço</h1>

        <DataTable
            :columns="columns"
            :data="props.serviceOrders"
            search-placeholder="Buscar ordem de serviço..."
            empty-message="Nenhuma ordem de serviço registrada."
            :create-label="can('operacional.create') ? 'Nova ordem de serviço' : null"
            :create-href="can('operacional.create') ? '/admin/ordens-de-servico/criar' : null"
        />
    </AdminLayout>
</template>
