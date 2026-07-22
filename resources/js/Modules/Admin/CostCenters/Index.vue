<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link, router } from '@inertiajs/vue3';
import { h } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    costCenters: {
        type: Array,
        default: () => [],
    },
});

const { can } = usePermissions();

const destroy = async (costCenter) => {
    if (await confirmDelete({ title: `Remover o centro de custo "${costCenter.name}"?` })) {
        router.delete(`/admin/centros-de-custo/${costCenter.id}`);
    }
};

const columns = [
    { accessorKey: 'code', header: 'Código' },
    { accessorKey: 'name', header: 'Nome' },
    { accessorKey: 'description', header: 'Descrição', cell: ({ row }) => row.original.description ?? '—' },
    {
        id: 'status',
        header: 'Status',
        accessorFn: (row) => (row.is_active ? 'Active' : 'Inactive'),
        cell: ({ row }) => h(StatusBadge, { status: row.original.is_active ? 'active' : 'inactive', label: row.original.is_active ? 'Ativo' : 'Inativo' }),
    },
    {
        id: 'actions',
        header: 'Ações',
        enableSorting: false,
        cell: ({ row }) => {
            const children = [];
            if (can('cadastros.edit')) {
                children.push(h(Link, { href: `/admin/centros-de-custo/${row.original.id}/editar`, class: 'text-sm hover:text-primary hover:underline' }, () => 'Editar'));
            }
            if (can('cadastros.delete')) {
                children.push(h('button', { type: 'button', class: 'text-sm text-error hover:underline', onClick: () => destroy(row.original) }, 'Remover'));
            }
            return h('div', { class: 'flex items-center justify-end gap-3' }, children);
        },
    },
];
</script>

<template>
    <Head title="Centros de custo" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Centros de custo</h1>

        <DataTable
            :columns="columns"
            :data="props.costCenters"
            search-placeholder="Buscar centro de custo..."
            empty-message="Nenhum centro de custo cadastrado."
            :create-label="can('cadastros.create') ? 'Novo centro de custo' : null"
            :create-href="can('cadastros.create') ? '/admin/centros-de-custo/criar' : null"
        />
    </AdminLayout>
</template>
