<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, router } from '@inertiajs/vue3';
import { computed, h, ref } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    reviews: {
        type: Array,
        default: () => [],
    },
});

const { can } = usePermissions();

// Contadores por nota (pedido 2026-10-11). Clicar filtra a tabela; de novo, mostra todas.
const notaFiltro = ref(null);
const contagem = computed(() => {
    const porNota = { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 };
    props.reviews.forEach((review) => { if (porNota[review.rating] !== undefined) porNota[review.rating]++; });
    return porNota;
});
const media = computed(() => props.reviews.length
    ? (props.reviews.reduce((soma, review) => soma + Number(review.rating), 0) / props.reviews.length).toFixed(2).replace('.', ',')
    : '0');
const reviewsFiltradas = computed(() => (notaFiltro.value ? props.reviews.filter((review) => Number(review.rating) === notaFiltro.value) : props.reviews));
const filtrarNota = (nota) => { notaFiltro.value = notaFiltro.value === nota ? null : nota; };
const corNota = { 5: '#16a34a', 4: '#65a30d', 3: '#ca8a04', 2: '#ea580c', 1: '#dc2626' };

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

        <div class="mb-5 grid grid-cols-3 gap-3 sm:grid-cols-6">
            <!-- Card Total branco nos dois temas (Lira, 2026-10-14). -->
            <button type="button" class="card-branco rounded-xl border p-3 text-left transition"
                :class="notaFiltro === null ? 'ring-2 ring-slate-900 ring-offset-1 dark:ring-white' : 'hover:border-slate-400'"
                @click="notaFiltro = null">
                <span class="block text-xs font-semibold uppercase tracking-wide opacity-70">Total</span>
                <span class="block text-2xl font-bold">{{ props.reviews.length }}</span>
                <span class="block text-xs opacity-70">média {{ media }} <i class="fas fa-star text-amber-400"></i></span>
            </button>
            <button v-for="nota in [5, 4, 3, 2, 1]" :key="nota" type="button" class="rounded-xl border bg-[var(--surface)] p-3 text-left transition"
                :class="notaFiltro === nota ? 'ring-2 ring-offset-1' : 'hover:border-slate-400'"
                :style="{ borderColor: notaFiltro === nota ? corNota[nota] : 'var(--surface-border)', background: 'var(--surface)', '--tw-ring-color': corNota[nota] }"
                @click="filtrarNota(nota)">
                <span class="flex items-center gap-1 text-xs font-semibold" :style="{ color: corNota[nota] }">
                    {{ nota }} <i class="fas fa-star"></i>
                </span>
                <span class="block text-2xl font-bold">{{ contagem[nota] }}</span>
                <span class="block text-xs text-slate-500">{{ props.reviews.length ? Math.round(contagem[nota] / props.reviews.length * 100) : 0 }}% do total</span>
            </button>
        </div>

        <DataTable
            :columns="columns"
            :data="reviewsFiltradas"
            search-placeholder="Buscar por produto ou cliente..."
            empty-message="Nenhuma avaliação ainda." />
    </AdminLayout>
</template>
