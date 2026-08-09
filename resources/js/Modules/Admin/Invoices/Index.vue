<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, router } from '@inertiajs/vue3';
import { computed, h, ref } from 'vue';

const props = defineProps({
    invoices: {
        type: Array,
        default: () => [],
    },
    summary: {
        type: Object,
        default: () => ({}),
    },
});

const { can } = usePermissions();

const syncing = ref(false);

const syncWithSefaz = () => {
    syncing.value = true;
    router.post('/admin/notas-fiscais/sincronizar', {}, {
        preserveScroll: true,
        onFinish: () => { syncing.value = false; },
    });
};

const formatPrice = (value) =>
    value === null || value === undefined
        ? '—'
        : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const formatDate = (value) => (value ? new Date(value).toLocaleDateString('pt-BR') : '—');

// Cor real de cada plataforma (mesma fonte da calculadora de precificação,
// Shared/marketplaceFees.js) — pedido explícito 2026-08-09.
const ORIGIN_STYLES = {
    loja: { label: 'Loja', color: '#1B3A5C' },
    mercado_livre: { label: 'Mercado Livre', color: '#2968C8' },
    shopee: { label: 'Shopee', color: '#EE4D2D' },
    amazon: { label: 'Amazon', color: '#FF9900' },
    tiktok_shop: { label: 'TikTok Shop', color: '#FE2C55' },
    shein: { label: 'Shein', color: '#000000' },
    nota_fiscal_avulsa: { label: 'Emissão manual', color: '#7C3AED' },
};

const hexToRgba = (hex, alpha) => {
    const value = hex.replace('#', '');
    const r = parseInt(value.substring(0, 2), 16);
    const g = parseInt(value.substring(2, 4), 16);
    const b = parseInt(value.substring(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
};

// Mesma paleta usada em Orders/Index.vue e Orders/Show.vue — reaproveitada
// aqui pra manter a mesma leitura visual de status em todo o admin.
const invoiceBadge = {
    pending: { color: 'pending', label: 'Pendente' },
    signed: { color: 'shipped', label: 'Assinada' },
    sent: { color: 'shipped', label: 'Enviada à SEFAZ' },
    authorized: { color: 'completed', label: 'Emitida' },
    rejected: { color: 'cancelled', label: 'Rejeitada' },
    denied: { color: 'cancelled', label: 'Denegada' },
    cancelled: { color: 'cancelled', label: 'Cancelada' },
    error: { color: 'cancelled', label: 'Erro' },
};

const tabs = [
    { label: 'Todas', value: 'all' },
    { label: 'Emitidas', value: 'authorized' },
    { label: 'Canceladas', value: 'cancelled' },
    { label: 'Pendentes', value: 'pending_group' },
    { label: 'Com problema', value: 'failed_group' },
];

const activeTab = ref('all');

const filteredInvoices = computed(() => {
    switch (activeTab.value) {
        case 'authorized':
            return props.invoices.filter((invoice) => invoice.status === 'authorized');
        case 'cancelled':
            return props.invoices.filter((invoice) => invoice.status === 'cancelled');
        case 'pending_group':
            return props.invoices.filter((invoice) => ['pending', 'signed', 'sent'].includes(invoice.status));
        case 'failed_group':
            return props.invoices.filter((invoice) => ['rejected', 'denied', 'error'].includes(invoice.status));
        default:
            return props.invoices;
    }
});

const columns = [
    {
        id: 'numero',
        header: 'Nota',
        accessorFn: (row) => `${row.numero}/${row.serie}`,
        cell: ({ row }) => h('span', { class: 'font-mono font-semibold' }, `${row.original.numero}/${row.original.serie}`),
    },
    {
        id: 'origin',
        header: 'Plataforma',
        accessorFn: (row) => ORIGIN_STYLES[row.order?.origin]?.label ?? row.order?.origin ?? 'Via SEFAZ',
        cell: ({ row }) => {
            const style = ORIGIN_STYLES[row.original.order?.origin] ?? { label: row.original.order?.origin ?? 'Via SEFAZ', color: '#64748B' };
            return h('span', {
                class: 'inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold',
                style: { color: style.color, background: hexToRgba(style.color, 0.12) },
            }, style.label);
        },
    },
    {
        id: 'external_order_id',
        header: 'Pedido na plataforma',
        accessorFn: (row) => row.order?.external_order_id ?? '—',
    },
    {
        accessorKey: 'valor_total',
        header: 'Valor',
        cell: ({ row }) => h('span', {
            class: 'font-semibold',
            style: { color: row.original.status === 'authorized' ? '#15803d' : row.original.status === 'cancelled' ? '#b91c1c' : undefined },
        }, formatPrice(row.original.valor_total)),
    },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) => {
            const badge = invoiceBadge[row.original.status] ?? { color: row.original.status, label: row.original.status };
            return h(StatusBadge, { status: badge.color, label: badge.label });
        },
    },
    { accessorKey: 'chave_acesso', header: 'Chave de acesso', cell: ({ row }) => h('span', { class: 'font-mono text-xs' }, row.original.chave_acesso ?? '—') },
    {
        id: 'autorizada_em',
        header: 'Emitida em',
        accessorFn: (row) => formatDate(row.autorizada_em),
    },
    {
        id: 'actions',
        header: 'Ações',
        cell: ({ row }) => h(ActionIcon, {
            icon: 'fa-eye',
            label: 'Visualizar nota',
            color: 'blue',
            href: `/admin/notas-fiscais/${row.original.id}`,
        }),
    },
];
</script>

<template>
    <Head title="Notas Fiscais" />

    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">Notas Fiscais</h1>
            <button
                v-if="can('pedidos.edit')"
                type="button"
                class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)] disabled:opacity-60"
                :disabled="syncing"
                @click="syncWithSefaz"
            >
                <i class="fas" :class="syncing ? 'fa-spinner fa-spin' : 'fa-cloud-arrow-down'"></i>
                {{ syncing ? 'Sincronizando...' : 'Sincronizar com SEFAZ' }}
            </button>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="flex items-center gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300">
                    <i class="fas fa-file-circle-check"></i>
                </span>
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-400">Notas emitidas</p>
                    <p class="mt-0.5 text-2xl font-bold">{{ summary.authorized_count ?? 0 }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300">
                    <i class="fas fa-sack-dollar"></i>
                </span>
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-400">Valor total emitido</p>
                    <p class="mt-0.5 text-2xl font-bold">{{ formatPrice(summary.authorized_total ?? 0) }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300">
                    <i class="fas fa-file-circle-xmark"></i>
                </span>
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-400">Notas canceladas</p>
                    <p class="mt-0.5 text-2xl font-bold">{{ summary.cancelled_count ?? 0 }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300">
                    <i class="fas fa-ban"></i>
                </span>
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-400">Valor total cancelado</p>
                    <p class="mt-0.5 text-2xl font-bold">{{ formatPrice(summary.cancelled_total ?? 0) }}</p>
                </div>
            </div>
        </div>

        <DataTable
            :columns="columns"
            :data="filteredInvoices"
            :filter-tabs="tabs"
            search-placeholder="Buscar por número, chave de acesso, pedido na plataforma..."
            empty-message="Nenhuma nota fiscal encontrada."
            :create-label="can('pedidos.edit') ? 'Emitir nota fiscal' : null"
            :create-href="can('pedidos.edit') ? '/admin/notas-fiscais/emitir' : null"
            @update:active-tab="activeTab = $event"
        />
    </AdminLayout>
</template>
