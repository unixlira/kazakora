<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    document: '',
    email: '',
    phone: '',
    city: '',
    state: '',
    is_active: true,
});

const submit = () => {
    form.post('/admin/fornecedores');
};
</script>

<template>
    <Head title="Novo fornecedor" />

    <AdminLayout>
        <h1 class="mb-6 text-2xl font-bold">Novo fornecedor</h1>

        <form class="max-w-2xl space-y-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 shadow-sm" @submit.prevent="submit">
            <div>
                <label for="name" class="block text-sm font-medium">Nome</label>
                <input id="name" v-model="form.name" type="text" required
                    class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="document" class="block text-sm font-medium">CNPJ/CPF</label>
                    <input id="document" v-model="form.document" type="text"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                    <InputError :message="form.errors.document" />
                </div>
                <div>
                    <label for="phone" class="block text-sm font-medium">Telefone</label>
                    <input id="phone" v-model="form.phone" type="text"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                    <InputError :message="form.errors.phone" />
                </div>
            </div>

            <div>
                <label for="email" class="block text-sm font-medium">E-mail</label>
                <input id="email" v-model="form.email" type="email"
                    class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                <InputError :message="form.errors.email" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label for="city" class="block text-sm font-medium">Cidade</label>
                    <input id="city" v-model="form.city" type="text"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                </div>
                <div>
                    <label for="state" class="block text-sm font-medium">UF</label>
                    <input id="state" v-model="form.state" type="text" maxlength="2" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 uppercase">
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm font-medium">
                <input v-model="form.is_active" type="checkbox" class="rounded border-[var(--surface-border)]">
                Fornecedor ativo
            </label>

            <button type="submit" :disabled="form.processing"
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                Criar fornecedor
            </button>
        </form>
    </AdminLayout>
</template>
