<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    serviceOrder: { type: Object, required: true },
    staff: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
});

const statusLabels = { open: 'Aberta', in_progress: 'Em andamento', completed: 'Concluída', cancelled: 'Cancelada' };

const form = useForm({
    customer_name: props.serviceOrder.customer_name,
    customer_contact: props.serviceOrder.customer_contact ?? '',
    description: props.serviceOrder.description,
    assigned_to: props.serviceOrder.assigned_to ?? '',
    status: props.serviceOrder.status,
});

const submit = () => {
    form.put(`/admin/ordens-de-servico/${props.serviceOrder.id}`);
};
</script>

<template>
    <Head title="Editar ordem de serviço" />

    <AdminLayout>
        <h1 class="mb-6 text-2xl font-bold">Editar ordem de serviço #{{ serviceOrder.id }}</h1>

        <form class="max-w-2xl space-y-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 shadow-sm" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium">Cliente</label>
                    <input v-model="form.customer_name" type="text" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                    <InputError :message="form.errors.customer_name" />
                </div>
                <div>
                    <label class="block text-sm font-medium">Contato</label>
                    <input v-model="form.customer_contact" type="text" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium">Descrição</label>
                <textarea v-model="form.description" rows="4" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2" />
                <InputError :message="form.errors.description" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium">Responsável</label>
                    <select v-model="form.assigned_to" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                        <option value="">Não atribuído</option>
                        <option v-for="member in props.staff" :key="member.id" :value="member.id">{{ member.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium">Status</label>
                    <select v-model="form.status" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                        <option v-for="status in props.statuses" :key="status" :value="status">{{ statusLabels[status] ?? status }}</option>
                    </select>
                </div>
            </div>

            <button type="submit" :disabled="form.processing"
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                Salvar alterações
            </button>
        </form>
    </AdminLayout>
</template>
