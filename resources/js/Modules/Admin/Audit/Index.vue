<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import { Head, router } from '@inertiajs/vue3';
import { h, reactive } from 'vue';

const props = defineProps({
    logs: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    users: { type: Array, default: () => [] },
    entities: { type: Array, default: () => [] },
});

const actionLabels = { create: 'Criação', update: 'Edição', delete: 'Exclusão' };
const actionBadge = { create: 'active', update: 'pending', delete: 'inactive' };

const filterState = reactive({
    user_id: props.filters.user_id ?? '',
    action: props.filters.action ?? '',
    entity: props.filters.entity ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const applyFilters = () => {
    router.get('/admin/auditoria', filterState, { preserveState: true });
};

const columns = [
    { id: 'user', header: 'Usuário', accessorFn: (row) => row.user?.name ?? '—' },
    {
        accessorKey: 'action',
        header: 'Ação',
        cell: ({ row }) => h(StatusBadge, { status: actionBadge[row.original.action] ?? 'default', label: actionLabels[row.original.action] ?? row.original.action }),
    },
    { accessorKey: 'entity', header: 'Entidade' },
    { accessorKey: 'entity_id', header: 'ID do registro' },
    {
        accessorKey: 'created_at',
        header: 'Data/Hora',
        cell: ({ row }) => new Date(row.original.created_at).toLocaleString('pt-BR'),
    },
];
</script>

<template>
    <Head title="Auditoria" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Auditoria</h1>

        <div class="mb-4 grid grid-cols-1 gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <label class="block text-xs font-medium text-slate-400">Usuário</label>
                <select v-model="filterState.user_id" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
                    <option value="">Todos</option>
                    <option v-for="user in props.users" :key="user.id" :value="user.id">{{ user.name }}</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400">Ação</label>
                <select v-model="filterState.action" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
                    <option value="">Todas</option>
                    <option value="create">Criação</option>
                    <option value="update">Edição</option>
                    <option value="delete">Exclusão</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400">Entidade</label>
                <select v-model="filterState.entity" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
                    <option value="">Todas</option>
                    <option v-for="entity in props.entities" :key="entity" :value="entity">{{ entity }}</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400">De</label>
                <input v-model="filterState.from" type="date" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400">Até</label>
                <input v-model="filterState.to" type="date" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div class="sm:col-span-2 lg:col-span-5">
                <button type="button" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis" @click="applyFilters">
                    Filtrar
                </button>
            </div>
        </div>

        <DataTable
            :columns="columns"
            :data="props.logs"
            search-placeholder="Buscar no log..."
            empty-message="Nenhum registro de auditoria encontrado."
        />
    </AdminLayout>
</template>
