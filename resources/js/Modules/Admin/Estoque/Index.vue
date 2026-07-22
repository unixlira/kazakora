<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable } from '@/Shared/Components/DataTable';
import { Head } from '@inertiajs/vue3';
import { h } from 'vue';

const props = defineProps({
    movements: { type: Array, default: () => [] },
});

const typeLabels = {
    sale: 'Venda',
    restock: 'Reposição',
    adjustment: 'Ajuste manual',
    return: 'Devolução',
    marketplace_sync: 'Sincronização',
};

const columns = [
    { id: 'product', header: 'Produto', accessorFn: (row) => row.product?.name ?? '—' },
    { id: 'sku', header: 'SKU', accessorFn: (row) => row.product?.sku ?? '—' },
    { id: 'type', header: 'Tipo', cell: ({ row }) => typeLabels[row.original.type] ?? row.original.type },
    {
        accessorKey: 'quantity',
        header: 'Quantidade',
        cell: ({ row }) => h('span', { class: row.original.quantity < 0 ? 'text-error' : 'text-success' }, row.original.quantity > 0 ? `+${row.original.quantity}` : row.original.quantity),
    },
    { accessorKey: 'stock_after', header: 'Estoque após' },
    { accessorKey: 'reason', header: 'Motivo', cell: ({ row }) => row.original.reason ?? '—' },
    { id: 'user', header: 'Usuário', accessorFn: (row) => row.user?.name ?? 'Sistema' },
    {
        accessorKey: 'created_at',
        header: 'Data/Hora',
        cell: ({ row }) => new Date(row.original.created_at).toLocaleString('pt-BR'),
    },
];
</script>

<template>
    <Head title="Estoque" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Movimentações de estoque</h1>
        <p class="mb-4 text-sm text-slate-400">Histórico completo (últimas 500 movimentações). Para ajustar o estoque de um produto, edite-o na tela de Produtos.</p>

        <DataTable
            :columns="columns"
            :data="props.movements"
            search-placeholder="Buscar por produto..."
            empty-message="Nenhuma movimentação de estoque registrada."
        />
    </AdminLayout>
</template>
