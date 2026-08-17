<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, router, useForm } from '@inertiajs/vue3';
import { confirmDelete } from '@/Shared/notify';
import { h } from 'vue';

const props = defineProps({
    products: {
        type: Array,
        default: () => [],
    },
});

const { can } = usePermissions();

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const destroy = async (product) => {
    if (await confirmDelete({ title: `Remover o produto "${product.name}"?` })) {
        router.delete(`/admin/produtos/${product.id}`);
    }
};

const columns = [
    {
        accessorKey: 'name',
        header: 'Produto',
        // Pedido explícito 2026-08-17 (variações de produto): antes disso
        // 2 variações do mesmo item físico apareciam como 2 linhas soltas
        // sem relação visível nenhuma (caso real: Ring Light 8"/10" e uma
        // 3ª duplicata desconectada) — não esconde nenhuma linha da busca
        // (search por nome/SKU continua achando qualquer variação), só
        // deixa o vínculo visível.
        cell: ({ row }) =>
            h('div', [
                h('p', { class: 'font-medium' }, row.original.name),
                h('p', { class: 'text-xs text-slate-400' }, [
                    row.original.sku,
                    row.original.parent
                        ? h('span', { class: 'ml-1.5 text-blue-500' }, `· Variação de ${row.original.parent.name}`)
                        : row.original.children_count
                            ? h('span', { class: 'ml-1.5 text-emerald-600' }, `· ${row.original.children_count} variação(ões)`)
                            : null,
                ]),
            ]),
    },
    {
        id: 'category',
        header: 'Categoria',
        accessorFn: (row) => row.category?.name ?? '—',
    },
    {
        accessorKey: 'price',
        header: 'Preço',
        cell: ({ row }) => formatPrice(row.original.price),
    },
    {
        accessorKey: 'cost_price',
        header: 'Preço de custo',
        cell: ({ row }) => (row.original.cost_price != null ? formatPrice(row.original.cost_price) : '—'),
    },
    {
        accessorKey: 'stock',
        header: 'Estoque',
        cell: ({ row }) => h('span', { class: row.original.stock <= 5 ? 'text-error font-medium' : '' }, row.original.stock),
    },
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
                children.push(h(ActionIcon, { icon: 'fa-pen', label: 'Editar', color: 'blue', href: `/admin/produtos/${row.original.id}/editar` }));
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
    <Head title="Produtos" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Produtos</h1>

        <DataTable
            :columns="columns"
            :data="props.products"
            search-placeholder="Buscar por nome ou SKU..."
            empty-message="Nenhum produto cadastrado."
            :create-label="can('cadastros.create') ? 'Novo produto' : null"
            :create-href="can('cadastros.create') ? '/admin/produtos/criar' : null"
        />
    </AdminLayout>
</template>
