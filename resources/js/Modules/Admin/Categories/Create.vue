<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    description: '',
});

const submit = () => {
    form.post('/admin/categories');
};
</script>

<template>
    <Head title="Nova categoria" />

    <AdminLayout>
        <h1 class="text-2xl font-bold">Nova categoria</h1>

        <form class="mt-6 max-w-xl space-y-4" @submit.prevent="submit">
            <div>
                <label for="name" class="block text-sm font-medium">Nome</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    required
                    class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                >
                <InputError :message="form.errors.name" />
            </div>

            <div>
                <label for="description" class="block text-sm font-medium">Descrição</label>
                <textarea
                    id="description"
                    v-model="form.description"
                    rows="4"
                    class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                />
                <InputError :message="form.errors.description" />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:bg-gray-300"
            >
                Criar categoria
            </button>
        </form>
    </AdminLayout>
</template>
