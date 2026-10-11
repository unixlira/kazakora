<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { useServerTable, toTableSort } from '@/Shared/useServerTable';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, h, ref } from 'vue';
import { confirmDelete } from '@/Shared/notify';

// Lançamentos e vendas chegam paginados do servidor (~50 por página cada,
// paginators do Laravel) — busca, ordenação, período e plataforma são query
// param tratados em CashFlowController@index. Os totais das vendas também
// vêm prontos de lá (salesTotals), somados sobre o conjunto filtrado todo.
const props = defineProps({
    entries: { type: Object, default: () => ({ data: [] }) },
    costCenters: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
    sales: { type: Object, default: () => ({ data: [] }) },
    salesTotals: { type: Object, default: () => ({ cost: 0, fee: 0, shipping: 0, netProfit: 0 }) },
    salesPlatforms: { type: Array, default: () => [] },
    salesFilter: { type: Object, required: true },
    entriesFilter: { type: Object, default: () => ({}) },
});

// Um único conjunto de query params pras duas tabelas — mudar o filtro de
// uma não pode apagar o da outra. Página não entra aqui de propósito:
// qualquer filtro novo volta as duas pra primeira página.
const { visit, sortParams } = useServerTable('/admin/fluxo-de-caixa', {
    start: props.salesFilter.start ?? null,
    end: props.salesFilter.end ?? null,
    platform: props.salesFilter.platform ?? null,
    search: props.salesFilter.search ?? null,
    sort: props.salesFilter.sort ?? null,
    direction: props.salesFilter.sort ? props.salesFilter.direction : null,
    entries_search: props.entriesFilter.search ?? null,
    entries_sort: props.entriesFilter.sort ?? null,
    entries_direction: props.entriesFilter.sort ? props.entriesFilter.direction : null,
});

const entryRows = computed(() => props.entries?.data ?? []);
const salesRows = computed(() => props.sales?.data ?? []);

const { can } = usePermissions();
const showForm = ref(false);

const form = useForm({
    type: 'income',
    description: '',
    amount: 0,
    cost_center_id: '',
    entry_date: new Date().toISOString().slice(0, 10),
});

const submit = () => {
    form.post('/admin/fluxo-de-caixa', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showForm.value = false;
        },
    });
};

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const salesRangeStart = ref(props.salesFilter.start ?? '');
const salesRangeEnd = ref(props.salesFilter.end ?? '');

const applySalesRange = () => {
    visit({ start: salesRangeStart.value || null, end: salesRangeEnd.value || null });
};

const toDateInput = (date) => date.toISOString().slice(0, 10);

const applyPreset = (preset) => {
    const now = new Date();

    if (preset === 'all') {
        salesRangeStart.value = '';
        salesRangeEnd.value = '';
        applySalesRange();
        return;
    }

    let start;
    let end;

    if (preset === 'thisMonth') {
        start = new Date(now.getFullYear(), now.getMonth(), 1);
        end = now;
    } else if (preset === 'lastMonth') {
        start = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        end = new Date(now.getFullYear(), now.getMonth(), 0);
    } else {
        start = new Date(now.getFullYear(), 0, 1);
        end = now;
    }

    salesRangeStart.value = toDateInput(start);
    salesRangeEnd.value = toDateInput(end);
    applySalesRange();
};

// Filtro por plataforma — pedido explícito 2026-08-14. O valor é o origin
// do pedido (salesPlatforms vem do servidor com o rótulo de cada um).
const platformFilter = ref(props.salesFilter.platform ?? '');
const applyPlatform = () => visit({ platform: platformFilter.value || null });

// Comissão editável direto na tabela — pedido explícito 2026-08-14: "editavel
// na propria tabela o valor de comissao ... clicar no enter ai ele atualiza".
// Grava (CashFlowController::updateSaleFee) e deixa o Inertia recarregar
// 'sales' já recalculado no servidor (rateio entre itens do mesmo pedido,
// has_fee_data, lucro líquido) — não tenta recalcular isso no front.
const savingFeeOrderId = ref(null);

const saveFee = (orderId, rawValue) => {
    const feeAmount = parseFloat(String(rawValue).replace(',', '.'));
    if (Number.isNaN(feeAmount) || feeAmount < 0) return;

    savingFeeOrderId.value = orderId;
    router.put(`/admin/fluxo-de-caixa/vendas/${orderId}/comissao`, { fee_amount: feeAmount }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { savingFeeOrderId.value = null; },
    });
};

// Pago ao fornecedor editável direto na tabela — mesmo pedido explícito
// 2026-08-14. O valor digitado é o custo total daquela linha (já
// ×quantidade); o backend grava de volta como custo unitário no produto
// (updateItemCost), então passa a valer pra qualquer venda futura dele
// também — igual a editar o custo em /admin/produtos.
const savingCostItemId = ref(null);

const saveCost = (itemId, rawValue) => {
    const productCost = parseFloat(String(rawValue).replace(',', '.'));
    if (Number.isNaN(productCost) || productCost < 0) return;

    savingCostItemId.value = itemId;
    router.put(`/admin/fluxo-de-caixa/vendas/item/${itemId}/custo`, { product_cost: productCost }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { savingCostItemId.value = null; },
    });
};

const destroy = async (entry) => {
    if (await confirmDelete({ title: `Remover o lançamento "${entry.description}"?` })) {
        router.delete(`/admin/fluxo-de-caixa/${entry.id}`);
    }
};

const columns = [
    { accessorKey: 'entry_date', header: 'Data', cell: ({ row }) => new Date(`${row.original.entry_date}T00:00:00`).toLocaleDateString('pt-BR') },
    { accessorKey: 'description', header: 'Descrição' },
    { id: 'costCenter', header: 'Centro de custo', accessorFn: (row) => row.cost_center?.name ?? '—' },
    {
        accessorKey: 'type',
        header: 'Tipo',
        cell: ({ row }) => h(StatusBadge, { status: row.original.type === 'income' ? 'active' : 'cancelled', label: row.original.type === 'income' ? 'Entrada' : 'Saída' }),
    },
    {
        accessorKey: 'amount',
        header: 'Valor',
        cell: ({ row }) => h('span', { class: row.original.type === 'income' ? 'text-success font-medium' : 'text-error font-medium' }, formatPrice(row.original.amount)),
    },
    {
        id: 'actions',
        header: 'Ações',
        enableSorting: false,
        cell: ({ row }) => (can('financeiro.delete')
            ? h('div', { class: 'flex justify-end' }, h(ActionIcon, { icon: 'fa-trash', label: 'Remover', color: 'red', onClick: () => destroy(row.original) }))
            : null),
    },
];

const salesColumns = [
    { accessorKey: 'date', header: 'Data pagamento', cell: ({ row }) => new Date(`${row.original.date}T00:00:00`).toLocaleDateString('pt-BR') },
    {
        accessorKey: 'product_name',
        header: 'Produto',
        cell: ({ row }) => h('div', {}, [
            h('span', {}, row.original.product_name),
            !row.original.has_cost
                ? h('span', { class: 'ml-2 text-xs text-amber-500', title: 'Produto sem custo cadastrado — margem acima do real' }, '⚠ sem custo')
                : null,
        ]),
    },
    { accessorKey: 'platform', header: 'Plataforma' },
    {
        accessorKey: 'product_cost',
        header: 'Pago ao fornecedor',
        cell: ({ row }) => h('input', {
            type: 'number',
            step: '0.01',
            min: '0',
            class: `w-24 rounded-lg border px-2 py-1 text-sm ${row.original.has_cost ? 'border-[var(--surface-border)] text-slate-500' : 'border-amber-400 text-amber-500'}`,
            value: row.original.product_cost,
            disabled: savingCostItemId.value === row.original.item_id,
            title: row.original.has_cost ? 'Custo cadastrado — edite e aperte Enter pra corrigir' : 'Sem custo cadastrado — digite o valor e aperte Enter',
            onKeydown: (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    event.target.blur();
                    saveCost(row.original.item_id, event.target.value);
                }
            },
        }),
    },
    {
        accessorKey: 'platform_fee',
        header: 'Comissão da plataforma',
        cell: ({ row }) => h('input', {
            type: 'number',
            step: '0.01',
            min: '0',
            class: `w-24 rounded-lg border px-2 py-1 text-sm ${row.original.has_fee_data ? 'border-[var(--surface-border)] text-slate-500' : 'border-amber-400 text-amber-500'}`,
            value: row.original.platform_fee,
            disabled: savingFeeOrderId.value === row.original.order_id,
            title: row.original.has_fee_data ? 'Comissão registrada — edite e aperte Enter pra corrigir' : 'A plataforma ainda não informou a taxa real desse pedido — digite o valor e aperte Enter',
            onKeydown: (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    event.target.blur();
                    saveFee(row.original.order_id, event.target.value);
                }
            },
        }),
    },
    {
        // Frete que a loja pagou (Correios da pré-postagem, Flex), já
        // descontado o frete recebido do comprador quando é da loja (Amazon).
        accessorKey: 'shipping_cost',
        header: 'Frete (loja)',
        cell: ({ row }) => h('span', { class: 'text-slate-500' }, formatPrice(row.original.shipping_cost ?? 0)),
    },
    {
        accessorKey: 'net_profit',
        header: 'Margem de contribuição',
        cell: ({ row }) => h('div', {}, [
            h('span', { class: row.original.net_profit >= 0 ? 'text-success font-semibold' : 'text-error font-semibold' }, formatPrice(row.original.net_profit)),
            !row.original.has_fee_data
                ? h('span', { class: 'ml-2 text-xs text-amber-500', title: 'Calculado sem descontar comissão — dado ainda não disponível' }, '⚠ incompleto')
                : null,
        ]),
    },
];
</script>

<template>
    <Head title="Fluxo de Caixa" />

    <AdminLayout>
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-2xl font-bold">Fluxo de Caixa</h1>
            <button v-if="can('financeiro.create')" type="button"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis"
                @click="showForm = !showForm">
                <i class="fas fa-plus text-xs"></i> Novo lançamento
            </button>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <CardStats stat-subtitle="SALDO" :stat-title="formatPrice(summary.balance)" stat-icon-name="fas fa-scale-balanced" variant="primary" />
            <CardStats stat-subtitle="ENTRADAS" :stat-title="formatPrice(summary.income)" stat-icon-name="fas fa-arrow-trend-up" variant="success" />
            <CardStats stat-subtitle="SAÍDAS" :stat-title="formatPrice(summary.expense)" stat-icon-name="fas fa-arrow-trend-down" variant="error" />
            <CardStats stat-subtitle="VALOR EM ESTOQUE" :stat-title="formatPrice(summary.stockValue)" stat-icon-name="fas fa-boxes-stacked" variant="warning" />
        </div>

        <form v-if="showForm" class="mb-6 grid grid-cols-1 gap-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:grid-cols-5" @submit.prevent="submit">
            <div>
                <label class="block text-xs font-medium text-slate-400">Tipo</label>
                <select v-model="form.type" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
                    <option value="income">Entrada</option>
                    <option value="expense">Saída</option>
                </select>
            </div>
            <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-slate-400">Descrição</label>
                <input v-model="form.description" type="text" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400">Valor (R$)</label>
                <input v-model.number="form.amount" type="number" step="0.01" min="0.01" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400">Data</label>
                <input v-model="form.entry_date" type="date" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-slate-400">Centro de custo (opcional)</label>
                <select v-model="form.cost_center_id" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
                    <option value="">Nenhum</option>
                    <option v-for="cc in props.costCenters" :key="cc.id" :value="cc.id">{{ cc.name }}</option>
                </select>
            </div>
            <div class="sm:col-span-5">
                <button type="submit" :disabled="form.processing" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                    Salvar lançamento
                </button>
            </div>
        </form>

        <DataTable
            :columns="columns"
            :data="entryRows"
            :paginator="entries"
            :search="entriesFilter.search ?? ''"
            :sort="toTableSort(entriesFilter.sort, entriesFilter.direction)"
            search-placeholder="Buscar lançamento..."
            empty-message="Nenhum lançamento registrado."
            @update:search="visit({ entries_search: $event })"
            @update:sort="visit(sortParams($event, 'entries_'))"
        />

        <div class="mt-8 mb-4">
            <h2 class="text-lg font-bold">Margem de Contribuição por Venda</h2>
            <p class="text-sm text-slate-400">Produto a produto: quanto custou no fornecedor, quanto ficou de comissão na plataforma, quanto a loja pagou de frete e quanto sobrou no bolso (sem ADS, que sai no total do mês no Financeiro).</p>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <div>
                <label class="block text-xs font-medium text-slate-400">De</label>
                <input v-model="salesRangeStart" type="date" class="mt-1 rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400">Até</label>
                <input v-model="salesRangeEnd" type="date" class="mt-1 rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <button type="button" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis" @click="applySalesRange">
                Filtrar
            </button>
            <div>
                <label class="block text-xs font-medium text-slate-400">Plataforma</label>
                <select v-model="platformFilter" class="mt-1 rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm" @change="applyPlatform">
                    <option value="">Todas</option>
                    <option v-for="platform in salesPlatforms" :key="platform.value" :value="platform.value">{{ platform.label }}</option>
                </select>
            </div>
            <div class="ml-auto flex gap-2">
                <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs font-medium hover:bg-[var(--surface-muted)]" @click="applyPreset('all')">Tudo</button>
                <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs font-medium hover:bg-[var(--surface-muted)]" @click="applyPreset('thisMonth')">Mês atual</button>
                <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs font-medium hover:bg-[var(--surface-muted)]" @click="applyPreset('lastMonth')">Mês passado</button>
                <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs font-medium hover:bg-[var(--surface-muted)]" @click="applyPreset('year')">Ano todo</button>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <CardStats stat-subtitle="PAGO AO FORNECEDOR" :stat-title="formatPrice(salesTotals.cost)" stat-icon-name="fas fa-truck-loading" variant="primary" />
            <CardStats stat-subtitle="COMISSÃO DAS PLATAFORMAS" :stat-title="formatPrice(salesTotals.fee)" stat-icon-name="fas fa-percent" variant="primary" />
            <CardStats stat-subtitle="FRETE PAGO PELA LOJA" :stat-title="formatPrice(salesTotals.shipping)" stat-icon-name="fas fa-truck" variant="primary" />
            <CardStats stat-subtitle="MARGEM DE CONTRIBUIÇÃO" :stat-title="formatPrice(salesTotals.netProfit)" stat-icon-name="fas fa-sack-dollar" :variant="salesTotals.netProfit >= 0 ? 'success' : 'error'" />
        </div>

        <DataTable
            :columns="salesColumns"
            :data="salesRows"
            :paginator="sales"
            :search="salesFilter.search ?? ''"
            :sort="toTableSort(salesFilter.sort, salesFilter.direction)"
            row-key="item_id"
            search-placeholder="Buscar produto, plataforma ou nº do pedido..."
            empty-message="Nenhuma venda no período."
            @update:search="visit({ search: $event })"
            @update:sort="visit(sortParams($event))"
        />
    </AdminLayout>
</template>
