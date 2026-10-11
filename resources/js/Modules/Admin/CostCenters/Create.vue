<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    code: '',
    name: '',
    description: '',
    is_active: true,
});

const submit = () => {
    form.post('/admin/centros-de-custo');
};
</script>

<template>
    <Head title="Novo centro de custo" />

    <AdminLayout>
        <h1 class="mb-6 text-2xl font-bold">Novo centro de custo</h1>

        <form class="max-w-2xl space-y-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 shadow-sm" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label for="code" class="block text-sm font-medium">Código</label>
                    <input id="code" v-model="form.code" type="text" required
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                    <InputError :message="form.errors.code" />
                </div>
                <div class="sm:col-span-2">
                    <label for="name" class="block text-sm font-medium">Nome</label>
                    <input id="name" v-model="form.name" type="text" required
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                    <InputError :message="form.errors.name" />
                </div>
            </div>

            <div>
                <label for="description" class="block text-sm font-medium">Descrição</label>
                <textarea id="description" v-model="form.description" rows="3"
                    class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2" />
                <InputError :message="form.errors.description" />
            </div>

            <label class="flex items-center gap-2 text-sm font-medium">
                <input v-model="form.is_active" type="checkbox" class="rounded border-[var(--surface-border)]">
                Centro de custo ativo
            </label>

            <button type="submit" :disabled="form.processing"
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                Criar centro de custo
            </button>
        </form>
    </AdminLayout>
</template>
