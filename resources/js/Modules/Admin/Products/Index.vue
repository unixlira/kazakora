<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { confirmDelete } from '@/Shared/notify';

defineProps({
    products: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const searchForm = useForm({
    search: '',
});

const search = () => {
    router.get('/admin/products', { search: searchForm.search }, { preserveState: true });
};

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const destroy = async (product) => {
    if (await confirmDelete({ title: `Remover o produto "${product.name}"?` })) {
        router.delete(`/admin/products/${product.id}`);
    }
};
</script>

<template>
    <Head title="Produtos" />

    <AdminLayout>
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold">Produtos</h1>
            <Link
                href="/admin/products/create"
                class="rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700"
            >
                Novo produto
            </Link>
        </div>

        <form class="mt-6" @submit.prevent="search">
            <input
                v-model="searchForm.search"
                type="text"
                placeholder="Buscar por nome ou SKU..."
                class="w-full max-w-sm rounded border border-gray-300 px-3 py-2 text-sm"
            >
        </form>

        <div class="mt-6 overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase text-gray-400">
                    <tr>
                        <th class="px-4 py-3">Produto</th>
                        <th class="px-4 py-3">Categoria</th>
                        <th class="px-4 py-3">Preço</th>
                        <th class="px-4 py-3">Estoque</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="product in products.data" :key="product.id" class="border-b border-gray-100">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ product.name }}</p>
                            <p class="text-xs text-gray-400">{{ product.sku }}</p>
                        </td>
                        <td class="px-4 py-3">{{ product.category?.name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ formatPrice(product.price) }}</td>
                        <td class="px-4 py-3" :class="product.stock <= 5 ? 'text-red-500' : ''">
                            {{ product.stock }}
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs"
                                :class="product.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                            >
                                {{ product.is_active ? 'Ativo' : 'Inativo' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="`/admin/products/${product.id}/edit`" class="mr-3 hover:underline">
                                Editar
                            </Link>
                            <button type="button" class="text-red-500 hover:underline" @click="destroy(product)">
                                Remover
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="products.last_page > 1" class="mt-6 flex flex-wrap gap-2">
            <template v-for="link in products.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-state
                    class="rounded px-3 py-1 text-sm"
                    :class="link.active ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                    v-html="link.label"
                />
                <span v-else class="rounded px-3 py-1 text-sm text-gray-300" v-html="link.label" />
            </template>
        </nav>
    </AdminLayout>
</template>
