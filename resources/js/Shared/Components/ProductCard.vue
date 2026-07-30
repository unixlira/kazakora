<script setup>
import { Link } from '@inertiajs/vue3';
import { addToCart, formatPrice, primaryImage, specLine, toggleFavorite } from '@/Shared/productCard';

defineProps({
    product: {
        type: Object,
        required: true,
    },
    isFavorite: {
        type: Boolean,
        default: false,
    },
    isAuthenticated: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <article class="flex flex-col overflow-hidden rounded-2xl border border-store-border bg-store-bg-raised transition-shadow hover:shadow-lg">
        <div class="relative aspect-[4/3.3]" style="background: radial-gradient(120% 120% at 25% 15%, color-mix(in oklab, var(--color-store-accent) 14%, var(--color-store-bg-raised)), var(--color-store-bg-sunken) 70%);">
            <img v-if="primaryImage(product)" :src="primaryImage(product)" :alt="product.name" class="h-full w-full object-cover">
            <div v-else class="flex h-full w-full items-center justify-center">
                <i class="fas fa-box-open text-4xl text-store-accent-strong opacity-40"></i>
            </div>
            <button v-if="isAuthenticated" type="button"
                class="absolute right-2.5 top-2.5 flex h-8 w-8 items-center justify-center rounded-full bg-store-bg-raised shadow"
                :aria-pressed="isFavorite" @click="toggleFavorite(product.id)">
                <i class="text-sm" :class="isFavorite ? 'fas fa-heart text-store-accent' : 'far fa-heart text-store-fg-muted'"></i>
            </button>
            <Link v-else href="/entrar"
                class="absolute right-2.5 top-2.5 flex h-8 w-8 items-center justify-center rounded-full bg-store-bg-raised shadow">
                <i class="far fa-heart text-sm text-store-fg-muted"></i>
            </Link>
        </div>
        <div class="flex flex-1 flex-col gap-1.5 p-4">
            <span v-if="product.category" class="font-store-mono text-[0.68rem] uppercase tracking-wide text-store-fg-faint">{{ product.category.name }}</span>
            <h4 class="font-medium leading-snug">{{ product.name }}</h4>
            <p v-if="specLine(product)" class="font-store-mono text-xs text-store-fg-muted">{{ specLine(product) }}</p>
            <div class="mt-auto flex items-center justify-between pt-2">
                <div>
                    <span class="text-lg font-semibold">{{ formatPrice(product.price) }}</span>
                    <span v-if="product.stock <= 0" class="mt-0.5 block text-xs text-red-600">Esgotado</span>
                </div>
                <button type="button" :disabled="product.stock < 1"
                    class="flex h-9 w-9 items-center justify-center rounded-full border border-store-border-strong transition-colors hover:bg-store-accent hover:text-store-accent-contrast disabled:cursor-not-allowed disabled:opacity-40"
                    aria-label="Adicionar ao carrinho" @click="addToCart(product.id)">
                    <i class="fas fa-plus text-sm"></i>
                </button>
            </div>
        </div>
    </article>
</template>
