<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { DataTable } from '@/Shared/Components/DataTable';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, router, useForm } from '@inertiajs/vue3';
import { h, ref } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    recharges: { type: Array, default: () => [] },
    shopeeBalance: { type: [Number, null], default: null },
    summary: { type: Object, required: true },
});

const { can } = usePermissions();
const showForm = ref(false);

const form = useForm({
    channel: 'shopee',
    amount: 0,
    recharge_date: new Date().toISOString().slice(0, 10),
    notes: '',
});

const submit = () => {
    form.post('/admin/anuncios/recargas', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showForm.value = false;
        },
    });
};

const formatPrice = (value) =>
    value === null || value === undefined
        ? '—'
        : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const destroy = async (recharge) => {
    if (await confirmDelete({ title: `Remover essa recarga de ${formatPrice(recharge.amount)}?` })) {
        router.delete(`/admin/anuncios/recargas/${recharge.id}`);
    }
};

// Mesma paleta usada em Invoices/Index.vue e no Dashboard Financeiro — cor
// real de cada plataforma.
const CHANNEL_STYLES = {
    shopee: { label: 'Shopee', color: '#EE4D2D' },
    mercado_livre: { label: 'Mercado Livre', color: '#2968C8' },
};

const hexToRgba = (hex, alpha) => {
    const value = hex.replace('#', '');
    const r = parseInt(value.substring(0, 2), 16);
    const g = parseInt(value.substring(2, 4), 16);
    const b = parseInt(value.substring(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
};

const columns = [
    { accessorKey: 'recharge_date', header: 'Data', cell: ({ row }) => new Date(`${row.original.recharge_date}T00:00:00`).toLocaleDateString('pt-BR') },
    {
        id: 'channel',
        header: 'Canal',
        cell: ({ row }) => {
            const style = CHANNEL_STYLES[row.original.channel] ?? { label: row.original.channel, color: '#64748B' };
            return h('span', {
                class: 'inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold',
                style: { color: style.color, background: hexToRgba(style.color, 0.12) },
            }, style.label);
        },
    },
    { accessorKey: 'amount', header: 'Valor', cell: ({ row }) => h('span', { class: 'font-semibold' }, formatPrice(row.original.amount)) },
    { id: 'notes', header: 'Observações', accessorFn: (row) => row.notes ?? '—' },
    { id: 'creator', header: 'Registrado por', accessorFn: (row) => row.creator?.name ?? '—' },
    {
        id: 'actions',
        header: 'Ações',
        enableSorting: false,
        cell: ({ row }) => (can('financeiro.delete')
            ? h('div', { class: 'flex justify-end' }, h(ActionIcon, { icon: 'fa-trash', label: 'Remover', color: 'red', onClick: () => destroy(row.original) }))
            : null),
    },
];
</script>

<template>
    <Head title="Recargas de Anúncio" />

    <AdminLayout>
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-2xl font-bold">Recargas de Anúncio</h1>
            <button v-if="can('financeiro.create')" type="button"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis"
                @click="showForm = !showForm">
                <i class="fas fa-plus text-xs"></i> Registrar recarga
            </button>
        </div>

        <p class="mb-4 text-sm text-slate-500">
            Nenhuma das duas plataformas expõe um extrato de recarga consultável por API — só o saldo <strong>atual</strong>
            da Shopee (abaixo, direto da API) está disponível ao vivo. O histórico de quando/quanto você recarregou é
            registrado manualmente aqui.
        </p>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <CardStats
                stat-subtitle="SALDO ATUAL SHOPEE ADS (AO VIVO)"
                :stat-title="shopeeBalance !== null ? formatPrice(shopeeBalance) : 'Indisponível'"
                stat-icon-name="fas fa-wallet" variant="primary"
            />
            <CardStats stat-subtitle="TOTAL RECARREGADO — SHOPEE" :stat-title="formatPrice(summary.shopee)" stat-icon-name="fas fa-bullhorn" variant="warning" />
            <CardStats stat-subtitle="TOTAL RECARREGADO — MERCADO LIVRE" :stat-title="formatPrice(summary.mercado_livre)" stat-icon-name="fas fa-bullhorn" variant="info" />
        </div>

        <form v-if="showForm" class="mb-6 grid grid-cols-1 gap-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:grid-cols-5" @submit.prevent="submit">
            <div>
                <label class="block text-xs font-medium text-slate-400">Canal</label>
                <select v-model="form.channel" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
                    <option value="shopee">Shopee</option>
                    <option value="mercado_livre">Mercado Livre</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400">Valor (R$)</label>
                <input v-model.number="form.amount" type="number" step="0.01" min="0.01" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400">Data</label>
                <input v-model="form.recharge_date" type="date" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-slate-400">Observações (opcional)</label>
                <input v-model="form.notes" type="text" maxlength="500" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div class="sm:col-span-5">
                <button type="submit" :disabled="form.processing" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                    Salvar recarga
                </button>
            </div>
        </form>

        <DataTable
            :columns="columns"
            :data="props.recharges"
            search-placeholder="Buscar recarga..."
            empty-message="Nenhuma recarga registrada ainda."
        />
    </AdminLayout>
</template>
