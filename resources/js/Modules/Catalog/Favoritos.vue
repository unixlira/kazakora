<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import ProductCard from '@/Shared/Components/ProductCard.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import { idsFavoritos } from '@/Shared/favoritos';

const props = defineProps({
    products: {
        type: Object,
        required: true,
    },
    favoriteIds: {
        type: Array,
        default: () => [],
    },
    reviewableProductIds: {
        type: Array,
        default: () => [],
    },
    reviewedProductIds: {
        type: Array,
        default: () => [],
    },
    // Sem login (2026-10-15): a lista vem do navegador pela URL (?ids=).
    visitante: {
        type: Boolean,
        default: false,
    },
});

onMounted(() => {
    if (!props.visitante) return;
    const ids = idsFavoritos().join(',');
    if (ids !== props.favoriteIds.join(',')) {
        router.get('/favoritos', ids ? { ids } : {}, { replace: true, preserveScroll: true });
    }
});

const isFavorite = (productId) => props.favoriteIds.includes(productId);
const canReview = (productId) => props.reviewableProductIds.includes(productId);
const hasReviewed = (productId) => props.reviewedProductIds.includes(productId);
</script>

<template>
    <Head title="Meus Favoritos" />

    <AppLayout>
        <section class="mx-auto max-w-[1320px] px-4 py-12 md:px-6">
            <h1 class="font-display text-3xl font-semibold">Meus Favoritos</h1>
            <p class="mt-2 text-store-fg-muted">Produtos que você marcou pra não perder de vista.</p>
            <p v-if="visitante" class="mt-2 text-sm text-store-fg-muted">
                Seus favoritos estão guardados só neste aparelho.
                <Link href="/entrar" class="font-medium text-store-accent hover:underline">Entre na sua conta</Link> para vê-los em qualquer lugar.
            </p>

            <p v-if="products.data.length === 0" class="py-16 text-center text-store-fg-muted">
                Você ainda não favoritou nenhum produto.
                <Link href="/#produtos" class="mt-3 block font-medium text-store-accent hover:underline">Ver catálogo</Link>
            </p>

            <div v-else class="mt-8 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-5">
                <ProductCard v-for="product in products.data" :key="product.id" :product="product"
                    :is-favorite="isFavorite(product.id)" :is-authenticated="!visitante"
                    :can-review="canReview(product.id)" :has-reviewed="hasReviewed(product.id)" />
            </div>

            <nav v-if="products.last_page > 1" class="mt-10 flex flex-wrap justify-center gap-2">
                <template v-for="link in products.links" :key="link.label">
                    <Link v-if="link.url" :href="link.url" preserve-state
                        class="rounded-lg px-3 py-1.5 text-sm"
                        :class="link.active ? 'bg-store-accent text-store-accent-contrast' : 'border border-store-border-strong text-store-fg hover:border-store-fg'"
                        v-html="link.label" />
                    <span v-else class="rounded-lg px-3 py-1.5 text-sm text-store-fg-faint" v-html="link.label" />
                </template>
            </nav>
        </section>
    </AppLayout>
</template>
