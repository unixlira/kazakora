<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import Modal from '@/Shared/Modal.vue';
import ProductCard from '@/Shared/Components/ProductCard.vue';
import { formatPrice, toggleFavorite } from '@/Shared/productCard';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    product: { type: Object, required: true },
    reviews: { type: Array, default: () => [] },
    shippingMethods: { type: Array, default: () => [] },
    isFavorite: { type: Boolean, default: false },
    canReview: { type: Boolean, default: false },
    hasReviewed: { type: Boolean, default: false },
    relatedProducts: { type: Array, default: () => [] },
    relatedFavoriteIds: { type: Array, default: () => [] },
    relatedReviewableIds: { type: Array, default: () => [] },
    relatedReviewedIds: { type: Array, default: () => [] },
});

const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);

const ratingAvg = computed(() => Number(props.product.reviews_avg_rating ?? 0));
const reviewsCount = computed(() => Number(props.product.reviews_count ?? props.reviews.length));
const filledStars = computed(() => Math.round(ratingAvg.value));

// Galeria: vídeo primeiro (mesmo tamanho das imagens), depois as imagens em ordem.
const mediaItems = computed(() => {
    const items = [];
    if (props.product.video_url) {
        items.push({ type: 'video', src: props.product.video_url });
    }
    for (const image of props.product.images ?? []) {
        items.push({ type: 'image', src: image.url });
    }
    return items;
});

const activeIndex = ref(0);
const activeMedia = computed(() => mediaItems.value[activeIndex.value] ?? null);

const goPrev = () => {
    if (mediaItems.value.length < 2) return;
    activeIndex.value = (activeIndex.value - 1 + mediaItems.value.length) % mediaItems.value.length;
};
const goNext = () => {
    if (mediaItems.value.length < 2) return;
    activeIndex.value = (activeIndex.value + 1) % mediaItems.value.length;
};

// Navegação por arrastar o dedo (mobile), tanto na galeria embutida quanto no lightbox.
const touchStartX = ref(null);
const onTouchStart = (event) => {
    touchStartX.value = event.touches[0]?.clientX ?? null;
};
const onTouchEnd = (event) => {
    if (touchStartX.value === null) return;
    const deltaX = (event.changedTouches[0]?.clientX ?? touchStartX.value) - touchStartX.value;
    if (Math.abs(deltaX) > 40) {
        deltaX > 0 ? goPrev() : goNext();
    }
    touchStartX.value = null;
};

const onGalleryClick = () => {
    if (activeMedia.value?.type === 'image') isLightboxOpen.value = true;
};

const isLightboxOpen = ref(false);

watch(isLightboxOpen, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});

const onKeydown = (event) => {
    const tag = event.target?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA') return;
    if (event.key === 'ArrowLeft') goPrev();
    if (event.key === 'ArrowRight') goNext();
    if (event.key === 'Escape' && isLightboxOpen.value) isLightboxOpen.value = false;
};

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});

const discountPercent = computed(() => {
    if (props.product.discount_percentage) return Math.round(Number(props.product.discount_percentage));
    if (props.product.discount_amount && props.product.price > 0) {
        return Math.round((Number(props.product.discount_amount) / Number(props.product.price)) * 100);
    }
    return 0;
});

const specLine = computed(() => {
    const parts = [props.product.brand, props.product.model, props.product.color].filter(Boolean);
    return parts.length ? parts.join(' · ') : null;
});

// Características: resumo curto na lateral (estilo Mercado Livre) + tabela completa mais abaixo.
const keySpecs = computed(() => {
    const specs = [];
    if (props.product.brand) specs.push({ label: 'Marca', value: props.product.brand });
    if (props.product.model) specs.push({ label: 'Modelo', value: props.product.model });
    if (props.product.color) specs.push({ label: 'Cor', value: props.product.color });
    if (props.product.variation) specs.push({ label: 'Variação', value: props.product.variation });
    if (props.product.category) specs.push({ label: 'Categoria', value: props.product.category.name });
    return specs;
});

const fullSpecs = computed(() => {
    const specs = [...keySpecs.value];
    if (props.product.sku) specs.push({ label: 'SKU', value: props.product.sku });
    specs.push({ label: 'Estoque disponível', value: `${props.product.stock} unidade${props.product.stock === 1 ? '' : 's'}` });
    return specs;
});

const scrollToSpecs = () => {
    document.getElementById('caracteristicas')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

// Quebra a descrição livre em blocos: parágrafos normais, ou um cabeçalho
// ("Principais benefícios:") seguido de uma lista com marcadores ("- item").
const descriptionBlocks = computed(() => {
    if (!props.product.description) return [];

    return props.product.description
        .split(/\n\s*\n/)
        .map((block) => block.trim())
        .filter(Boolean)
        .map((block) => {
            const lines = block.split('\n').map((line) => line.trim()).filter(Boolean);
            const heading = lines.length > 1 && lines[0].endsWith(':') ? lines.shift() : null;
            const isList = lines.length > 0 && lines.every((line) => /^[-•]/.test(line));

            return {
                heading,
                isList,
                items: isList ? lines.map((line) => line.replace(/^[-•]\s*/, '')) : null,
                text: isList ? null : lines.join(' '),
            };
        });
});

const BLOCK_STYLES = [
    { icon: 'bg-store-accent/10 text-store-accent', heading: 'text-store-accent' },
    { icon: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/50 dark:text-emerald-300', heading: 'text-emerald-600 dark:text-emerald-300' },
    { icon: 'bg-sky-100 text-sky-600 dark:bg-sky-900/50 dark:text-sky-300', heading: 'text-sky-600 dark:text-sky-300' },
    { icon: 'bg-violet-100 text-violet-600 dark:bg-violet-900/50 dark:text-violet-300', heading: 'text-violet-600 dark:text-violet-300' },
];
const blockStyle = (index) => BLOCK_STYLES[index % BLOCK_STYLES.length];

const isFavoriteRelated = (productId) => props.relatedFavoriteIds.includes(productId);
const canReviewRelated = (productId) => props.relatedReviewableIds.includes(productId);
const hasReviewedRelated = (productId) => props.relatedReviewedIds.includes(productId);

const quantity = ref(1);
const addingToCart = ref(false);

const addToCart = () => {
    addingToCart.value = true;
    router.post('/carrinho', { product_id: props.product.id, quantity: quantity.value }, {
        preserveScroll: true,
        onFinish: () => { addingToCart.value = false; },
    });
};

const showReviewModal = ref(false);
const reviewForm = useForm({ rating: 5, comment: '' });

const submitReview = () => {
    reviewForm.post(`/produtos/${props.product.id}/avaliacoes`, {
        preserveScroll: true,
        onSuccess: () => {
            showReviewModal.value = false;
            reviewForm.reset();
        },
    });
};

const formatDate = (value) => new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium' }).format(new Date(value));
</script>

<template>
    <Head :title="product.name" />

    <AppLayout>
        <div class="mx-auto max-w-[1320px] px-4 py-8 md:px-6 md:py-12">
            <!-- Breadcrumb -->
            <nav class="mb-6 flex flex-wrap items-center gap-2 text-sm text-store-fg-muted">
                <Link href="/" class="hover:text-store-accent">Início</Link>
                <i class="fas fa-chevron-right text-[10px]"></i>
                <Link v-if="product.category" :href="`/?search=${encodeURIComponent(product.category.name)}`" class="hover:text-store-accent">
                    {{ product.category.name }}
                </Link>
                <i v-if="product.category" class="fas fa-chevron-right text-[10px]"></i>
                <span class="truncate text-store-fg">{{ product.name }}</span>
            </nav>

            <div class="grid grid-cols-1 gap-10 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)]">
                <!-- Galeria: vídeo (se houver) + imagens, miniaturas verticais à esquerda no desktop -->
                <div class="mx-auto w-full md:max-w-[420px] lg:max-w-[460px]">
                    <div class="flex flex-col-reverse gap-3 lg:flex-row lg:gap-3">
                        <!-- Miniaturas -->
                        <div v-if="mediaItems.length > 1"
                            class="flex gap-2 overflow-x-auto pb-1 lg:w-14 lg:shrink-0 lg:flex-col lg:overflow-x-visible lg:overflow-y-auto lg:pb-0">
                            <button v-for="(item, index) in mediaItems" :key="index" type="button"
                                class="relative aspect-square w-12 shrink-0 overflow-hidden rounded-xl border-2 bg-store-bg-sunken transition-colors lg:w-full"
                                :class="index === activeIndex ? 'border-store-accent' : 'border-store-border hover:border-store-border-strong'"
                                @click="activeIndex = index">
                                <template v-if="item.type === 'video'">
                                    <video :src="item.src" class="h-full w-full object-cover"></video>
                                    <span class="absolute inset-0 flex items-center justify-center bg-black/30">
                                        <i class="fas fa-play text-sm text-white"></i>
                                    </span>
                                </template>
                                <img v-else :src="item.src" :alt="product.name" class="h-full w-full object-cover">
                            </button>
                        </div>

                        <!-- Imagem/vídeo principal -->
                        <div class="group relative aspect-square flex-1 select-none overflow-hidden rounded-2xl border border-store-border bg-store-bg-sunken"
                            :class="activeMedia?.type === 'image' ? 'cursor-zoom-in' : ''"
                            @click="onGalleryClick" @touchstart="onTouchStart" @touchend="onTouchEnd">
                            <template v-if="activeMedia?.type === 'video'">
                                <video :src="activeMedia.src" controls class="h-full w-full object-contain bg-black" @click.stop></video>
                            </template>
                            <template v-else-if="activeMedia?.type === 'image'">
                                <img :src="activeMedia.src" :alt="product.name"
                                    class="h-full w-full object-cover transition-transform duration-300 ease-out group-hover:scale-110">
                            </template>
                            <div v-else class="flex h-full w-full items-center justify-center">
                                <i class="fas fa-box-open text-6xl text-store-accent-strong opacity-30"></i>
                            </div>

                            <span v-if="discountPercent > 0"
                                class="absolute left-3 top-3 rounded-full bg-emerald-600 px-3 py-1 text-xs font-bold text-white shadow">
                                -{{ discountPercent }}%
                            </span>

                            <button v-if="isAuthenticated" type="button"
                                class="absolute right-3 top-3 flex h-9 w-9 items-center justify-center rounded-full bg-store-bg-raised shadow"
                                :aria-pressed="isFavorite" @click.stop="toggleFavorite(product.id)">
                                <i class="text-base" :class="isFavorite ? 'fas fa-heart text-store-accent' : 'far fa-heart text-store-fg-muted'"></i>
                            </button>
                            <Link v-else href="/entrar" @click.stop
                                class="absolute right-3 top-3 flex h-9 w-9 items-center justify-center rounded-full bg-store-bg-raised shadow">
                                <i class="far fa-heart text-base text-store-fg-muted"></i>
                            </Link>

                            <!-- Setas de navegação -->
                            <template v-if="mediaItems.length > 1">
                                <button type="button"
                                    class="absolute left-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full bg-store-bg-raised/90 text-store-fg shadow transition-opacity hover:bg-store-bg-raised md:opacity-0 md:group-hover:opacity-100"
                                    aria-label="Mídia anterior" @click.stop="goPrev">
                                    <i class="fas fa-chevron-left text-xs"></i>
                                </button>
                                <button type="button"
                                    class="absolute right-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full bg-store-bg-raised/90 text-store-fg shadow transition-opacity hover:bg-store-bg-raised md:opacity-0 md:group-hover:opacity-100"
                                    aria-label="Próxima mídia" @click.stop="goNext">
                                    <i class="fas fa-chevron-right text-xs"></i>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Informações -->
                <div class="flex flex-col gap-5">
                    <div>
                        <p v-if="product.category" class="font-store-mono text-xs uppercase tracking-wider text-store-fg-muted">
                            {{ product.category.name }}
                        </p>
                        <h1 class="mt-1 font-display text-3xl font-semibold leading-tight text-store-fg md:text-4xl">{{ product.name }}</h1>
                        <p v-if="specLine" class="mt-2 font-store-mono text-sm text-store-fg-muted">{{ specLine }}</p>

                        <div class="mt-3 flex items-center gap-2">
                            <div class="flex items-center gap-0.5">
                                <i v-for="star in 5" :key="star" class="text-sm"
                                    :class="star <= filledStars ? 'fas fa-star text-amber-400' : 'far fa-star text-store-fg-faint'"></i>
                            </div>
                            <span class="text-sm text-store-fg-muted">
                                {{ ratingAvg > 0 ? ratingAvg.toFixed(1) : 'Sem avaliações' }}
                                <span v-if="reviewsCount">({{ reviewsCount }} avaliação{{ reviewsCount === 1 ? '' : 'ões' }})</span>
                            </span>
                        </div>
                    </div>

                    <!-- Características principais -->
                    <div v-if="keySpecs.length" class="rounded-2xl border border-store-border bg-store-bg-raised p-5">
                        <h3 class="text-sm font-semibold text-store-fg">Características principais</h3>
                        <ul class="mt-3 flex flex-col gap-1.5">
                            <li v-for="spec in keySpecs.slice(0, 4)" :key="spec.label" class="flex items-center justify-between gap-4 text-sm">
                                <span class="text-store-fg-muted">{{ spec.label }}</span>
                                <span class="truncate font-medium text-store-fg">{{ spec.value }}</span>
                            </li>
                        </ul>
                        <button type="button" class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-store-accent hover:underline"
                            @click="scrollToSpecs">
                            Ver características completas
                            <i class="fas fa-arrow-down text-[10px]"></i>
                        </button>
                    </div>

                    <!-- Flags coloridas -->
                    <div class="flex flex-wrap gap-2">
                        <span v-if="product.is_featured" class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-900/50 dark:text-amber-300">
                            <i class="fas fa-star text-[10px]"></i> Destaque
                        </span>
                        <span v-if="product.is_new_release" class="inline-flex items-center gap-1.5 rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold text-violet-700 dark:bg-violet-900/50 dark:text-violet-300">
                            <i class="fas fa-sparkles text-[10px]"></i> Lançamento
                        </span>
                        <span v-if="discountPercent > 0" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300">
                            <i class="fas fa-tag text-[10px]"></i> {{ discountPercent }}% OFF
                        </span>
                        <span v-if="product.stock > 0" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300">
                            <i class="fas fa-circle-check text-[10px]"></i> Em estoque
                        </span>
                        <span v-else class="inline-flex items-center gap-1.5 rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700 dark:bg-red-900/50 dark:text-red-300">
                            <i class="fas fa-circle-xmark text-[10px]"></i> Esgotado
                        </span>
                    </div>

                    <!-- Preço -->
                    <div class="rounded-2xl border border-store-border bg-store-bg-raised p-5">
                        <span v-if="product.has_discount" class="block text-sm text-store-fg-faint line-through decoration-1">
                            {{ formatPrice(product.price) }}
                        </span>
                        <div class="flex items-baseline gap-3">
                            <span class="font-display text-3xl font-bold" :class="product.has_discount ? 'text-store-accent' : 'text-store-fg'">
                                {{ formatPrice(product.final_price) }}
                            </span>
                            <span v-if="discountPercent > 0" class="rounded-md bg-emerald-100 px-2 py-0.5 text-sm font-bold text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300">
                                -{{ discountPercent }}%
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-store-fg-muted">em até 12x no cartão · à vista com desconto no Pix</p>

                        <div class="mt-4 flex items-center gap-3">
                            <div class="flex items-center rounded-lg border border-store-border-strong">
                                <button type="button" class="flex h-10 w-10 items-center justify-center text-store-fg-muted hover:text-store-fg"
                                    :disabled="quantity <= 1" @click="quantity = Math.max(1, quantity - 1)">
                                    <i class="fas fa-minus text-xs"></i>
                                </button>
                                <span class="w-10 text-center text-sm font-semibold">{{ quantity }}</span>
                                <button type="button" class="flex h-10 w-10 items-center justify-center text-store-fg-muted hover:text-store-fg"
                                    :disabled="quantity >= product.stock" @click="quantity = Math.min(product.stock, quantity + 1)">
                                    <i class="fas fa-plus text-xs"></i>
                                </button>
                            </div>
                            <button type="button" :disabled="product.stock < 1 || addingToCart"
                                class="flex flex-1 items-center justify-center gap-2 rounded-lg bg-store-accent px-5 py-2.5 text-sm font-semibold text-store-accent-contrast transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
                                @click="addToCart">
                                <i class="fas fa-bag-shopping text-sm"></i>
                                Adicionar ao carrinho
                            </button>
                        </div>
                    </div>

                    <!-- Entrega -->
                    <div v-if="shippingMethods.length" class="rounded-2xl border border-store-border bg-store-bg-raised p-5">
                        <h3 class="flex items-center gap-2 text-sm font-semibold text-store-fg">
                            <i class="fas fa-truck-fast text-store-accent"></i> Opções de entrega
                        </h3>
                        <ul class="mt-3 flex flex-col gap-2">
                            <li v-for="method in shippingMethods" :key="method.id"
                                class="flex items-center justify-between text-sm text-store-fg-muted">
                                <span>{{ method.name }} · até {{ method.estimated_days }} dia{{ method.estimated_days === 1 ? '' : 's' }}</span>
                                <span class="font-semibold text-store-fg">{{ Number(method.price) > 0 ? formatPrice(method.price) : 'Grátis' }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Características: tabela completa -->
            <section v-if="fullSpecs.length" id="caracteristicas" class="mt-16 scroll-mt-24">
                <h2 class="mb-6 text-center font-display text-2xl font-semibold text-store-fg md:text-3xl">Características</h2>
                <div class="mx-auto max-w-3xl overflow-hidden rounded-2xl border border-store-border">
                    <div v-for="(spec, index) in fullSpecs" :key="spec.label"
                        class="flex items-center justify-between gap-4 px-5 py-3 text-sm"
                        :class="index % 2 === 0 ? 'bg-store-bg-raised' : 'bg-store-bg-sunken'">
                        <span class="text-store-fg-muted">{{ spec.label }}</span>
                        <span class="font-medium text-store-fg">{{ spec.value }}</span>
                    </div>
                </div>
            </section>

            <!-- Descrição: landing page centralizada -->
            <section v-if="product.description" class="mt-16 flex justify-center">
                <div class="relative w-full max-w-3xl overflow-hidden rounded-2xl border border-store-border bg-store-bg-raised p-8 shadow-sm md:p-12">
                    <div class="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-store-accent via-emerald-500 to-sky-500"></div>

                    <div class="flex flex-col items-center text-center">
                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-store-accent/10 text-store-accent">
                            <i class="fas fa-box-open text-lg"></i>
                        </span>
                        <h2 class="mt-4 font-display text-2xl font-semibold text-store-fg md:text-3xl">Sobre o produto</h2>
                    </div>

                    <div class="mx-auto mt-8 flex max-w-2xl flex-col gap-6 text-left">
                        <div v-for="(block, index) in descriptionBlocks" :key="index">
                            <h3 v-if="block.heading" class="mb-3 flex items-center gap-2.5 font-display text-lg font-semibold" :class="blockStyle(index).heading">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full" :class="blockStyle(index).icon">
                                    <i class="fas fa-sparkles text-xs"></i>
                                </span>
                                {{ block.heading }}
                            </h3>
                            <ul v-if="block.isList" class="flex flex-col gap-2">
                                <li v-for="(item, itemIndex) in block.items" :key="itemIndex"
                                    class="flex items-start gap-3 rounded-xl bg-store-bg-sunken/70 px-4 py-3">
                                    <i class="fas fa-circle-check mt-0.5 shrink-0 text-emerald-500"></i>
                                    <span class="text-sm leading-relaxed text-store-fg-muted md:text-base">{{ item }}</span>
                                </li>
                            </ul>
                            <p v-else class="text-sm leading-relaxed text-store-fg-muted md:text-base">{{ block.text }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Avaliações -->
            <section class="mx-auto mt-16 max-w-3xl">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <h2 class="font-display text-2xl font-semibold text-store-fg">
                        Avaliações {{ reviewsCount ? `(${reviewsCount})` : '' }}
                    </h2>
                    <button v-if="canReview && !hasReviewed" type="button"
                        class="rounded-lg bg-store-accent px-4 py-2 text-sm font-semibold text-store-accent-contrast hover:opacity-90"
                        @click="showReviewModal = true">
                        Avaliar produto
                    </button>
                </div>

                <p v-if="!reviews.length" class="mt-6 text-store-fg-muted">Este produto ainda não recebeu avaliações.</p>

                <div v-else class="mt-6 flex flex-col gap-4">
                    <article v-for="review in reviews" :key="review.id"
                        class="flex gap-4 rounded-2xl border border-store-border bg-store-bg-raised p-5 shadow-sm">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-store-accent/10 font-display text-sm font-semibold text-store-accent">
                            {{ (review.user?.name ?? 'C').charAt(0).toUpperCase() }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-medium text-store-fg">{{ review.user?.name ?? 'Cliente KazaKora' }}</span>
                                <span class="text-xs text-store-fg-faint">{{ formatDate(review.created_at) }}</span>
                            </div>
                            <div class="mt-1 flex items-center gap-0.5">
                                <i v-for="star in 5" :key="star" class="text-xs"
                                    :class="star <= review.rating ? 'fas fa-star text-amber-400' : 'far fa-star text-store-fg-faint'"></i>
                            </div>
                            <p v-if="review.comment" class="mt-2 text-sm leading-relaxed text-store-fg-muted">{{ review.comment }}</p>
                        </div>
                    </article>
                </div>
            </section>

            <hr class="my-16 border-store-border">

            <!-- Você também pode gostar de -->
            <section v-if="relatedProducts.length">
                <h2 class="mb-6 font-display text-2xl font-semibold text-store-fg md:text-3xl">Você também pode gostar de</h2>
                <div class="grid grid-cols-2 gap-6 sm:grid-cols-3 lg:grid-cols-5">
                    <ProductCard v-for="related in relatedProducts" :key="related.id" :product="related"
                        :is-favorite="isFavoriteRelated(related.id)" :is-authenticated="isAuthenticated"
                        :can-review="canReviewRelated(related.id)" :has-reviewed="hasReviewedRelated(related.id)" />
                </div>
            </section>
        </div>

        <Modal :open="showReviewModal" max-width="max-w-[480px]" @close="showReviewModal = false">
            <h3 class="font-display text-xl font-semibold">Avaliar {{ product.name }}</h3>
            <div class="mt-4 flex gap-1">
                <button v-for="star in 5" :key="star" type="button" @click="reviewForm.rating = star">
                    <i class="text-2xl" :class="star <= reviewForm.rating ? 'fas fa-star text-amber-400' : 'far fa-star text-store-fg-faint'"></i>
                </button>
            </div>
            <textarea v-model="reviewForm.comment" rows="3" placeholder="Conte como foi sua experiência (opcional)"
                class="mt-4 w-full rounded-lg border border-store-border-strong bg-store-bg px-3 py-2 text-sm"></textarea>
            <p v-if="reviewForm.errors.review" class="mt-2 text-sm text-red-600">{{ reviewForm.errors.review }}</p>
            <button type="button" :disabled="reviewForm.processing"
                class="mt-4 rounded-lg bg-store-accent px-5 py-2.5 text-sm font-semibold text-store-accent-contrast hover:opacity-90 disabled:opacity-50"
                @click="submitReview">
                Enviar avaliação
            </button>
        </Modal>

        <!-- Lightbox: imagem ampliada, fundo preto desfocado, navegação por seta/teclado/arraste -->
        <Teleport to="body">
            <div v-if="isLightboxOpen" class="fixed inset-0 z-[110] flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/90 backdrop-blur-md" @click="isLightboxOpen = false"></div>

                <button type="button"
                    class="absolute right-4 top-4 z-10 flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20"
                    aria-label="Fechar" @click="isLightboxOpen = false">
                    <i class="fas fa-xmark text-lg"></i>
                </button>

                <template v-if="mediaItems.length > 1">
                    <button type="button"
                        class="absolute left-3 top-1/2 z-10 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 md:left-6"
                        aria-label="Mídia anterior" @click="goPrev">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <button type="button"
                        class="absolute right-3 top-1/2 z-10 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 md:right-6"
                        aria-label="Próxima mídia" @click="goNext">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </template>

                <div class="relative z-0 flex max-h-[90vh] max-w-[90vw] items-center justify-center"
                    @click.stop @touchstart="onTouchStart" @touchend="onTouchEnd">
                    <video v-if="activeMedia?.type === 'video'" :src="activeMedia.src" controls autoplay
                        class="max-h-[90vh] max-w-[90vw] rounded-lg"></video>
                    <img v-else-if="activeMedia?.type === 'image'" :src="activeMedia.src" :alt="product.name"
                        class="max-h-[90vh] max-w-[90vw] rounded-lg object-contain">
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
