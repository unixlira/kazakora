<script setup>
/**
 * Aba "Lançamentos": entradas e saídas digitadas à mão, filtradas pelo
 * período do topo (antes os cartões somavam tudo, sem período). Não entram
 * na conta do lucro. Data do formulário em São Paulo (antes vinha de
 * toISOString() e virava o dia depois das 21h); valor começa vazio; dá para
 * editar (a rota PUT já existia).
 */
import CardStats from '@/Shared/Components/CardStats.vue';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { toTableSort } from '@/Shared/useServerTable';
import { confirmDelete } from '@/Shared/notify';
import { formatPrice, hojeSaoPaulo } from '@/Shared/graficos';
import { router, useForm } from '@inertiajs/vue3';
import { computed, h, nextTick, ref, watch } from 'vue';

const props = defineProps({
    entries: { type: Object, default: () => ({ data: [] }) },
    summary: { type: Object, default: () => ({ balance: 0, income: 0, expense: 0 }) },
    entriesFilter: { type: Object, default: () => ({}) },
    costCenters: { type: Array, default: () => [] },
    abrirFormulario: { type: Boolean, default: false },
});
const emit = defineEmits(['visitar']);

const { can } = usePermissions();
const mostrarForm = ref(props.abrirFormulario);
const editando = ref(null);
const campoDescricao = ref(null);

const novo = () => ({ type: 'expense', description: '', amount: '', cost_center_id: '', entry_date: hojeSaoPaulo() });
const form = useForm(novo());

const abrirNovo = async () => {
    editando.value = null;
    form.defaults(novo());
    form.reset();
    form.clearErrors();
    mostrarForm.value = true;
    await nextTick();
    campoDescricao.value?.focus();
};
watch(() => props.abrirFormulario, (v) => { if (v) abrirNovo(); });
defineExpose({ abrirNovo });

const editar = async (entry) => {
    editando.value = entry.id;
    form.defaults({ type: entry.type, description: entry.description, amount: entry.amount, cost_center_id: entry.cost_center_id ?? '', entry_date: String(entry.entry_date).slice(0, 10) });
    form.reset();
    form.clearErrors();
    mostrarForm.value = true;
    await nextTick();
    campoDescricao.value?.focus();
};

const salvar = () => {
    const opcoes = { preserveScroll: true, onSuccess: () => { mostrarForm.value = false; editando.value = null; } };
    if (editando.value) {
        form.put(`/admin/fluxo-de-caixa/${editando.value}`, opcoes);
    } else {
        form.post('/admin/fluxo-de-caixa', opcoes);
    }
};

const remover = async (entry) => {
    if (await confirmDelete({ title: `Remover o lançamento "${entry.description}"?` })) {
        router.delete(`/admin/fluxo-de-caixa/${entry.id}`, { preserveScroll: true });
    }
};

const dataBr = (iso) => { const d = String(iso).slice(0, 10); return `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}`; };

const colunas = computed(() => [
    { accessorKey: 'entry_date', header: 'Data', cell: ({ row }) => h('span', { class: 'tabular-nums' }, dataBr(row.original.entry_date)) },
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
        cell: ({ row }) => h('span', { class: `tabular-nums font-medium ${row.original.type === 'income' ? 'text-success' : 'text-error'}` }, formatPrice(row.original.amount)),
    },
    {
        id: 'actions',
        header: 'Ações',
        enableSorting: false,
        cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
            can('financeiro.edit') ? h(ActionIcon, { icon: 'fa-pen', label: 'Editar', color: 'blue', onClick: () => editar(row.original) }) : null,
            can('financeiro.delete') ? h(ActionIcon, { icon: 'fa-trash', label: 'Remover', color: 'red', onClick: () => remover(row.original) }) : null,
        ]),
    },
]);

const sortParams = (sort) => ({ entries_sort: sort?.id ?? null, entries_direction: sort ? (sort.desc ? 'desc' : 'asc') : null });
</script>

<template>
    <div>
        <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3 sm:gap-4">
            <CardStats stat-subtitle="Saldo do período" :stat-title="formatPrice(summary.balance)" stat-icon-name="fas fa-scale-balanced" :variant="summary.balance >= 0 ? 'primary' : 'error'" compact />
            <CardStats stat-subtitle="Entradas" :stat-title="formatPrice(summary.income)" stat-icon-name="fas fa-arrow-trend-up" variant="success" compact />
            <CardStats stat-subtitle="Saídas" :stat-title="formatPrice(summary.expense)" stat-icon-name="fas fa-arrow-trend-down" variant="error" compact />
        </div>
        <p class="mb-4 text-sm text-[var(--texto-suave)]">Lançamentos à mão (aluguel, contador, entrada avulsa). Não entram na conta do lucro do Resumo.</p>

        <form v-if="mostrarForm" class="mb-4 grid grid-cols-1 gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:grid-cols-6" @submit.prevent="salvar">
            <h2 class="text-base font-semibold sm:col-span-6">{{ editando ? 'Editar lançamento' : 'Novo lançamento' }}</h2>
            <div>
                <label for="lanc-tipo" class="block text-xs font-medium text-[var(--texto-suave)]">Tipo</label>
                <select id="lanc-tipo" v-model="form.type" class="campo mt-1 w-full">
                    <option value="expense">Saída</option>
                    <option value="income">Entrada</option>
                </select>
            </div>
            <div class="sm:col-span-2">
                <label for="lanc-descricao" class="block text-xs font-medium text-[var(--texto-suave)]">Descrição</label>
                <input id="lanc-descricao" ref="campoDescricao" v-model="form.description" type="text" required maxlength="255" autocomplete="off" class="campo mt-1 w-full">
                <p v-if="form.errors.description" class="mt-1 text-xs text-error">{{ form.errors.description }}</p>
            </div>
            <div>
                <label for="lanc-valor" class="block text-xs font-medium text-[var(--texto-suave)]">Valor (R$)</label>
                <input id="lanc-valor" v-model="form.amount" type="number" step="0.01" min="0.01" inputmode="decimal" required placeholder="0,00" class="campo mt-1 w-full tabular-nums">
                <p v-if="form.errors.amount" class="mt-1 text-xs text-error">{{ form.errors.amount }}</p>
            </div>
            <div>
                <label for="lanc-data" class="block text-xs font-medium text-[var(--texto-suave)]">Data</label>
                <input id="lanc-data" v-model="form.entry_date" type="date" required class="campo mt-1 w-full">
                <p v-if="form.errors.entry_date" class="mt-1 text-xs text-error">{{ form.errors.entry_date }}</p>
            </div>
            <div>
                <label for="lanc-centro" class="block text-xs font-medium text-[var(--texto-suave)]">Centro de custo (opcional)</label>
                <select id="lanc-centro" v-model="form.cost_center_id" class="campo mt-1 w-full">
                    <option value="">Nenhum</option>
                    <option v-for="cc in costCenters" :key="cc.id" :value="cc.id">{{ cc.name }}</option>
                </select>
            </div>
            <div class="flex gap-2 sm:col-span-6">
                <button type="submit" :disabled="form.processing" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-emphasis disabled:opacity-50">
                    {{ form.processing ? 'Salvando…' : (editando ? 'Salvar alterações' : 'Salvar lançamento') }}
                </button>
                <button type="button" class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]" @click="mostrarForm = false; editando = null">Cancelar</button>
            </div>
        </form>

        <DataTable
            :columns="colunas"
            :data="entries?.data ?? []"
            :paginator="entries"
            :search="entriesFilter.search ?? ''"
            :sort="toTableSort(entriesFilter.sort, entriesFilter.direction)"
            search-placeholder="Buscar lançamento…"
            empty-message="Nenhum lançamento neste período."
            @update:search="emit('visitar', { entries_search: $event })"
            @update:sort="emit('visitar', sortParams($event))"
        />
    </div>
</template>

<style scoped>
.campo {
    background: var(--surface);
    color: var(--surface-foreground);
    border: 1px solid var(--surface-border);
    border-radius: 8px;
    padding: 6px 8px;
    font-size: 14px;
    color-scheme: light dark;
}
.campo option { background: var(--surface); color: var(--surface-foreground); }
.campo:focus-visible { outline: 2px solid var(--foco, #9a3412); outline-offset: 2px; }
</style>
