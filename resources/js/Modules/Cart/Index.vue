<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    total: {
        type: Number,
        default: 0,
    },
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const updateQuantity = (productId, quantity) => {
    router.patch(`/cart/${productId}`, { quantity }, { preserveScroll: true });
};

const removeItem = (productId) => {
    router.delete(`/cart/${productId}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Carrinho" />

    <AppLayout>
        <h1 class="text-2xl font-bold">Carrinho</h1>

        <p v-if="items.length === 0" class="mt-4 text-gray-500">
            Seu carrinho está vazio.
            <Link href="/" class="underline">Ver catálogo</Link>
        </p>

        <div v-else class="mt-6 space-y-4">
            <div
                v-for="item in items"
                :key="item.product.id"
                class="flex items-center justify-between rounded-lg border border-gray-200 bg-white p-4"
            >
                <div>
                    <h2 class="font-semibold">{{ item.product.name }}</h2>
                    <p class="text-sm text-gray-500">{{ formatPrice(item.product.price) }} / un.</p>
                </div>

                <div class="flex items-center gap-4">
                    <input
                        type="number"
                        min="1"
                        :max="item.product.stock"
                        :value="item.quantity"
                        class="w-16 rounded border border-gray-300 px-2 py-1 text-center"
                        @change="updateQuantity(item.product.id, Number($event.target.value))"
                    >

                    <span class="w-24 text-right font-medium">{{ formatPrice(item.subtotal) }}</span>

                    <button
                        type="button"
                        class="text-sm text-red-500 hover:underline"
                        @click="removeItem(item.product.id)"
                    >
                        Remover
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between border-t border-gray-200 pt-4">
                <span class="text-lg font-bold">Total</span>
                <span class="text-lg font-bold">{{ formatPrice(total) }}</span>
            </div>

            <Link
                href="/checkout"
                class="block w-full rounded bg-gray-900 py-3 text-center font-medium text-white hover:bg-gray-700"
            >
                Finalizar compra
            </Link>
        </div>
    </AppLayout>
</template>
