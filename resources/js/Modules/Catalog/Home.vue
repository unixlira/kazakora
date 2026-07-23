<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    featured: {
        type: Object,
        default: null,
    },
    products: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        default: () => [],
    },
    favoriteIds: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const addToCart = (productId) => {
    router.post('/carrinho', { product_id: productId, quantity: 1 }, { preserveScroll: true });
};

const toggleFavorite = (productId) => {
    router.post(`/favoritos/${productId}`, {}, { preserveScroll: true });
};

const isFavorite = (productId) => props.favoriteIds.includes(productId);

const primaryImage = (product) => {
    const image = product.images?.find((img) => img.is_primary) ?? product.images?.[0];
    return image?.url ?? null;
};

const specLine = (product) => {
    const parts = [product.brand, product.model, product.color].filter(Boolean);
    return parts.length ? parts.join(' · ') : (product.category?.name ?? null);
};

const CATEGORY_ICONS = ['fa-box-open', 'fa-tags', 'fa-star'];
</script>

<template>
    <Head :title="filters.search ? `Busca: ${filters.search}` : 'KazaKora — eletrônicos, gadgets e cozinha'" />

    <AppLayout>
        <!-- Hero -->
        <section v-if="featured" class="mx-auto max-w-[1320px] px-4 pb-16 pt-12 md:px-6 md:pt-16">
            <div class="grid items-center gap-12 lg:grid-cols-[1.05fr_0.95fr]">
                <div>
                    <p class="font-store-mono text-xs uppercase tracking-widest text-store-accent">Recém-chegado</p>
                    <h1 class="mt-3 font-display text-4xl font-semibold leading-[1.05] text-balance md:text-5xl">
                        {{ featured.name }}
                    </h1>
                    <p v-if="featured.description" class="mt-4 max-w-[46ch] text-store-fg-muted">
                        {{ featured.description }}
                    </p>
                    <p v-if="specLine(featured)" class="font-store-mono mt-5 text-sm text-store-fg-muted">
                        {{ specLine(featured) }}
                    </p>

                    <div class="mt-6 flex items-baseline gap-3">
                        <span class="text-3xl font-semibold">{{ formatPrice(featured.price) }}</span>
                        <span v-if="featured.stock > 0" class="text-sm text-store-fg-muted">em estoque</span>
                        <span v-else class="text-sm text-red-600">esgotado</span>
                    </div>

                    <div class="mt-7 flex flex-wrap gap-3">
                        <button type="button" :disabled="featured.stock < 1"
                            class="inline-flex items-center gap-2 rounded-lg bg-store-accent px-6 py-3 text-sm font-semibold text-store-accent-contrast transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="addToCart(featured.id)">
                            Adicionar ao carrinho
                            <i class="fas fa-arrow-right text-xs"></i>
                        </button>
                        <a href="#produtos" class="inline-flex items-center rounded-lg border border-store-border-strong px-6 py-3 text-sm font-semibold hover:border-store-fg">
                            Ver catálogo
                        </a>
                    </div>
                </div>

                <div class="relative aspect-square rounded-[20px] border border-store-border" style="background: radial-gradient(120% 120% at 30% 20%, color-mix(in oklab, var(--color-store-accent) 18%, var(--color-store-bg-raised)), var(--color-store-bg-raised) 65%);">
                    <img v-if="primaryImage(featured)" :src="primaryImage(featured)" :alt="featured.name" class="h-full w-full rounded-[20px] object-cover">
                    <div v-else class="flex h-full w-full items-center justify-center">
                        <i class="fas fa-box-open text-7xl text-store-accent-strong opacity-40"></i>
                    </div>
                    <button type="button"
                        class="absolute right-4 top-4 flex h-10 w-10 items-center justify-center rounded-full bg-store-bg-raised shadow"
                        :aria-pressed="isFavorite(featured.id)" @click="toggleFavorite(featured.id)">
                        <i class="text-base" :class="isFavorite(featured.id) ? 'fas fa-heart text-store-accent' : 'far fa-heart text-store-fg-muted'"></i>
                    </button>
                </div>
            </div>
        </section>

        <!-- Categories -->
        <section v-if="categories.length" id="categorias" class="mx-auto max-w-[1320px] px-4 pb-16 md:px-6">
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <a v-for="(category, i) in categories" :key="category.id" href="#produtos"
                    class="flex min-h-[170px] flex-col justify-between rounded-2xl border border-store-border bg-store-bg-raised p-6 no-underline text-store-fg transition-transform hover:-translate-y-0.5">
                    <i class="fas text-2xl text-store-accent" :class="CATEGORY_ICONS[i % CATEGORY_ICONS.length]"></i>
                    <div>
                        <h3 class="font-display text-xl font-semibold">{{ category.name }}</h3>
                        <div class="mt-3 flex items-center justify-between">
                            <span class="text-sm text-store-fg-muted">{{ category.products_count }} produto(s)</span>
                            <span class="font-store-mono text-sm text-store-accent">→</span>
                        </div>
                    </div>
                </a>
            </div>
        </section>

        <!-- Product grid -->
        <section id="produtos" class="mx-auto max-w-[1320px] px-4 pb-20 md:px-6">
            <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="font-display text-3xl font-semibold">
                        {{ filters.search ? `Resultados para "${filters.search}"` : 'Catálogo' }}
                    </h2>
                    <p class="mt-2 text-store-fg-muted">Eletrônicos, gadgets e utensílios de cozinha selecionados pela curadoria KazaKora.</p>
                </div>
            </div>

            <p v-if="products.data.length === 0" class="py-16 text-center text-store-fg-muted">
                Nenhum produto encontrado.
            </p>

            <div v-else class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <article v-for="product in products.data" :key="product.id"
                    class="flex flex-col overflow-hidden rounded-2xl border border-store-border bg-store-bg-raised transition-shadow hover:shadow-lg">
                    <div class="relative aspect-[4/3.3]" style="background: radial-gradient(120% 120% at 25% 15%, color-mix(in oklab, var(--color-store-accent) 14%, var(--color-store-bg-raised)), var(--color-store-bg-sunken) 70%);">
                        <img v-if="primaryImage(product)" :src="primaryImage(product)" :alt="product.name" class="h-full w-full object-cover">
                        <div v-else class="flex h-full w-full items-center justify-center">
                            <i class="fas fa-box-open text-4xl text-store-accent-strong opacity-40"></i>
                        </div>
                        <button v-if="isAuthenticated" type="button"
                            class="absolute right-2.5 top-2.5 flex h-8 w-8 items-center justify-center rounded-full bg-store-bg-raised shadow"
                            :aria-pressed="isFavorite(product.id)" @click="toggleFavorite(product.id)">
                            <i class="text-sm" :class="isFavorite(product.id) ? 'fas fa-heart text-store-accent' : 'far fa-heart text-store-fg-muted'"></i>
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

        <!-- Manifesto -->
        <section class="bg-store-accent-strong py-16 text-store-accent-contrast">
            <div class="mx-auto max-w-3xl px-4 md:px-6">
                <blockquote class="font-display text-balance text-2xl font-semibold leading-snug md:text-3xl">
                    "Escolhemos cada produto do nosso catálogo com atenção — eletrônicos, gadgets e utensílios que valem o espaço na sua casa."
                </blockquote>
                <p class="font-store-mono mt-6 text-xs uppercase tracking-wider opacity-75">— Curadoria KazaKora</p>
            </div>
        </section>
    </AppLayout>
</template>
