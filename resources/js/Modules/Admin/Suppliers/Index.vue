<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link, router } from '@inertiajs/vue3';
import { h } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    suppliers: {
        type: Array,
        default: () => [],
    },
});

const { can } = usePermissions();

const destroy = async (supplier) => {
    if (await confirmDelete({ title: `Remover o fornecedor "${supplier.name}"?` })) {
        router.delete(`/admin/fornecedores/${supplier.id}`);
    }
};

const columns = [
    { accessorKey: 'name', header: 'Nome' },
    { accessorKey: 'document', header: 'CNPJ/CPF', cell: ({ row }) => row.original.document ?? '—' },
    { accessorKey: 'email', header: 'E-mail', cell: ({ row }) => row.original.email ?? '—' },
    { accessorKey: 'phone', header: 'Telefone', cell: ({ row }) => row.original.phone ?? '—' },
    { id: 'city', header: 'Cidade/UF', accessorFn: (row) => (row.city ? `${row.city}/${row.state ?? ''}` : '—') },
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
                children.push(h(Link, { href: `/admin/fornecedores/${row.original.id}/editar`, class: 'text-sm hover:text-primary hover:underline' }, () => 'Editar'));
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
    <Head title="Fornecedores" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Fornecedores</h1>

        <DataTable
            :columns="columns"
            :data="props.suppliers"
            search-placeholder="Buscar fornecedor..."
            empty-message="Nenhum fornecedor cadastrado."
            :create-label="can('cadastros.create') ? 'Novo fornecedor' : null"
            :create-href="can('cadastros.create') ? '/admin/fornecedores/criar' : null"
        />
    </AdminLayout>
</template>
