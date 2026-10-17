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

// Selo de envio só nesta listagem (pedido do Lira em 2026-10-16, ajustado
// no mesmo dia): o nome segue o canal, não o nome cru da transportadora.
//   site (KazaKora, FerraKora, Beauty Kora) -> Correios (o serviço PAC/SEDEX
//     continua embaixo, na pré-postagem);
//   Mercado Livre -> Flex ou Mercado Envios (em duas linhas); Full fica Full;
//   Shopee -> Express (Retirada pelo comprador continua Retirada: esse pacote
//     não vai para a transportadora);
//   TikTok -> Ponto de Coleta.
// Amazon e o resto ficam como vêm de TipoDeEnvio. O KoraSync não muda: ele
// usa o nome de TipoDeEnvio, que é o que a bancada usa.
const shippingBadge = (order) => {
    const tipo = order.shipping_type ?? 'desconhecido';
    const padrao = { tone: shippingTypeTone[tipo] ?? 'gray', label: order.shipping_type_short || '—' };

    switch (order.origin) {
        case 'loja':
            return { tone: 'green', label: 'Correios' };
        case 'mercado_livre':
            if (tipo === 'flex') return { tone: shippingTypeTone.flex, label: 'Flex' };
            if (tipo === 'mercado_envios') return { tone: shippingTypeTone.mercado_envios, label: 'Mercado\nEnvios' };
            return padrao;
        case 'shopee':
            return tipo === 'retirada' ? padrao : { tone: shippingTypeTone.express_coleta, label: 'Express' };
        case 'tiktok_shop':
            return { tone: shippingTypeTone.express_coleta, label: 'Ponto de\nColeta' };
        default:
            return padrao;
    }
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
    // Cliente é a coluna elástica (2026-10-16): fica com a sobra da largura e
    // corta o nome com reticências (nome inteiro no title). Assim a tabela
    // cabe em 1366px sem rolagem lateral e o Status não precisa quebrar.
    {
        id: 'customer',
        header: 'Cliente',
        accessorFn: customerName,
        meta: { cellClass: 'w-full max-w-0 min-w-[7rem]' },
        cell: ({ row }) => {
            const nome = customerName(row.original);
            return h('div', { class: 'truncate', title: nome }, nome);
        },
    },
    // Quantidade de itens embaixo do total (era uma coluna "Itens" só com o
    // número): libera largura para o Status ficar numa linha.
    {
        accessorKey: 'total',
        header: 'Total',
        meta: { cellClass: 'whitespace-nowrap' },
        cell: ({ row }) => {
            const itens = Number(row.original.items_count ?? 0);
            return h('div', {}, [
                h('div', { class: 'tabular-nums' }, formatPrice(row.original.total)),
                h('div', { class: 'text-xs text-slate-400' }, `${itens} ${itens === 1 ? 'item' : 'itens'}`),
            ]);
        },
    },
    // Status sempre numa linha só (Lira 2026-10-16: "Aguardando pagamento" em
    // duas linhas ficou feio). O espaço vem da tabela compacta (dense) e do
    // nome do cliente cortado, não de quebrar o selo. Desde então o
    // awaiting_payment aparece como "Pendente" (contexto order no statusLabels).
    {
        accessorKey: 'status',
        header: 'Status',
        meta: { cellClass: 'whitespace-nowrap' },
        cell: ({ row }) => h(StatusBadge, {
            status: row.original.status,
            context: 'order',
        }),
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
            const selo = shippingBadge(row.original);
            const envio = h(StatusBadge, {
                status: selo.tone,
                label: selo.label,
                wrap: true,
                title: row.original.shipping_type_label || 'Envio não informado',
            });

            if (!qr) {
                // "Sem QR" só faz sentido onde a loja gera a pré-postagem dos Correios.
                return h('div', { class: 'flex flex-col items-start gap-1' }, [
                    envio,
                    tipo.startsWith('correios') || row.original.origin === 'loja' ? h(StatusBadge, { status: null, context: 'correios' }) : null,
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
        meta: { cellClass: 'whitespace-nowrap' },
        cell: ({ row }) => new Date(row.original.created_at).toLocaleDateString('pt-BR'),
    },
    {
        id: 'actions',
        header: 'Ações',
        enableSorting: false,
        meta: { cellClass: 'w-px' },
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
        <!-- "Novo pedido" saiu do card de filtros para o cabeçalho, ao lado do
             título (2026-10-16): os filtros ficam só com filtro e a busca ganha
             a linha inteira em telas médias. -->
        <div class="mb-4 flex items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">Pedidos</h1>
            <Link v-if="can('pedidos.edit')" href="/admin/pedidos/criar"
                class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-primary px-3.5 text-sm font-medium text-white hover:bg-primary-emphasis focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2">
                <i class="fas fa-plus text-xs" aria-hidden="true"></i>
                Novo pedido
            </Link>
        </div>

        <form class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" @submit.prevent="applyFilters">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-[200px_160px_300px_minmax(0,1fr)] lg:items-end">
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

                <div class="sm:col-span-2 lg:col-span-3 2xl:col-span-1">
                    <label for="busca-pedido" class="text-xs font-medium text-slate-500">Buscar pedido</label>
                    <!-- Campo e botão colados, como o input-group do Bootstrap (pedido do
                         Lira 2026-10-16): canto arredondado só nas pontas de fora. Enter no
                         campo continua valendo. -->
                    <div class="mt-1 flex">
                        <div class="relative min-w-0 flex-1">
                            <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                            <input id="busca-pedido" v-model="filterState.search" type="search" autocomplete="off" @search="onNativeSearch"
                                placeholder="Pedido, marketplace, cliente, CPF, telefone, rastreio, NF-e…"
                                class="relative w-full rounded-l-lg rounded-r-none border border-[var(--surface-border)] bg-[var(--surface-muted)] py-2 pl-9 pr-10 text-sm focus:z-10 focus:border-primary focus:outline-none">
                            <button v-if="filterState.search" type="button"
                                class="absolute right-2 top-1/2 z-20 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full text-xs text-slate-400 hover:bg-[var(--surface-border)] hover:text-slate-600"
                                aria-label="Limpar busca" @click="clearSearch">
                                <i class="fas fa-xmark"></i>
                            </button>
                        </div>
                        <button type="submit"
                            class="-ml-px inline-flex h-[38px] shrink-0 items-center justify-center gap-2 rounded-l-none rounded-r-lg border border-primary bg-primary px-4 text-sm font-medium text-white hover:bg-primary-emphasis">
                            <i class="fas fa-magnifying-glass text-xs" aria-hidden="true"></i>
                            Buscar
                        </button>
                    </div>
                </div>
            </div>

            <p class="mt-2 text-xs text-slate-400">Ex.: #274, 261016PWVAA8JX, 701-6533441-5387462, Rachel, 123.456.789-09, (11) 98765-4321, rastreio, número da NF-e, SKU ou 13/08/2026. Aperte Enter ou clique em Buscar.</p>
        </form>

        <DataTable
            dense
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
