<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link, router } from '@inertiajs/vue3';
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

const originLabels = {
    loja: 'Loja',
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
        cell: ({ row }) => h(Link, { href: `/admin/notas-fiscais/${row.original.id}`, class: 'font-medium hover:text-primary hover:underline' }, () => `${row.original.numero}/${row.original.serie}`),
    },
    {
        id: 'order_id',
        header: 'Pedido',
        accessorKey: 'order_id',
        cell: ({ row }) => row.original.order_id
            ? h(Link, { href: `/admin/pedidos/${row.original.order_id}`, class: 'hover:text-primary hover:underline' }, () => `#${row.original.order_id}`)
            : h('span', { class: 'text-xs italic text-slate-400' }, 'via SEFAZ'),
    },
    {
        id: 'customer',
        header: 'Cliente',
        accessorFn: (row) => row.order?.user?.name ?? row.destinatario_nome ?? '—',
    },
    {
        id: 'origin',
        header: 'Plataforma',
        accessorFn: (row) => originLabels[row.order?.origin] ?? row.order?.origin ?? '—',
    },
    {
        id: 'external_order_id',
        header: 'Pedido na plataforma',
        accessorFn: (row) => row.order?.external_order_id ?? '—',
    },
    {
        accessorKey: 'valor_total',
        header: 'Valor',
        cell: ({ row }) => formatPrice(row.original.valor_total),
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
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-400">Notas emitidas</p>
                <p class="mt-1 text-2xl font-bold">{{ summary.authorized_count ?? 0 }}</p>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-400">Valor total emitido</p>
                <p class="mt-1 text-2xl font-bold">{{ formatPrice(summary.authorized_total ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-400">Notas canceladas</p>
                <p class="mt-1 text-2xl font-bold">{{ summary.cancelled_count ?? 0 }}</p>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-400">Valor total cancelado</p>
                <p class="mt-1 text-2xl font-bold">{{ formatPrice(summary.cancelled_total ?? 0) }}</p>
            </div>
        </div>

        <DataTable
            :columns="columns"
            :data="filteredInvoices"
            :filter-tabs="tabs"
            search-placeholder="Buscar por pedido, cliente, chave de acesso..."
            empty-message="Nenhuma nota fiscal encontrada."
            :create-label="can('pedidos.edit') ? 'Emitir nota fiscal' : null"
            :create-href="can('pedidos.edit') ? '/admin/notas-fiscais/emitir' : null"
            @update:active-tab="activeTab = $event"
        />
    </AdminLayout>
</template>
