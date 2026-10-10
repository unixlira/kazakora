<script setup>
// Seção de produtos da home (pedido 2026-10-10): título, link "Ver todos"
// e grade de cards — 2 por linha no celular, 4 no computador.
import ProductCard from '@/Shared/Components/ProductCard.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    titulo: { type: String, required: true },
    subtitulo: { type: String, default: null },
    link: { type: String, default: null },
    produtos: { type: Array, default: () => [] },
    favoriteIds: { type: Array, default: () => [] },
    reviewableProductIds: { type: Array, default: () => [] },
    reviewedProductIds: { type: Array, default: () => [] },
    // 5 colunas no computador (Ofertas do dia têm 5 produtos).
    cinco: { type: Boolean, default: false },
});

const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);
</script>

<template>
    <section v-if="produtos.length" class="mx-auto max-w-[1320px] px-4 py-10 md:px-6">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-2xl font-bold md:text-3xl">{{ titulo }}</h2>
                <p v-if="subtitulo" class="mt-1 text-sm text-store-fg-muted">{{ subtitulo }}</p>
            </div>
            <slot name="acao">
                <Link v-if="link" :href="link" class="text-sm font-semibold text-store-accent hover:underline">
                    Ver todos <i class="fas fa-arrow-right ml-1 text-xs"></i>
                </Link>
            </slot>
        </div>
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4 lg:gap-6" :class="{ 'lg:grid-cols-5': cinco }">
            <ProductCard v-for="product in produtos" :key="product.id" :product="product"
                :is-favorite="favoriteIds.includes(product.id)" :is-authenticated="isAuthenticated"
                :can-review="reviewableProductIds.includes(product.id)" :has-reviewed="reviewedProductIds.includes(product.id)" />
        </div>
    </section>
</template>
