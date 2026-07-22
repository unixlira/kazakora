<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, router, useForm } from '@inertiajs/vue3';
import { h, ref } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    shippingMethods: { type: Array, default: () => [] },
});

const { can } = usePermissions();
const showForm = ref(false);

const form = useForm({
    name: '',
    estimated_days: 3,
    price: 0,
    is_active: true,
});

const submit = () => {
    form.post('/admin/logistica', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showForm.value = false;
        },
    });
};

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const destroy = async (method) => {
    if (await confirmDelete({ title: `Remover o método "${method.name}"?` })) {
        router.delete(`/admin/logistica/${method.id}`);
    }
};

const columns = [
    { accessorKey: 'name', header: 'Nome' },
    { accessorKey: 'estimated_days', header: 'Prazo (dias)' },
    { accessorKey: 'price', header: 'Preço', cell: ({ row }) => formatPrice(row.original.price) },
    {
        id: 'status',
        header: 'Status',
        accessorFn: (row) => (row.is_active ? 'Active' : 'Inactive'),
        cell: ({ row }) => h(StatusBadge, { status: row.original.is_active ? 'active' : 'inactive', label: row.original.is_active ? 'Ativo' : 'Inativo' }),
    },
    {
        id: 'actions',
        header: 'Ações',
        enableSorting: false,
        cell: ({ row }) => (can('operacional.delete')
            ? h('button', { type: 'button', class: 'text-sm text-error hover:underline', onClick: () => destroy(row.original) }, 'Remover')
            : null),
    },
];
</script>

<template>
    <Head title="Logística" />

    <AdminLayout>
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-2xl font-bold">Logística — Métodos de envio</h1>
            <button v-if="can('operacional.create')" type="button"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis"
                @click="showForm = !showForm">
                <i class="fas fa-plus text-xs"></i> Novo método
            </button>
        </div>

        <form v-if="showForm" class="mb-6 grid grid-cols-1 gap-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:grid-cols-4" @submit.prevent="submit">
            <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-slate-400">Nome</label>
                <input v-model="form.name" type="text" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400">Prazo (dias)</label>
                <input v-model.number="form.estimated_days" type="number" min="0" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400">Preço (R$)</label>
                <input v-model.number="form.price" type="number" step="0.01" min="0" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div class="sm:col-span-4">
                <button type="submit" :disabled="form.processing" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                    Salvar método
                </button>
            </div>
        </form>

        <DataTable
            :columns="columns"
            :data="props.shippingMethods"
            search-placeholder="Buscar método..."
            empty-message="Nenhum método de envio cadastrado."
        />
    </AdminLayout>
</template>
