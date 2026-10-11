<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    staff: { type: Array, default: () => [] },
});

const form = useForm({
    customer_name: '',
    customer_contact: '',
    description: '',
    assigned_to: '',
});

const submit = () => {
    form.post('/admin/ordens-de-servico');
};
</script>

<template>
    <Head title="Nova ordem de serviço" />

    <AdminLayout>
        <h1 class="mb-6 text-2xl font-bold">Nova ordem de serviço</h1>

        <form class="max-w-2xl space-y-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 shadow-sm" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium">Cliente</label>
                    <input v-model="form.customer_name" type="text" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                    <InputError :message="form.errors.customer_name" />
                </div>
                <div>
                    <label class="block text-sm font-medium">Contato</label>
                    <input v-model="form.customer_contact" type="text" placeholder="Telefone ou e-mail" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium">Descrição</label>
                <textarea v-model="form.description" rows="4" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2" />
                <InputError :message="form.errors.description" />
            </div>

            <div>
                <label class="block text-sm font-medium">Responsável</label>
                <select v-model="form.assigned_to" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                    <option value="">Não atribuído</option>
                    <option v-for="member in props.staff" :key="member.id" :value="member.id">{{ member.name }}</option>
                </select>
            </div>

            <button type="submit" :disabled="form.processing"
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                Criar ordem de serviço
            </button>
        </form>
    </AdminLayout>
</template>
