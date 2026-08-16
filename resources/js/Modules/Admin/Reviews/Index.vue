<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, router } from '@inertiajs/vue3';
import { h } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    reviews: {
        type: Array,
        default: () => [],
    },
});

const { can } = usePermissions();

const channelBadge = {
    mercado_livre: { color: 'pending', label: 'Mercado Livre' },
    shopee: { color: 'processing', label: 'Shopee' },
    tiktok_shop: { color: 'completed', label: 'TikTok Shop' },
    amazon: { color: 'shipped', label: 'Amazon' },
};

const originLabel = (review) => (review.channel ? (channelBadge[review.channel]?.label ?? review.channel) : 'Site');
const originColor = (review) => (review.channel ? (channelBadge[review.channel]?.color ?? review.channel) : 'active');

const destroy = async (review) => {
    if (await confirmDelete({ title: 'Remover esta avaliação?', text: 'Essa ação não pode ser desfeita.' })) {
        router.delete(`/admin/avaliacoes/${review.id}`, { preserveScroll: true });
    }
};

const formatDate = (value) => (value ? new Date(value).toLocaleDateString('pt-BR') : '—');

const columns = [
    {
        id: 'product',
        header: 'Produto',
        accessorFn: (row) => row.product?.name ?? '—',
    },
    {
        id: 'reviewer',
        header: 'Cliente',
        accessorFn: (row) => row.reviewer_display_name,
    },
    {
        id: 'rating',
        header: 'Nota',
        accessorFn: (row) => row.rating,
        cell: ({ row }) => h('div', { class: 'flex items-center gap-0.5 text-amber-400' }, [
            ...Array.from({ length: 5 }, (_, i) => h('i', {
                class: ['text-xs', i < row.original.rating ? 'fas fa-star' : 'far fa-star text-slate-300 dark:text-slate-600'],
            })),
        ]),
    },
    {
        id: 'comment',
        header: 'Comentário',
        accessorFn: (row) => row.comment ?? '',
        cell: ({ row }) => h('span', { class: 'line-clamp-1 max-w-xs text-slate-500' }, row.original.comment ?? '—'),
    },
    {
        id: 'images',
        header: 'Fotos',
        accessorFn: (row) => row.images_count ?? 0,
        cell: ({ row }) => (row.original.images_count ? `${row.original.images_count} foto(s)` : '—'),
    },
    {
        id: 'origin',
        header: 'Origem',
        accessorFn: (row) => originLabel(row),
        cell: ({ row }) => h(StatusBadge, { status: originColor(row.original), label: originLabel(row.original) }),
    },
    {
        id: 'created_at',
        header: 'Data',
        accessorFn: (row) => row.created_at,
        cell: ({ row }) => formatDate(row.original.created_at),
    },
    {
        id: 'actions',
        header: 'Ações',
        enableSorting: false,
        cell: ({ row }) => {
            const children = [
                h(ActionIcon, { icon: 'fa-eye', label: 'Ver', color: 'slate', href: `/admin/avaliacoes/${row.original.id}` }),
            ];
            if (can('cadastros.edit')) {
                children.push(h(ActionIcon, { icon: 'fa-pen', label: 'Editar', color: 'blue', href: `/admin/avaliacoes/${row.original.id}/editar` }));
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
    <Head title="Avaliações" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Avaliações</h1>
        <p class="mb-4 text-sm text-slate-500">
            Avaliações reais dos clientes no site e importadas automaticamente dos marketplaces conectados (nota, comentário, fotos e nome do comprador).
        </p>

        <DataTable
            :columns="columns"
            :data="props.reviews"
            search-placeholder="Buscar por produto ou cliente..."
            empty-message="Nenhuma avaliação ainda." />
    </AdminLayout>
</template>
