<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { usePermissions } from '@/Shared/usePermissions';
import ServerPagination from '@/Shared/Components/ServerPagination.vue';
import InfoTooltip from '@/Shared/Components/InfoTooltip.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, h, reactive } from 'vue';
import { statusLabel } from '@/Shared/statusLabels';
import { shippingBadge } from './envio';

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
    loja: { color: 'shipped', label: 'KazaKora' },
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

const filterState = reactive({
    marketplace: props.filters.marketplace ?? '',
    status: props.filters.status ?? '',
    de: props.filters.de ?? '',
    ate: props.filters.ate ?? '',
    search: props.filters.search ?? '',
});
const orderRows = computed(() => Array.isArray(props.orders) ? props.orders : (props.orders?.data ?? []));
const totalPedidos = computed(() => {
    if (Array.isArray(props.orders)) return props.orders.length;
    const total = Number(props.orders?.total);
    return Number.isFinite(total) ? total : null;
});

// Texto do "i" ao lado do total. Tem que bater com OrderController::index:
// nonPurchaseReturn (tira só notas de devolução), sem filtro de status nem de
// período por padrão, created_at no período, latest() e paginate(50).
const explicacaoDaLista = [
    'Mostra os pedidos de todos os canais: KazaKora, FerraKora, Beauty Kora, Shopee, Mercado Livre, TikTok Shop, Amazon e notas fiscais avulsas.',
    'Entram todos os status, inclusive cancelados e estornados. Só ficam de fora as notas de devolução.',
    'Sem período escolhido, vale desde o primeiro pedido. O período usa a data da coluna Data.',
    'O total segue os filtros e a busca aplicados. Ordem do mais recente para o mais antigo, 50 por página.',
];

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
    // Envios: só o nome do meio de envio (pedido do Lira 2026-10-16). A
    // pré-postagem dos Correios (status, código, QR) fica no detalhe do pedido.
    {
        id: 'envio',
        header: 'Envios',
        cell: ({ row }) => {
            const selo = shippingBadge(row.original);
            return h(StatusBadge, {
                status: selo.tone,
                label: selo.label,
                wrap: true,
                title: row.original.shipping_type_label || 'Envio não informado',
            });
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

// Cartões no lugar da tabela abaixo de 1280px (Lira 2026-10-16): a tabela
// precisa de ~934px e, com o menu lateral, só cabe a partir de 1280px. Os
// selos de canal, status, nota/e-mail e envio do cartão usam as MESMAS
// funções de célula da tabela, para as duas vistas nunca divergirem.
const colunaPorId = Object.fromEntries(columns.map((coluna) => [coluna.id ?? coluna.accessorKey, coluna]));
const CelulaDoPedido = (p) => colunaPorId[p.coluna].cell({ row: { original: p.pedido } });
CelulaDoPedido.props = ['coluna', 'pedido'];

const formatDate = (value) => new Date(value).toLocaleDateString('pt-BR');
</script>

<template>
    <Head title="Pedidos" />

    <AdminLayout>
        <!-- O "+ pedido" morou no cabeçalho por um tempo; desde 16/10 (Lira)
             fica à direita da busca, na mesma linha. Ver o bloco "Buscar pedido". -->
        <div class="mb-4 flex items-center justify-between gap-3">
            <!-- Total ao lado do título com o "i" explicando o que entra na
                 lista (2026-10-16). O total vem do paginador, então segue os
                 filtros e a busca. -->
            <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                <h1 class="text-2xl font-bold">Pedidos</h1>
                <span v-if="totalPedidos !== null" class="inline-flex items-center gap-1.5 text-sm text-slate-500">
                    <span><span class="font-semibold tabular-nums text-[var(--foreground)]">{{ totalPedidos.toLocaleString('pt-BR') }}</span> {{ totalPedidos === 1 ? 'pedido' : 'pedidos' }}</span>
                    <InfoTooltip label="O que esta lista mostra" :paragraphs="explicacaoDaLista" />
                </span>
            </div>
        </div>

        <form class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" @submit.prevent="applyFilters">
            <!-- Filtros numa linha que quebra (flex-wrap), não mais em grid
                 (2026-10-16, Lira de novo: busca grande demais e rolagem lateral).
                 O grid de uma coluna no celular media a coluna pelo texto mais
                 largo dos selects e passava da tela (381px numa tela de 360px),
                 fazendo a página inteira rolar de lado. Agora cada campo tem
                 min-w-0 e largura própria; a busca cresce até max-w-md e desce
                 para a linha de baixo quando não cabe. -->
            <div class="flex min-w-0 flex-wrap items-end gap-3">
                <div class="w-full min-w-0 sm:w-44">
                    <label for="filtro-marketplace" class="text-xs font-medium text-slate-500">Marketplace</label>
                    <select id="filtro-marketplace" v-model="filterState.marketplace" name="marketplace" @change="applyFilters"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm focus:border-primary focus:outline-none">
                        <option value="">Todos</option>
                        <option v-for="option in props.marketplaces" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div class="w-full min-w-0 sm:w-40">
                    <label for="filtro-status" class="text-xs font-medium text-slate-500">Status</label>
                    <select id="filtro-status" v-model="filterState.status" name="status" @change="applyFilters"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm focus:border-primary focus:outline-none">
                        <option value="">Todos</option>
                        <option v-for="status in props.statuses" :key="status" :value="status">
                            {{ statusLabel(status, 'order') }}
                        </option>
                    </select>
                </div>

                <div class="w-full min-w-0 sm:w-[19rem]">
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

                <div class="w-full min-w-0 sm:w-auto sm:min-w-[16rem] sm:max-w-xl sm:flex-1">
                    <label for="busca-pedido" class="text-xs font-medium text-slate-500">Buscar pedido</label>
                    <!-- "+ pedido" à direita da busca, na mesma linha (Lira 2026-10-16),
                         com o ícone de mais dentro do círculo. No celular continua ao
                         lado: o campo encolhe (min-w-0), o botão não (shrink-0). -->
                        <div class="mt-1 flex min-w-0 items-center gap-2">
                        <!-- Campo e botão colados, como o input-group do Bootstrap (pedido do
                             Lira 2026-10-16): canto arredondado só nas pontas de fora. Enter no
                             campo continua valendo.
                             Foco do grupo (Lira 2026-10-16): o anel de foco fica em volta
                             do conjunto campo + botão (focus-within), no mesmo estilo do
                             foco global do admin (2px var(--foco), afastado 2px). O anel
                             isolado do campo e do botão sai (com !, porque a regra global
                             em app.css não está em camada e ganharia do utilitário). -->
                        <div class="flex min-w-0 flex-1 rounded-lg focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-[var(--foco)] focus-within:outline-solid">
                            <div class="relative min-w-0 flex-1">
                                <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                                <input id="busca-pedido" v-model="filterState.search" type="search" autocomplete="off" @search="onNativeSearch"
                                    placeholder="Pedido, marketplace, cliente, CPF, telefone, rastreio, NF-e…"
                                    class="relative w-full rounded-l-lg rounded-r-none border border-[var(--surface-border)] bg-[var(--surface-muted)] py-2 pl-9 pr-10 text-sm focus:z-10 focus:border-primary focus:outline-none!">
                                <button v-if="filterState.search" type="button"
                                    class="absolute right-2 top-1/2 z-20 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full text-xs text-slate-400 hover:bg-[var(--surface-border)] hover:text-slate-600"
                                    aria-label="Limpar busca" @click="clearSearch">
                                    <i class="fas fa-xmark"></i>
                                </button>
                            </div>
                            <button type="submit"
                                class="-ml-px inline-flex h-[38px] shrink-0 items-center justify-center gap-2 rounded-l-none rounded-r-lg border border-primary bg-primary px-4 text-sm font-medium text-white hover:bg-primary-emphasis focus-visible:outline-none!">
                                <i class="fas fa-magnifying-glass text-xs" aria-hidden="true"></i>
                                Buscar
                            </button>
                        </div>
                        <Link v-if="can('pedidos.edit')" href="/admin/pedidos/criar"
                            aria-label="Novo pedido" title="Novo pedido"
                            class="inline-flex h-[38px] shrink-0 items-center justify-center gap-1.5 rounded-lg bg-primary px-3 text-sm font-medium text-white hover:bg-primary-emphasis focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2">
                            <i class="fas fa-circle-plus" aria-hidden="true"></i>
                            pedido
                        </Link>
                    </div>
                </div>
            </div>

            <p class="mt-2 text-xs text-slate-400">Ex.: #274, 261016PWVAA8JX, 701-6533441-5387462, Rachel, 123.456.789-09, (11) 98765-4321, rastreio, número da NF-e, SKU ou 13/08/2026. Aperte Enter ou clique em Buscar.</p>
        </form>

        <!-- Celular, tablet e notebook pequeno (< 1280px): um cartão por pedido,
             sem rolagem lateral. Número e status no topo, cliente e valor em
             destaque, nota/e-mail e envio embaixo, botão Ver pedido inteiro. -->
        <div class="xl:hidden">
            <div v-if="orderRows.length === 0"
                class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-10 text-center text-sm text-slate-400 shadow-sm">
                <i class="fas fa-inbox mb-2 block text-2xl" aria-hidden="true"></i>
                Nenhum pedido encontrado.
            </div>
            <ul v-else class="grid list-none gap-3 lg:grid-cols-2">
                <li v-for="order in orderRows" :key="order.id"
                    class="flex min-w-0 flex-col rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <Link :href="`/admin/pedidos/${order.id}`" class="text-base font-bold hover:text-primary hover:underline">#{{ order.id }}</Link>
                            <div v-if="order.external_order_id" class="truncate text-xs text-slate-400" :title="order.external_order_id">{{ order.external_order_id }}</div>
                        </div>
                        <div class="shrink-0">
                            <CelulaDoPedido coluna="status" :pedido="order" />
                        </div>
                    </div>

                    <div class="mt-3 flex items-end justify-between gap-3">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-semibold" :title="customerName(order)">{{ customerName(order) }}</div>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-400">
                                <CelulaDoPedido coluna="origin" :pedido="order" />
                                <span>{{ formatDate(order.created_at) }}</span>
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="text-lg font-bold tabular-nums">{{ formatPrice(order.total) }}</div>
                            <div class="text-xs text-slate-400">{{ Number(order.items_count ?? 0) }} {{ Number(order.items_count ?? 0) === 1 ? 'item' : 'itens' }}</div>
                        </div>
                    </div>

                    <div class="mt-3 grid gap-3 border-t border-[var(--surface-border)] pt-3 sm:grid-cols-2">
                        <div class="min-w-0">
                            <div class="mb-1 text-[10px] font-semibold uppercase text-[var(--texto-suave)]">Nota / e-mail</div>
                            <CelulaDoPedido coluna="invoice_email" :pedido="order" />
                        </div>
                        <div class="min-w-0">
                            <div class="mb-1 text-[10px] font-semibold uppercase text-[var(--texto-suave)]">Envios</div>
                            <CelulaDoPedido coluna="envio" :pedido="order" />
                        </div>
                    </div>

                    <div class="mt-auto pt-4">
                        <Link :href="`/admin/pedidos/${order.id}`"
                            class="flex h-11 w-full items-center justify-center gap-2 rounded-lg border border-[var(--surface-border)] text-sm font-medium text-primary hover:bg-[var(--surface-muted)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50">
                            <i class="fas fa-eye text-xs" aria-hidden="true"></i>
                            Ver pedido
                        </Link>
                    </div>
                </li>
            </ul>
        </div>

        <!-- A partir de 1280px: a tabela de sempre. -->
        <DataTable
            class="hidden xl:block"
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
