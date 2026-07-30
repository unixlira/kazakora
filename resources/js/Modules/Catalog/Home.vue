<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import BannerCarousel from '@/Shared/Components/BannerCarousel.vue';
import CategoryCarousel from '@/Shared/Components/CategoryCarousel.vue';
import ProductCard from '@/Shared/Components/ProductCard.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    banners: {
        type: Array,
        default: () => [],
    },
    featuredProducts: {
        type: Array,
        default: () => [],
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

const isFavorite = (productId) => props.favoriteIds.includes(productId);

const TIPO_TABS = [
    { key: null, label: 'Todos' },
    { key: 'destaque', label: 'Destaques' },
    { key: 'lancamento', label: 'Lançamentos' },
];

const listTitle = computed(() => {
    if (props.filters.search) return `Resultados para "${props.filters.search}"`;
    if (props.filters.tipo === 'destaque') return 'Destaques';
    if (props.filters.tipo === 'lancamento') return 'Lançamentos';
    return 'Catálogo';
});

const tabHref = (tipo) => (tipo ? `/?tipo=${tipo}#produtos` : '/#produtos');
</script>

<template>
    <Head :title="filters.search ? `Busca: ${filters.search}` : 'KazaKora — eletrônicos, gadgets e cozinha'" />

    <AppLayout>
        <!-- Banner rotativo -->
        <BannerCarousel v-if="banners.length" :banners="banners" class="mb-[10px]" />

        <!-- Destaques -->
        <section v-if="featuredProducts.length" class="mx-auto max-w-[1320px] px-4 pb-16 md:px-6">
            <h2 class="mb-6 font-display text-3xl font-semibold">Destaques</h2>

            <div class="grid grid-cols-2 gap-6 sm:grid-cols-3 lg:grid-cols-5">
                <ProductCard v-for="product in featuredProducts" :key="product.id" :product="product"
                    :is-favorite="isFavorite(product.id)" :is-authenticated="isAuthenticated" />
            </div>

            <div class="mt-8 flex justify-center">
                <Link href="/?tipo=destaque#produtos"
                    class="inline-flex items-center gap-2 rounded-lg bg-store-accent px-6 py-3 text-sm font-semibold text-store-accent-contrast transition-colors hover:opacity-90">
                    Ver mais
                    <i class="fas fa-arrow-right text-xs"></i>
                </Link>
            </div>
        </section>

        <!-- Categories -->
        <section v-if="categories.length" id="categorias" class="mx-auto max-w-[1320px] px-4 pb-16 md:px-6">
            <CategoryCarousel :categories="categories" />
        </section>

        <!-- Product grid -->
        <section id="produtos" class="mx-auto max-w-[1320px] px-4 pb-20 md:px-6">
            <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="font-display text-3xl font-semibold">{{ listTitle }}</h2>
                    <p class="mt-2 text-store-fg-muted">Eletrônicos, gadgets e utensílios de cozinha selecionados pela curadoria KazaKora.</p>
                </div>

                <div v-if="!filters.search" class="flex flex-wrap gap-2">
                    <Link v-for="tab in TIPO_TABS" :key="tab.label" :href="tabHref(tab.key)" preserve-scroll
                        class="rounded-full px-4 py-1.5 text-sm font-medium no-underline transition-colors"
                        :class="(filters.tipo ?? null) === tab.key
                            ? 'bg-store-accent text-store-accent-contrast'
                            : 'border border-store-border-strong text-store-fg-muted hover:border-store-fg'">
                        {{ tab.label }}
                    </Link>
                </div>
            </div>

            <p v-if="products.data.length === 0" class="py-16 text-center text-store-fg-muted">
                Nenhum produto encontrado.
            </p>

            <div v-else class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <ProductCard v-for="product in products.data" :key="product.id" :product="product"
                    :is-favorite="isFavorite(product.id)" :is-authenticated="isAuthenticated" />
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
