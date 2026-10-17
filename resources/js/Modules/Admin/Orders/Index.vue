<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { usePermissions } from '@/Shared/usePermissions';
import ServerPagination from '@/Shared/Components/ServerPagination.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, h, reactive } from 'vue';
import { statusLabel } from '@/Shared/statusLabels';

const { can } = usePermissions();

const props = defineProps({
    orders: {
        type: [Array, Object],
        default: () => ({ data: [] }),
    },
    // Opções do filtro "Marketplace" (fonte única: Order::MARKETPLACES).
    marketplaces: {
        type: Array,
        default: () => [],
    },
    statuses: {
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

// Pedido feito no site do FerraKora ou da Beauty Kora (2026-10-15): o canal
// continua "loja", o selo mostra de qual site veio.
const siteBadge = {
    ferrakora: { color: '#F27A2A', label: 'FerraKora' },
    beautykora: { color: '#D63384', label: 'Beauty Kora' },
};

// Cor do selo de tipo de envio (texto e slug vêm de TipoDeEnvio no backend).
// Flex e Mercado Envios com cores diferentes de propósito: no Mercado Livre
// é essa a diferença que importa na bancada.
const shippingTypeTone = {
    flex: 'yellow',
    full: 'purple',
    mercado_envios: 'blue',
    express_coleta: 'blue',
    retirada: 'green',
    correios: 'green',
    correios_pac: 'green',
    correios_sedex: 'green',
    proprio: 'gray',
    outro: 'gray',
    desconhecido: 'gray',
};

// Nome do selo só nesta listagem (pedido do Lira em 2026-10-16): Shopee e
// TikTok aparecem como "Express", não "Ponto Coleta". O KoraSync continua
// com o nome de TipoDeEnvio, que é o que a bancada usa.
const shippingTypeShortLabel = {
    express_coleta: 'Express',
};

const filterState = reactive({
    marketplace: props.filters.marketplace ?? '',
    status: props.filters.status ?? '',
    de: props.filters.de ?? '',
    ate: props.filters.ate ?? '',
    search: props.filters.search ?? '',
});
const orderRows = computed(() => Array.isArray(props.orders) ? props.orders : (props.orders?.data ?? []));

const applyFilters = () => {
    // Trocar filtro volta para a página 1 (o page não vai junto); a
    // paginação do servidor leva busca e filtros nos links.
    const params = Object.fromEntries(
        Object.entries(filterState)
            .map(([key, value]) => [key, value === null ? '' : String(value).trim()])
            .filter(([, value]) => value !== '')
    );

    router.get('/admin/pedidos', params, { preserveState: true, preserveScroll: true, replace: true });
};

const clearSearch = () => {
    filterState.search = '';
    applyFilters();
};

// O "x" nativo do campo type=search limpa sem passar pelo botão.
const onNativeSearch = () => {
    if (String(filterState.search ?? '').trim() === '') applyFilters();
};

const clearPeriod = () => {
    filterState.de = '';
    filterState.ate = '';
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
            const badge = siteBadge[row.original.site_origem]
                ?? channelBadge[row.original.origin]
                ?? { color: row.original.origin, label: row.original.origin };
            return h(StatusBadge, { status: badge.color, label: badge.label });
        },
    },
    { id: 'customer', header: 'Cliente', accessorFn: customerName },
    { accessorKey: 'items_count', header: 'Itens' },
    { accessorKey: 'total', header: 'Total', cell: ({ row }) => formatPrice(row.original.total) },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) => h(StatusBadge, { status: row.original.status, context: 'order' }),
    },
    // Nota e e-mail na mesma coluna, um selo embaixo do outro (2026-10-11):
    // em 1440px a tabela passava 42px da tela e cortava a coluna Ações.
    {
        id: 'invoice_email',
        header: 'Nota / e-mail',
        cell: ({ row }) => {
            const log = row.original.latest_email_log;
            const rotulo = (texto) => h('span', { class: 'w-10 shrink-0 text-[10px] font-semibold uppercase text-[var(--texto-suave)]' }, texto);
            return h('div', { class: 'flex flex-col items-start gap-1' }, [
                h('div', { class: 'flex items-center gap-1' }, [
                    rotulo('Nota'),
                    h(StatusBadge, { status: row.original.invoice?.status ?? null, context: 'invoice' }),
                ]),
                h('div', { class: 'flex items-center gap-1' }, [
                    rotulo('E-mail'),
                    h(StatusBadge, { status: log?.status ?? null, context: 'email' }),
                    log && log.status === 'sent' && !log.invoice_attached
                        ? h('span', { class: 'text-xs text-amber-600 dark:text-amber-400', title: 'Enviado sem a nota fiscal em anexo' }, 'sem anexo')
                        : null,
                ]),
            ]);
        },
    },
    {
        id: 'correios_qr',
        header: 'Envios',
        cell: ({ row }) => {
            const qr = row.original.latest_correios_pre_postagem;
            const tipo = row.original.shipping_type ?? 'desconhecido';
            const envio = h(StatusBadge, {
                status: shippingTypeTone[tipo] ?? 'gray',
                label: shippingTypeShortLabel[tipo] || row.original.shipping_type_short || '—',
                title: row.original.shipping_type_label || 'Envio não informado',
            });

            if (!qr) {
                // "Sem QR" só faz sentido onde a loja gera a pré-postagem dos Correios.
                return h('div', { class: 'flex flex-col items-start gap-1' }, [
                    envio,
                    tipo.startsWith('correios') ? h(StatusBadge, { status: null, context: 'correios' }) : null,
                ]);
            }

            return h('div', { class: 'flex flex-col items-start gap-1' }, [envio, h(Link, {
                href: `/admin/correios/${qr.id}`,
                class: 'group block text-xs hover:text-primary',
                title: 'Abrir QR Code dos Correios',
            }, () => [
                h(StatusBadge, { status: qr.status, context: 'correios' }),
                qr.codigo_objeto ? h('span', { class: 'block font-mono text-[11px] text-slate-400 group-hover:text-primary' }, qr.codigo_objeto) : null,
                qr.service_label || qr.postage_price != null
                    ? h('span', { class: 'block text-[11px] text-slate-400' }, [qr.service_label, formatPostagePrice(qr.postage_price)].filter(Boolean).join(' · '))
                    : null,
            ])]);
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
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-[200px_160px_300px_minmax(0,1fr)_auto_auto] lg:items-end">
                <div>
                    <label for="filtro-marketplace" class="text-xs font-medium text-slate-500">Marketplace</label>
                    <select id="filtro-marketplace" v-model="filterState.marketplace" name="marketplace" @change="applyFilters"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm focus:border-primary focus:outline-none">
                        <option value="">Todos</option>
                        <option v-for="option in props.marketplaces" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div>
                    <label for="filtro-status" class="text-xs font-medium text-slate-500">Status</label>
                    <select id="filtro-status" v-model="filterState.status" name="status" @change="applyFilters"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm focus:border-primary focus:outline-none">
                        <option value="">Todos</option>
                        <option v-for="status in props.statuses" :key="status" :value="status">
                            {{ statusLabel(status, 'order') }}
                        </option>
                    </select>
                </div>

                <div>
                    <span class="text-xs font-medium text-slate-500">Período</span>
                    <div class="mt-1 flex items-center gap-1">
                        <input v-model="filterState.de" type="date" name="de" aria-label="Data inicial" @change="applyFilters"
                            class="w-full min-w-0 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm focus:border-primary focus:outline-none">
                        <span class="text-xs text-slate-400">até</span>
                        <input v-model="filterState.ate" type="date" name="ate" aria-label="Data final" @change="applyFilters"
                            class="w-full min-w-0 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm focus:border-primary focus:outline-none">
                        <button v-if="filterState.de || filterState.ate" type="button" aria-label="Limpar período"
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs text-slate-400 hover:bg-[var(--surface-border)] hover:text-slate-600"
                            @click="clearPeriod">
                            <i class="fas fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <div>
                    <label for="busca-pedido" class="text-xs font-medium text-slate-500">Buscar pedido</label>
                    <div class="relative mt-1">
                        <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                        <input id="busca-pedido" v-model="filterState.search" type="search" autocomplete="off" @search="onNativeSearch"
                            placeholder="Pedido, marketplace, cliente, CPF, telefone, rastreio, NF-e…"
                            class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] py-2 pl-9 pr-10 text-sm focus:border-primary focus:outline-none">
                        <button v-if="filterState.search" type="button"
                            class="absolute right-2 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full text-xs text-slate-400 hover:bg-[var(--surface-border)] hover:text-slate-600"
                            aria-label="Limpar busca" @click="clearSearch">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>
                </div>

                <!-- Botão de buscar (pedido do Lira 2026-10-16); Enter no campo continua valendo. -->
                <button type="submit"
                    class="inline-flex h-[38px] items-center justify-center gap-2 rounded-lg border border-primary px-4 text-sm font-medium text-primary hover:bg-primary hover:text-white">
                    <i class="fas fa-magnifying-glass text-xs" aria-hidden="true"></i>
                    Buscar
                </button>

                <Link v-if="can('pedidos.edit')" href="/admin/pedidos/criar"
                    class="inline-flex h-[38px] items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-medium text-white hover:bg-primary-emphasis">
                    <i class="fas fa-plus text-xs"></i>
                    Novo pedido
                </Link>
            </div>

            <p class="mt-2 text-xs text-slate-400">Ex.: #274, 261016PWVAA8JX, 701-6533441-5387462, Rachel, 123.456.789-09, (11) 98765-4321, rastreio, número da NF-e, SKU ou 13/08/2026. Aperte Enter ou clique em Buscar.</p>
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
