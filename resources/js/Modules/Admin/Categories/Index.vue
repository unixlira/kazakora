<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { confirmDelete } from '@/Shared/notify';

defineProps({
    categories: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const categoryError = computed(() => page.props.errors?.category);

const destroy = async (category) => {
    if (await confirmDelete({ title: `Remover a categoria "${category.name}"?` })) {
        router.delete(`/admin/categorias/${category.id}`);
    }
};
</script>

<template>
    <Head title="Categorias" />

    <AdminLayout>
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold">Categorias</h1>
            <Link
                href="/admin/categorias/criar"
                class="rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700"
            >
                Nova categoria
            </Link>
        </div>

        <InputError :message="categoryError" class="mt-4" />

        <div class="mt-6 overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase text-gray-400">
                    <tr>
                        <th class="px-4 py-3">Nome</th>
                        <th class="px-4 py-3">Produtos</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="category in categories" :key="category.id" class="border-b border-gray-100">
                        <td class="px-4 py-3">{{ category.name }}</td>
                        <td class="px-4 py-3">{{ category.products_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="`/admin/categorias/${category.id}/editar`" class="mr-3 hover:underline">
                                Editar
                            </Link>
                            <button type="button" class="text-red-500 hover:underline" @click="destroy(category)">
                                Remover
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
