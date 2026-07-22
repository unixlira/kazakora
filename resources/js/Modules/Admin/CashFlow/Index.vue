<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, router, useForm } from '@inertiajs/vue3';
import { h, ref } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    entries: { type: Array, default: () => [] },
    costCenters: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
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
            ? h('button', { type: 'button', class: 'text-sm text-error hover:underline', onClick: () => destroy(row.original) }, 'Remover')
            : null),
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
    </AdminLayout>
</template>
