<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import SubNav from './SubNav.vue';
import { Head, Link } from '@inertiajs/vue3';
import { h } from 'vue';

const props = defineProps({
    listings: { type: Array, default: () => [] },
});

const formatPrice = (value) =>
    value === null || value === undefined
        ? '—'
        : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const formatDate = (value) => (value ? new Date(value).toLocaleString('pt-BR') : '—');

// StatusBadge já cobre 'draft'/'pending' (amarelo); 'published'/'error' não
// existem na paleta padrão dela (são valores do módulo de Marketplace, não
// do pedido) — reaproveita as mesmas cores via label/status explícitos,
// mesmo padrão do invoiceBadge em Orders/Index.vue.
const statusBadge = {
    draft: { color: 'draft', label: 'Rascunho' },
    pending: { color: 'pending', label: 'Pendente' },
    published: { color: 'completed', label: 'Publicado' },
    error: { color: 'cancelled', label: 'Erro' },
};

const columns = [
    {
        id: 'product',
        header: 'Produto',
        cell: ({ row }) => h('div', {}, [
            row.original.productId
                ? h(Link, { href: `/admin/produtos/${row.original.productId}/editar`, class: 'hover:text-primary hover:underline' }, () => row.original.productName)
                : h('span', {}, row.original.productName),
            row.original.sku ? h('div', { class: 'text-xs text-slate-400' }, row.original.sku) : null,
        ]),
    },
    {
        id: 'externalId',
        header: 'Anúncio no ML',
        cell: ({ row }) => row.original.externalUrl
            ? h('a', { href: row.original.externalUrl, target: '_blank', rel: 'noopener', class: 'text-primary hover:underline' }, row.original.externalId)
            : h('span', { class: 'text-slate-400' }, '—'),
    },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) => {
            const badge = statusBadge[row.original.status] ?? { color: row.original.status, label: row.original.status };
            return h(StatusBadge, { status: badge.color, label: badge.label });
        },
    },
    { accessorKey: 'price', header: 'Preço', cell: ({ row }) => formatPrice(row.original.price) },
    { accessorKey: 'stock', header: 'Estoque', cell: ({ row }) => row.original.stock ?? '—' },
    { accessorKey: 'lastSyncedAt', header: 'Última sincronização', cell: ({ row }) => formatDate(row.original.lastSyncedAt) },
];
</script>

<template>
    <Head title="Anúncios — Mercado Livre" />

    <AdminLayout>
        <SubNav />

        <div class="mb-6">
            <h1 class="mb-1 text-2xl font-bold">Anúncios — Mercado Livre</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ props.listings.length }} anúncio(s) vinculado(s) a produtos do catálogo.</p>
        </div>

        <DataTable :columns="columns" :data="props.listings" empty-message="Nenhum produto publicado no Mercado Livre ainda." />
    </AdminLayout>
</template>
