<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({
    products: {
        type: Object,
        required: true,
    },
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const addToCart = (productId) => {
    router.post(
        '/cart',
        { product_id: productId, quantity: 1 },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head title="Catálogo" />

    <AppLayout>
        <h1 class="text-2xl font-bold">Catálogo</h1>

        <p v-if="products.data.length === 0" class="mt-4 text-gray-500">
            Nenhum produto cadastrado ainda.
        </p>

        <ul v-else class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <li
                v-for="product in products.data"
                :key="product.id"
                class="rounded-lg border border-gray-200 bg-white p-4"
            >
                <span
                    v-if="product.category"
                    class="text-xs font-medium uppercase tracking-wide text-gray-400"
                >
                    {{ product.category.name }}
                </span>

                <h2 class="mt-1 font-semibold">{{ product.name }}</h2>

                <p class="mt-2 text-lg font-bold">{{ formatPrice(product.price) }}</p>

                <p class="mt-1 text-sm" :class="product.stock > 0 ? 'text-green-600' : 'text-red-500'">
                    {{ product.stock > 0 ? `${product.stock} em estoque` : 'Esgotado' }}
                </p>

                <button
                    type="button"
                    :disabled="product.stock < 1"
                    class="mt-3 w-full rounded bg-gray-900 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:bg-gray-300"
                    @click="addToCart(product.id)"
                >
                    Adicionar ao carrinho
                </button>
            </li>
        </ul>

        <nav v-if="products.last_page > 1" class="mt-8 flex flex-wrap gap-2">
            <template v-for="link in products.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    class="rounded px-3 py-1 text-sm"
                    :class="link.active ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                    v-html="link.label"
                />
                <span
                    v-else
                    class="rounded px-3 py-1 text-sm text-gray-300"
                    v-html="link.label"
                />
            </template>
        </nav>
    </AppLayout>
</template>
