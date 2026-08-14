<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, h, ref } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    entries: { type: Array, default: () => [] },
    costCenters: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
    sales: { type: Array, default: () => [] },
    salesFilter: { type: Object, required: true },
});

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
    router.get('/admin/fluxo-de-caixa', { start: salesRangeStart.value || undefined, end: salesRangeEnd.value || undefined }, { preserveScroll: true, preserveState: true });
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

// Filtro por plataforma — pedido explícito 2026-08-14.
const platformFilter = ref('');
const availablePlatforms = computed(() => [...new Set(props.sales.map((sale) => sale.platform))].sort());
const filteredSales = computed(() => (platformFilter.value ? props.sales.filter((sale) => sale.platform === platformFilter.value) : props.sales));

// Totais da listagem de vendas — soma sempre calculada no front, mesmo
// vazia (0,00), pra nunca deixar a área em branco sem explicação. Pedido
// explícito 2026-08-14: "se não tiver dado colocar 0,00". Segue o filtro de
// plataforma acima, pra bater com o que está na tabela.
const salesTotals = computed(() => filteredSales.value.reduce((acc, sale) => {
    acc.cost += sale.product_cost;
    acc.fee += sale.platform_fee;
    acc.netProfit += sale.net_profit;
    return acc;
}, { cost: 0, fee: 0, netProfit: 0 }));

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
                ? h('span', { class: 'ml-2 text-xs text-amber-500', title: 'Produto sem custo cadastrado — lucro abaixo do real' }, '⚠ sem custo')
                : null,
        ]),
    },
    { accessorKey: 'platform', header: 'Plataforma' },
    { accessorKey: 'product_cost', header: 'Pago ao fornecedor', cell: ({ row }) => h('span', { class: 'text-slate-500' }, formatPrice(row.original.product_cost)) },
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
        accessorKey: 'net_profit',
        header: 'Lucro líquido',
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

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <CardStats stat-subtitle="SALDO" :stat-title="formatPrice(summary.balance)" stat-icon-name="fas fa-scale-balanced" variant="primary" />
            <CardStats stat-subtitle="ENTRADAS" :stat-title="formatPrice(summary.income)" stat-icon-name="fas fa-arrow-trend-up" variant="success" />
            <CardStats stat-subtitle="SAÍDAS" :stat-title="formatPrice(summary.expense)" stat-icon-name="fas fa-arrow-trend-down" variant="error" />
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
            :data="props.entries"
            search-placeholder="Buscar lançamento..."
            empty-message="Nenhum lançamento registrado."
        />

        <div class="mt-8 mb-4">
            <h2 class="text-lg font-bold">Lucro por Venda</h2>
            <p class="text-sm text-slate-400">Produto a produto: quanto custou no fornecedor, quanto ficou de comissão na plataforma e quanto sobrou de lucro.</p>
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
                <select v-model="platformFilter" class="mt-1 rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
                    <option value="">Todas</option>
                    <option v-for="platform in availablePlatforms" :key="platform" :value="platform">{{ platform }}</option>
                </select>
            </div>
            <div class="ml-auto flex gap-2">
                <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs font-medium hover:bg-[var(--surface-muted)]" @click="applyPreset('all')">Tudo</button>
                <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs font-medium hover:bg-[var(--surface-muted)]" @click="applyPreset('thisMonth')">Mês atual</button>
                <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs font-medium hover:bg-[var(--surface-muted)]" @click="applyPreset('lastMonth')">Mês passado</button>
                <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs font-medium hover:bg-[var(--surface-muted)]" @click="applyPreset('year')">Ano todo</button>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <CardStats stat-subtitle="PAGO AO FORNECEDOR" :stat-title="formatPrice(salesTotals.cost)" stat-icon-name="fas fa-truck-loading" variant="primary" />
            <CardStats stat-subtitle="COMISSÃO DAS PLATAFORMAS" :stat-title="formatPrice(salesTotals.fee)" stat-icon-name="fas fa-percent" variant="primary" />
            <CardStats stat-subtitle="LUCRO LÍQUIDO" :stat-title="formatPrice(salesTotals.netProfit)" stat-icon-name="fas fa-sack-dollar" :variant="salesTotals.netProfit >= 0 ? 'success' : 'error'" />
        </div>

        <DataTable
            :columns="salesColumns"
            :data="filteredSales"
            search-placeholder="Buscar produto ou plataforma..."
            empty-message="Nenhuma venda no período."
        />
    </AdminLayout>
</template>
