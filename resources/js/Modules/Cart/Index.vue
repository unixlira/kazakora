<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { confirmDelete } from '@/Shared/notify';
import { primaryImage } from '@/Shared/productCard';

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
    router.patch(`/carrinho/${productId}`, { quantity }, { preserveScroll: true });
};

const increment = (item) => updateQuantity(item.product.id, item.quantity + 1);
const decrement = (item) => updateQuantity(item.product.id, item.quantity - 1);

const removeItem = async (productId) => {
    if (await confirmDelete({ title: 'Remover item do carrinho?' })) {
        router.delete(`/carrinho/${productId}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Carrinho" />

    <AppLayout>
        <div class="mx-auto max-w-[1000px] px-4 py-12 md:px-6">
            <h1 class="font-display text-3xl font-semibold">Carrinho</h1>

            <p v-if="items.length === 0" class="mt-12 text-center text-store-fg-muted">
                Seu carrinho está vazio.
                <Link href="/" class="mt-3 block font-medium text-store-accent hover:underline">Ver catálogo</Link>
            </p>

            <template v-else>
                <div class="mt-8 divide-y divide-store-border rounded-2xl border border-store-border bg-store-bg-raised">
                    <div v-for="item in items" :key="item.product.id" class="flex flex-wrap items-center gap-4 p-5">
                        <Link :href="`/produtos/${item.product.slug}`"
                            class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-store-border bg-store-bg-sunken">
                            <img v-if="primaryImage(item.product)" :src="primaryImage(item.product)" :alt="item.product.name" class="h-full w-full object-cover">
                            <i v-else class="fas fa-box-open text-xl text-store-accent-strong opacity-40"></i>
                        </Link>

                        <div class="min-w-[180px] flex-1">
                            <Link :href="`/produtos/${item.product.slug}`" class="font-medium hover:text-store-accent hover:underline">{{ item.product.name }}</Link>
                            <p class="font-store-mono text-sm text-store-fg-muted">{{ formatPrice(item.product.final_price) }}</p>
                        </div>

                        <div class="flex items-center gap-2 rounded-full border border-store-border-strong px-1 py-1">
                            <button type="button" class="flex h-7 w-7 items-center justify-center rounded-full hover:bg-store-bg-sunken" @click="decrement(item)">
                                <i class="fas fa-minus text-xs"></i>
                            </button>
                            <span class="font-store-mono w-6 text-center text-sm">{{ item.quantity }}</span>
                            <button type="button" class="flex h-7 w-7 items-center justify-center rounded-full hover:bg-store-bg-sunken disabled:cursor-not-allowed disabled:opacity-40"
                                :disabled="item.quantity >= item.product.stock" @click="increment(item)">
                                <i class="fas fa-plus text-xs"></i>
                            </button>
                        </div>

                        <span class="font-store-mono w-28 text-right font-medium">{{ formatPrice(item.subtotal) }}</span>

                        <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full text-store-fg-muted hover:bg-red-50 hover:text-red-600" aria-label="Remover" @click="removeItem(item.product.id)">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>
                </div>

                <div class="mt-8 flex justify-end">
                    <div class="w-full max-w-sm rounded-2xl border border-store-border bg-store-bg-raised p-6">
                        <div class="flex items-baseline justify-between border-b border-store-border pb-4">
                            <span class="font-display text-lg font-semibold">Total</span>
                            <span class="text-xl font-semibold text-store-accent">{{ formatPrice(total) }}</span>
                        </div>
                        <Link href="/finalizacao"
                            class="mt-5 block rounded-lg bg-store-accent py-3 text-center text-sm font-semibold text-store-accent-contrast hover:opacity-90">
                            Finalizar compra
                        </Link>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
