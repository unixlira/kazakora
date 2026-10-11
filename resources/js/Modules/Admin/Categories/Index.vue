<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { DataTable } from '@/Shared/Components/DataTable';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, h } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    categories: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const categoryError = computed(() => page.props.errors?.category);
const { can } = usePermissions();

const destroy = async (category) => {
    if (await confirmDelete({ title: `Remover a categoria "${category.name}"?` })) {
        router.delete(`/admin/categorias/${category.id}`);
    }
};

const columns = [
    {
        id: 'image',
        header: '',
        enableSorting: false,
        cell: ({ row }) => row.original.image_url
            ? h('img', { src: row.original.image_url, class: 'h-9 w-9 rounded-full object-cover' })
            : h('div', { class: 'h-9 w-9 rounded-full bg-gray-100' }),
    },
    { accessorKey: 'name', header: 'Nome' },
    { accessorKey: 'products_count', header: 'Produtos' },
    {
        id: 'actions',
        header: 'Ações',
        enableSorting: false,
        cell: ({ row }) => {
            const children = [];
            if (can('cadastros.edit')) {
                children.push(h(ActionIcon, { icon: 'fa-pen', label: 'Editar', color: 'blue', href: `/admin/categorias/${row.original.id}/editar` }));
            }
            if (can('cadastros.delete')) {
                children.push(h(ActionIcon, { icon: 'fa-trash', label: 'Remover', color: 'red', onClick: () => destroy(row.original) }));
            }
            return h('div', { class: 'flex items-center justify-end gap-2' }, children);
        },
    },
];
</script>

<template>
    <Head title="Categorias" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Categorias</h1>

        <InputError :message="categoryError" class="mb-4" />

        <DataTable
            :columns="columns"
            :data="props.categories"
            search-placeholder="Buscar categoria..."
            empty-message="Nenhuma categoria cadastrada."
            :create-label="can('cadastros.create') ? 'Nova categoria' : null"
            :create-href="can('cadastros.create') ? '/admin/categorias/criar' : null"
        />
    </AdminLayout>
</template>
