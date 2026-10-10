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
    reviewableProductIds: {
        type: Array,
        default: () => [],
    },
    reviewedProductIds: {
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
const canReview = (productId) => props.reviewableProductIds.includes(productId);
const hasReviewed = (productId) => props.reviewedProductIds.includes(productId);

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

const BENEFICIOS = [
    { icone: 'fa-truck-fast', titulo: 'Frete Grátis', texto: 'Entrega em todo Brasil' },
    { icone: 'fa-credit-card', titulo: 'Parcelamento', texto: 'Em 12x nos cartões' },
    { icone: 'fa-lock', titulo: 'Compra Segura', texto: 'Ambiente seguro para pagamentos online' },
    { icone: 'fa-face-smile', titulo: 'Satisfação Garantida', texto: 'Você 100% feliz ou seu reembolso garantido' },
];
</script>

<template>
    <Head :title="filters.search ? `Busca: ${filters.search}` : 'KazaKora — eletrônicos, gadgets e cozinha'" />

    <AppLayout>
        <!-- Banner rotativo -->
        <BannerCarousel v-if="banners.length" :banners="banners" reserva-base />

        <!-- Benefícios (pedido 2026-10-10, modelo izeshop): metade em cima do
             banner, metade abaixo da linha que separa o banner do resto. -->
        <section class="relative z-10 mx-auto max-w-[1320px] px-4 md:px-6" :class="banners.length ? '-mt-12 mb-8 md:-mt-11' : 'my-6'">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
                <div v-for="beneficio in BENEFICIOS" :key="beneficio.titulo"
                    class="flex items-center gap-3 rounded-xl border border-store-border bg-store-bg-raised px-3 py-3 shadow-[0_8px_24px_var(--store-shadow)] md:px-5 md:py-4">
                    <i class="fa-solid shrink-0 text-2xl text-store-accent md:text-3xl" :class="beneficio.icone"></i>
                    <div class="min-w-0">
                        <h3 class="text-sm font-bold leading-tight md:text-base">{{ beneficio.titulo }}</h3>
                        <p class="mt-0.5 text-xs leading-snug text-store-fg-muted md:text-sm">{{ beneficio.texto }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Destaques -->
        <section v-if="featuredProducts.length" class="mx-auto max-w-[1320px] px-4 pb-12 md:px-6">
            <div class="mb-5 flex items-end justify-between gap-4">
                <div>
                    <p class="font-store-mono text-[0.68rem] uppercase tracking-[0.22em] text-store-accent">seleção KazaKora</p>
                    <h2 class="mt-1 font-display text-3xl font-semibold">Destaques</h2>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5 lg:gap-6">
                <ProductCard v-for="product in featuredProducts" :key="product.id" :product="product"
                    :is-favorite="isFavorite(product.id)" :is-authenticated="isAuthenticated"
                    :can-review="canReview(product.id)" :has-reviewed="hasReviewed(product.id)" />
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
        <section v-if="categories.length" id="categorias" class="mx-auto max-w-[1320px] px-4 pb-14 md:px-6">
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

            <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5 lg:gap-6">
                <ProductCard v-for="product in products.data" :key="product.id" :product="product"
                    :is-favorite="isFavorite(product.id)" :is-authenticated="isAuthenticated"
                    :can-review="canReview(product.id)" :has-reviewed="hasReviewed(product.id)" />
            </div>

            <nav v-if="products.last_page > 1" class="mt-10 flex flex-wrap justify-center gap-2">
                <template v-for="link in products.links" :key="link.label">
                    <Link v-if="link.url" :href="link.url" preserve-state
                        class="rounded-full px-3 py-1.5 text-sm"
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
