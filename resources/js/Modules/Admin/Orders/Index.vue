<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { usePermissions } from '@/Shared/usePermissions';
import ServerPagination from '@/Shared/Components/ServerPagination.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, h, reactive } from 'vue';

const { can } = usePermissions();

const props = defineProps({
    orders: {
        type: [Array, Object],
        default: () => ({ data: [] }),
    },
    channels: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const channelBadge = {
    loja: { color: 'shipped', label: 'Site' },
    mercado_livre: { color: 'pending', label: 'Mercado Livre' },
    shopee: { color: 'processing', label: 'Shopee' },
    tiktok_shop: { color: 'completed', label: 'TikTok Shop' },
    amazon: { color: '#146EB4', label: 'Amazon' },
};

const filterState = reactive({
    origin: props.filters.origin ?? '',
    search: props.filters.search ?? '',
});
const orderRows = computed(() => Array.isArray(props.orders) ? props.orders : (props.orders?.data ?? []));

const applyFilters = () => {
    const params = Object.fromEntries(
        Object.entries(filterState).filter(([, value]) => value !== null && String(value).trim() !== '')
    );

    router.get('/admin/pedidos', params, { preserveState: true, replace: true });
};

const clearSearch = () => {
    filterState.search = '';
    applyFilters();
};

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const customerName = (order) =>
    order.user?.name
    || order.shipping_name
    || order.shipping_email
    || order.shipping_phone
    || '—';

const statusLabels = {
    pending: 'Pendente',
    paid: 'Pago',
    shipped: 'Enviado',
    completed: 'Concluído',
    cancelled: 'Cancelado',
};

// StatusBadge escolhe a cor pelo valor de "status" — reaproveitamos as
// cores já definidas lá (não são específicas de pedido) pra nota fiscal e
// e-mail em vez de duplicar uma paleta nova.
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

const emailBadge = {
    sent: { color: 'completed', label: 'Enviado' },
    failed: { color: 'cancelled', label: 'Falhou' },
};

const correiosStatusLabel = {
    gerada: 'QR gerado',
    erro: 'Falhou',
};

const formatPostagePrice = (value) => value == null
    ? null
    : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const columns = [
    {
        accessorKey: 'id',
        header: 'Pedido',
        cell: ({ row }) => h('div', {}, [
            h(Link, { href: `/admin/pedidos/${row.original.id}`, class: 'hover:text-primary hover:underline' }, () => `#${row.original.id}`),
            row.original.external_order_id
                ? h('div', { class: 'text-xs text-slate-400' }, row.original.external_order_id)
                : null,
        ]),
    },
    {
        id: 'origin',
        header: 'Canal',
        cell: ({ row }) => {
            const badge = channelBadge[row.original.origin] ?? { color: row.original.origin, label: row.original.origin };
            return h(StatusBadge, { status: badge.color, label: badge.label });
        },
    },
    { id: 'customer', header: 'Cliente', accessorFn: customerName },
    { accessorKey: 'items_count', header: 'Itens' },
    { accessorKey: 'total', header: 'Total', cell: ({ row }) => formatPrice(row.original.total) },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) => h(StatusBadge, { status: row.original.status, label: statusLabels[row.original.status] ?? row.original.status }),
    },
    {
        id: 'invoice_status',
        header: 'Nota Fiscal',
        cell: ({ row }) => {
            const status = row.original.invoice?.status;
            if (!status) return h('span', { class: 'text-xs text-slate-400' }, '—');
            const badge = invoiceBadge[status] ?? { color: status, label: status };
            return h(StatusBadge, { status: badge.color, label: badge.label });
        },
    },
    {
        id: 'email_status',
        header: 'E-mail',
        cell: ({ row }) => {
            const log = row.original.latest_email_log;
            if (!log) return h('span', { class: 'text-xs text-slate-400' }, '—');
            const badge = emailBadge[log.status] ?? { color: log.status, label: log.status };
            return h('div', { class: 'flex items-center gap-1.5' }, [
                h(StatusBadge, { status: badge.color, label: badge.label }),
                log.status === 'sent' && !log.invoice_attached
                    ? h('span', { class: 'text-xs text-amber-600 dark:text-amber-400', title: 'Enviado sem a nota fiscal em anexo' }, 'sem anexo')
                    : null,
            ]);
        },
    },
    {
        id: 'correios_qr',
        header: 'Correios',
        cell: ({ row }) => {
            const qr = row.original.latest_correios_pre_postagem;

            if (!qr) {
                return h('span', { class: 'text-xs text-slate-400' }, row.original.origin === 'amazon' ? 'Sem QR' : '—');
            }

            return h(Link, {
                href: `/admin/correios/${qr.id}`,
                class: 'group block text-xs hover:text-primary',
                title: 'Abrir QR Code dos Correios',
            }, () => [
                h('span', { class: qr.status === 'gerada' ? 'font-semibold text-emerald-600 dark:text-emerald-400' : 'font-semibold text-red-600 dark:text-red-400' }, correiosStatusLabel[qr.status] ?? qr.status),
                qr.codigo_objeto ? h('span', { class: 'block font-mono text-[11px] text-slate-400 group-hover:text-primary' }, qr.codigo_objeto) : null,
                qr.service_label || qr.postage_price != null
                    ? h('span', { class: 'block text-[11px] text-slate-400' }, [qr.service_label, formatPostagePrice(qr.postage_price)].filter(Boolean).join(' · '))
                    : null,
            ]);
        },
    },
    {
        accessorKey: 'created_at',
        header: 'Data',
        cell: ({ row }) => new Date(row.original.created_at).toLocaleDateString('pt-BR'),
    },
    {
        id: 'actions',
        header: 'Ações',
        enableSorting: false,
        cell: ({ row }) => h('div', { class: 'flex justify-end' }, h(ActionIcon, {
            icon: 'fa-eye',
            label: 'Ver pedido',
            color: 'blue',
            href: `/admin/pedidos/${row.original.id}`,
        })),
    },
];
</script>

<template>
    <Head title="Pedidos" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Pedidos</h1>

        <form class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" @submit.prevent="applyFilters">
            <div class="grid gap-3 lg:grid-cols-[220px_minmax(0,1fr)_auto] lg:items-end">
                <div>
                    <label class="text-xs font-medium text-slate-500">Marketplace</label>
                    <select v-model="filterState.origin" @change="applyFilters"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm focus:border-primary focus:outline-none">
                        <option value="">Todos os marketplaces</option>
                        <option v-for="channel in props.channels" :key="channel" :value="channel">
                            {{ channelBadge[channel]?.label ?? channel }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="text-xs font-medium text-slate-500">Buscar pedido</label>
                    <div class="relative mt-1">
                        <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                        <input v-model="filterState.search" type="search" placeholder="ID, número externo, cliente ou data"
                            class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] py-2 pl-9 pr-10 text-sm focus:border-primary focus:outline-none">
                        <button v-if="filterState.search" type="button"
                            class="absolute right-2 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full text-xs text-slate-400 hover:bg-[var(--surface-border)] hover:text-slate-600"
                            aria-label="Limpar busca" @click="clearSearch">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>
                </div>

                <Link v-if="can('pedidos.edit')" href="/admin/pedidos/criar"
                    class="inline-flex h-[38px] items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-medium text-white hover:bg-primary-emphasis">
                    <i class="fas fa-plus text-xs"></i>
                    Novo pedido
                </Link>
            </div>

            <p class="mt-2 text-xs text-slate-400">Ex.: #274, 701-6533441-5387462, Rachel ou 13/08/2026. Aperte Enter para buscar.</p>
        </form>

        <DataTable
            :columns="columns"
            :data="orderRows"
            search-placeholder="Filtrar resultados desta página..."
            :hide-search="true"
            empty-message="Nenhum pedido encontrado."
            :create-label="null"
            :create-href="null"
            :show-pagination="false"
        />

        <ServerPagination v-if="!Array.isArray(props.orders)" :paginator="props.orders" />
    </AdminLayout>
</template>
