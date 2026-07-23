<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { addToCart, formatPrice, primaryImage, specLine, toggleFavorite } from '@/Shared/productCard';

defineProps({
    products: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Head title="Meus Favoritos" />

    <AppLayout>
        <section class="mx-auto max-w-[1320px] px-4 py-12 md:px-6">
            <h1 class="font-display text-3xl font-semibold">Meus Favoritos</h1>
            <p class="mt-2 text-store-fg-muted">Produtos que você marcou pra não perder de vista.</p>

            <p v-if="products.data.length === 0" class="py-16 text-center text-store-fg-muted">
                Você ainda não favoritou nenhum produto.
                <Link href="/#produtos" class="mt-3 block font-medium text-store-accent hover:underline">Ver catálogo</Link>
            </p>

            <div v-else class="mt-8 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <article v-for="product in products.data" :key="product.id"
                    class="flex flex-col overflow-hidden rounded-2xl border border-store-border bg-store-bg-raised transition-shadow hover:shadow-lg">
                    <div class="relative aspect-[4/3.3]" style="background: radial-gradient(120% 120% at 25% 15%, color-mix(in oklab, var(--color-store-accent) 14%, var(--color-store-bg-raised)), var(--color-store-bg-sunken) 70%);">
                        <img v-if="primaryImage(product)" :src="primaryImage(product)" :alt="product.name" class="h-full w-full object-cover">
                        <div v-else class="flex h-full w-full items-center justify-center">
                            <i class="fas fa-box-open text-4xl text-store-accent-strong opacity-40"></i>
                        </div>
                        <button type="button"
                            class="absolute right-2.5 top-2.5 flex h-8 w-8 items-center justify-center rounded-full bg-store-bg-raised shadow"
                            aria-pressed="true" aria-label="Remover dos favoritos" @click="toggleFavorite(product.id)">
                            <i class="fas fa-heart text-sm text-store-accent"></i>
                        </button>
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
