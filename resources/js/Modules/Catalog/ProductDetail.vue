<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import Modal from '@/Shared/Modal.vue';
import ProductCard from '@/Shared/Components/ProductCard.vue';
import { COMPANY } from '@/Shared/company';
import { formatPrice, toggleFavorite } from '@/Shared/productCard';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    product: { type: Object, required: true },
    // Outras variações do mesmo item físico (tamanho, voltagem...),
    // estilo Shopee/Mercado Livre — pedido explícito 2026-08-17.
    variations: { type: Array, default: () => [] },
    reviews: { type: Array, default: () => [] },
    shippingMethods: { type: Array, default: () => [] },
    isFavorite: { type: Boolean, default: false },
    canReview: { type: Boolean, default: false },
    hasReviewed: { type: Boolean, default: false },
    relatedProducts: { type: Array, default: () => [] },
    relatedFavoriteIds: { type: Array, default: () => [] },
    relatedReviewableIds: { type: Array, default: () => [] },
    relatedReviewedIds: { type: Array, default: () => [] },
    salesCount: { type: Number, default: 0 },
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

const variantThumbnail = (item) => {
    const images = item?.images ?? [];
    return images.find((image) => image.is_primary)?.url ?? images[0]?.url ?? null;
};

const currentVariantThumbnail = computed(() => variantThumbnail(props.product));

// Miniaturas: cap fixo (em vez de medir o espaço da tela em pixels) — mais
// simples e previsível. O que passar do cap vira um "+N" que abre o lightbox.
const MAX_VISIBLE_THUMBNAILS = 4;
const visibleThumbnails = computed(() => mediaItems.value.slice(0, MAX_VISIBLE_THUMBNAILS));
const hiddenThumbnailsCount = computed(() => Math.max(0, mediaItems.value.length - MAX_VISIBLE_THUMBNAILS));

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

// Clique na imagem OU no vídeo abre o lightbox (o vídeo embutido na galeria é
// só uma prévia estática com ícone de play, igual às miniaturas — a
// reprodução de verdade acontece no lightbox, com controles próprios).
const onGalleryClick = () => {
    isLightboxOpen.value = true;
};

// Zoom: em vez de ampliar dentro do card, mostra um painel flutuante fora
// dele (à direita, no tamanho original) seguindo a posição do mouse. Só
// para imagem — vídeo não tem zoom, tem lightbox com play/pause/mudo.
const isZooming = ref(false);
const zoomPosition = ref({ x: 50, y: 50 });

const onGalleryMouseEnter = () => {
    if (activeMedia.value?.type === 'image') isZooming.value = true;
};
const onGalleryMouseLeave = () => {
    isZooming.value = false;
};
const onGalleryMouseMove = (event) => {
    if (activeMedia.value?.type !== 'image') return;
    const rect = event.currentTarget.getBoundingClientRect();
    zoomPosition.value = {
        x: Math.min(100, Math.max(0, ((event.clientX - rect.left) / rect.width) * 100)),
        y: Math.min(100, Math.max(0, ((event.clientY - rect.top) / rect.height) * 100)),
    };
};

const isLightboxOpen = ref(false);

// Controles próprios do vídeo dentro do lightbox (sem <video controls> nativo).
const lightboxVideoEl = ref(null);
const isVideoPlaying = ref(false);
const isVideoMuted = ref(false);

const toggleVideoPlay = () => {
    const el = lightboxVideoEl.value;
    if (!el) return;
    if (el.paused) el.play();
    else el.pause();
};
const toggleVideoMute = () => {
    const el = lightboxVideoEl.value;
    if (!el) return;
    el.muted = !el.muted;
    isVideoMuted.value = el.muted;
};

watch(isLightboxOpen, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});

watch(() => props.product.id, () => {
    activeIndex.value = 0;
    isLightboxOpen.value = false;
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

// Preço em destaque com centavos menores/sobrescritos.
const priceParts = computed(() => {
    const [whole, cents] = formatPrice(props.product.final_price).split(',');
    return { whole, cents: cents ?? '00' };
});

// Parcelamento informativo (sem juros, calculado sobre o preço real — não há
// gateway de pagamento integrado ainda, então isso é conteúdo de vitrine).
const installmentValue = computed(() => Number(props.product.final_price) / 12);

const showPaymentModal = ref(false);

// Desconto por quantidade: regra real cadastrada no produto (se existir).
// O percentual é aplicado sobre o final_price (já considerando promoção
// normal), igual à mesma conta feita no backend (CartManager/CheckoutController).
const quantityDiscounts = computed(() =>
    [...(props.product.quantity_discounts ?? [])].sort((a, b) => a.min_quantity - b.min_quantity)
);

const unitPriceForQuantity = (qty) => {
    const tier = [...quantityDiscounts.value].reverse().find((row) => row.min_quantity <= qty);
    if (!tier) return Number(props.product.final_price);
    return Math.max(0, Math.round(Number(props.product.final_price) * (1 - Number(tier.discount_percentage) / 100) * 100) / 100);
};

const currentUnitPrice = computed(() => unitPriceForQuantity(quantity.value));
const currentTotal = computed(() => currentUnitPrice.value * quantity.value);

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

// "O que você precisa saber": reaproveita o primeiro bloco em lista da mesma
// descrição real do produto (não fabrica um resumo à parte).
const whatYouNeedToKnow = computed(() => descriptionBlocks.value.find((block) => block.isList)?.items?.slice(0, 6) ?? []);

const BLOCK_STYLES = [
    { icon: 'bg-store-accent/10 text-store-accent', heading: 'text-store-accent' },
    { icon: 'bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-300', heading: 'text-purple-700 dark:text-purple-300' },
    { icon: 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-900/50 dark:text-fuchsia-300', heading: 'text-fuchsia-700 dark:text-fuchsia-300' },
    { icon: 'bg-violet-100 text-violet-600 dark:bg-violet-900/50 dark:text-violet-300', heading: 'text-violet-600 dark:text-violet-300' },
];
const blockStyle = (index) => BLOCK_STYLES[index % BLOCK_STYLES.length];

const isFavoriteRelated = (productId) => props.relatedFavoriteIds.includes(productId);
const canReviewRelated = (productId) => props.relatedReviewableIds.includes(productId);
const hasReviewedRelated = (productId) => props.relatedReviewedIds.includes(productId);

// Frete: todos os produtos são exibidos com frete grátis. No checkout,
// a cotação real define se aparece também desconto de frete da loja.
const cheapestShipping = computed(() => props.shippingMethods[0] ?? null);
const isFreeShipping = computed(() => Boolean(cheapestShipping.value));
const showAllShipping = ref(false);

const quantity = ref(1);
const addingToCart = ref(false);
const buyingNow = ref(false);

const addToCart = () => {
    addingToCart.value = true;
    router.post('/carrinho', { product_id: props.product.id, quantity: quantity.value }, {
        preserveScroll: true,
        onFinish: () => { addingToCart.value = false; },
    });
};

const buyNow = () => {
    buyingNow.value = true;
    router.post('/carrinho', { product_id: props.product.id, quantity: quantity.value }, {
        preserveScroll: true,
        onSuccess: () => router.get('/finalizacao'),
        onFinish: () => { buyingNow.value = false; },
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
        <div class="mx-auto max-w-[1320px] px-4 pb-24 pt-6 md:px-6 md:pb-12 md:pt-10">
            <!-- Breadcrumb -->
            <nav class="mb-5 flex flex-wrap items-center gap-2 text-sm text-store-fg-muted">
                <Link href="/" class="hover:text-store-accent">Início</Link>
                <i class="fas fa-chevron-right text-[10px]"></i>
                <Link v-if="product.category" :href="`/?search=${encodeURIComponent(product.category.name)}`" class="hover:text-store-accent">
                    {{ product.category.name }}
                </Link>
                <i v-if="product.category" class="fas fa-chevron-right text-[10px]"></i>
                <span class="truncate text-store-fg">{{ product.name }}</span>
            </nav>

            <section class="relative overflow-hidden rounded-[2rem] border border-store-border bg-store-bg-raised p-4 shadow-[0_24px_80px_var(--store-shadow)] md:p-7">
                <div class="pointer-events-none absolute inset-x-0 top-0 h-40 bg-[radial-gradient(circle_at_25%_0%,color-mix(in_oklab,var(--color-store-accent)_26%,transparent),transparent_62%)]"></div>

                <div class="relative mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="max-w-3xl">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-store-mono text-xs uppercase tracking-[0.22em] text-store-fg-muted">Produto KazaKora</span>
                            <span v-if="product.category" class="rounded-full bg-store-accent-soft px-3 py-1 text-xs font-semibold text-store-accent-strong">
                                {{ product.category.name }}
                            </span>
                        </div>
                        <h1 class="mt-3 font-display text-3xl font-semibold leading-tight text-store-fg md:text-5xl">{{ product.name }}</h1>
                        <div class="mt-4 flex flex-wrap items-center gap-3 text-sm text-store-fg-muted">
                            <div class="flex items-center gap-1">
                                <i v-for="star in 5" :key="star" class="text-sm"
                                    :class="star <= filledStars ? 'fas fa-star text-amber-400' : 'far fa-star text-store-fg-faint'"></i>
                            </div>
                            <span>
                                {{ ratingAvg > 0 ? ratingAvg.toFixed(1) : 'Sem avaliações' }}
                                <span v-if="reviewsCount">({{ reviewsCount }} avaliação{{ reviewsCount === 1 ? '' : 'ões' }})</span>
                            </span>
                            <span v-if="salesCount > 0" class="rounded-full bg-store-bg-sunken px-3 py-1 text-xs font-semibold text-store-fg">+{{ salesCount }} vendas</span>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <span v-if="product.is_featured" class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-900/50 dark:text-amber-300">
                            <i class="fas fa-star text-[10px]"></i> Destaque
                        </span>
                        <span v-if="product.is_new_release" class="inline-flex items-center gap-1.5 rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold text-violet-700 dark:bg-violet-900/50 dark:text-violet-300">
                            <i class="fas fa-sparkles text-[10px]"></i> Lançamento
                        </span>
                        <span v-if="discountPercent > 0" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300">
                            <i class="fas fa-tag text-[10px]"></i> {{ discountPercent }}% OFF
                        </span>
                    </div>
                </div>

                <div class="relative grid grid-cols-1 gap-7 lg:grid-cols-[minmax(0,560px)_minmax(360px,1fr)] lg:items-start">
                    <!-- Galeria: vídeo primeiro, senão primeira foto. Miniaturas quadradas e cap +N depois de 4. -->
                    <div class="w-full">
                        <div class="flex flex-col gap-3 md:flex-row">
                            <div v-if="mediaItems.length > 1" class="order-2 grid grid-cols-4 gap-2 md:order-1 md:w-18 md:shrink-0 md:grid-cols-1">
                                <button v-for="(item, index) in visibleThumbnails" :key="index" type="button"
                                    class="relative aspect-square overflow-hidden rounded-2xl border-2 bg-store-bg-sunken transition-colors"
                                    :class="index === activeIndex ? 'border-store-accent' : 'border-store-border hover:border-store-border-strong'"
                                    @click="activeIndex = index">
                                    <template v-if="item.type === 'video'">
                                        <video :src="item.src" class="h-full w-full object-cover"></video>
                                        <span class="absolute inset-0 flex items-center justify-center bg-black/35">
                                            <i class="fas fa-play text-sm text-white"></i>
                                        </span>
                                    </template>
                                    <img v-else :src="item.src" :alt="product.name" class="h-full w-full object-cover">

                                    <span v-if="hiddenThumbnailsCount > 0 && index === MAX_VISIBLE_THUMBNAILS - 1"
                                        class="absolute inset-0 flex items-center justify-center bg-black/62 text-lg font-bold text-white backdrop-blur-[2px]"
                                        @click.stop="activeIndex = index; isLightboxOpen = true">
                                        +{{ hiddenThumbnailsCount }}
                                    </span>
                                </button>
                            </div>

                            <div class="group order-1 relative aspect-square min-h-[300px] flex-1 select-none overflow-hidden rounded-[1.75rem] border border-store-border bg-store-bg-sunken cursor-zoom-in md:order-2"
                                @click="onGalleryClick" @touchstart="onTouchStart" @touchend="onTouchEnd"
                                @mouseenter="onGalleryMouseEnter" @mouseleave="onGalleryMouseLeave" @mousemove="onGalleryMouseMove">
                                <template v-if="activeMedia?.type === 'video'">
                                    <video :src="activeMedia.src" muted class="h-full w-full bg-black object-contain"></video>
                                    <span class="pointer-events-none absolute inset-0 flex items-center justify-center">
                                        <span class="flex h-18 w-18 items-center justify-center rounded-full bg-black/55 text-white shadow-2xl">
                                            <i class="fas fa-play text-2xl"></i>
                                        </span>
                                    </span>
                                </template>
                                <template v-else-if="activeMedia?.type === 'image'">
                                    <img :src="activeMedia.src" :alt="product.name" class="h-full w-full object-contain p-3 md:p-5">
                                </template>
                                <div v-else class="flex h-full w-full items-center justify-center">
                                    <i class="fas fa-box-open text-6xl text-store-accent-strong opacity-30"></i>
                                </div>

                                <div v-if="isZooming && activeMedia?.type === 'image'"
                                    class="pointer-events-none absolute left-full top-0 z-30 ml-4 hidden aspect-square w-[520px] overflow-hidden rounded-[1.75rem] border border-store-border bg-store-bg-raised shadow-xl lg:block"
                                    :style="{
                                        backgroundImage: `url(${activeMedia.src})`,
                                        backgroundSize: '200%',
                                        backgroundPosition: `${zoomPosition.x}% ${zoomPosition.y}%`,
                                        backgroundRepeat: 'no-repeat',
                                    }">
                                </div>

                                <span v-if="discountPercent > 0" class="absolute left-4 top-4 rounded-full bg-emerald-600 px-3 py-1 text-xs font-bold text-white shadow">
                                    -{{ discountPercent }}%
                                </span>

                                <span v-if="mediaItems.length > 1" class="absolute bottom-4 left-1/2 -translate-x-1/2 rounded-full bg-black/62 px-3 py-1.5 text-xs font-medium text-white lg:hidden">
                                    {{ activeIndex + 1 }}/{{ mediaItems.length }}
                                </span>

                                <template v-if="mediaItems.length > 1">
                                    <button type="button" class="absolute left-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-store-bg-raised/95 text-store-fg shadow transition-opacity hover:bg-store-bg-raised md:opacity-0 md:group-hover:opacity-100" aria-label="Mídia anterior" @click.stop="goPrev">
                                        <i class="fas fa-chevron-left text-xs"></i>
                                    </button>
                                    <button type="button" class="absolute right-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-store-bg-raised/95 text-store-fg shadow transition-opacity hover:bg-store-bg-raised md:opacity-0 md:group-hover:opacity-100" aria-label="Próxima mídia" @click.stop="goNext">
                                        <i class="fas fa-chevron-right text-xs"></i>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Painel de conversão: preço, frete, quantidade e botões na mesma row da galeria. -->
                    <aside class="rounded-[1.75rem] border border-store-border bg-store-bg/80 p-5 shadow-sm backdrop-blur md:p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <span v-if="product.has_discount" class="block text-sm text-store-fg-faint line-through decoration-1">{{ formatPrice(product.price) }}</span>
                                <div class="flex items-start gap-3">
                                    <span class="font-display text-4xl font-bold leading-none md:text-5xl" :class="product.has_discount ? 'text-store-accent' : 'text-store-fg'">
                                        {{ priceParts.whole }}<sup class="text-xl md:text-2xl">,{{ priceParts.cents }}</sup>
                                    </span>
                                    <span v-if="discountPercent > 0" class="mt-1 rounded-md bg-emerald-100 px-2 py-0.5 text-sm font-bold text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300">-{{ discountPercent }}%</span>
                                </div>
                                <p class="mt-2 text-sm text-store-fg-muted">em 12x {{ formatPrice(installmentValue) }} sem juros</p>
                                <button type="button" class="mt-1 text-sm font-semibold text-store-accent hover:underline" @click="showPaymentModal = true">Ver meios de pagamento</button>
                            </div>

                            <button v-if="isAuthenticated" type="button" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-store-border bg-store-bg-raised hover:bg-store-bg-sunken"
                                :aria-pressed="isFavorite" @click="toggleFavorite(product.id)">
                                <i class="text-lg" :class="isFavorite ? 'fas fa-heart text-store-accent' : 'far fa-heart text-store-fg-muted'"></i>
                            </button>
                            <Link v-else href="/entrar" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-store-border bg-store-bg-raised hover:bg-store-bg-sunken" aria-label="Favoritar">
                                <i class="far fa-heart text-lg text-store-fg-muted"></i>
                            </Link>
                        </div>

                        <div v-if="variations.length" class="mt-5 border-t border-store-border pt-5">
                            <span class="text-sm font-semibold text-store-fg">Escolha a variação</span>
                            <div class="mt-3 flex flex-wrap gap-3">
                                <span class="inline-flex items-center gap-2 rounded-2xl bg-store-accent px-3 py-2 text-sm font-semibold text-store-accent-contrast shadow-sm shadow-store-accent/15">
                                    <img v-if="currentVariantThumbnail" :src="currentVariantThumbnail" :alt="product.variation || product.name" class="h-10 w-10 rounded-xl object-cover ring-1 ring-white/35">
                                    <span>{{ product.variation || product.name }}</span>
                                </span>
                                <Link v-for="variant in variations" :key="variant.id" :href="`/produtos/${variant.slug}`" preserve-scroll
                                    class="inline-flex items-center gap-2 rounded-2xl border px-3 py-2 text-sm font-medium no-underline transition-colors"
                                    :class="variant.stock > 0 ? 'border-store-border-strong text-store-fg-muted hover:border-store-fg hover:text-store-fg' : 'border-store-border-strong text-store-fg-faint line-through opacity-60'">
                                    <img v-if="variantThumbnail(variant)" :src="variantThumbnail(variant)" :alt="variant.variation || variant.name" class="h-10 w-10 rounded-xl object-cover">
                                    <span>{{ variant.variation || variant.name }}</span>
                                </Link>
                            </div>
                        </div>

                        <div v-if="quantityDiscounts.length" class="mt-5 rounded-2xl border border-store-border bg-store-bg-raised p-4">
                            <h3 class="flex items-center gap-2 text-sm font-semibold text-store-fg"><i class="fas fa-layer-group text-store-accent"></i> Desconto por quantidade</h3>
                            <ul class="mt-3 flex flex-col gap-2">
                                <li v-for="tier in quantityDiscounts" :key="tier.id" class="flex items-center justify-between gap-3 text-sm">
                                    <span class="text-store-fg-muted">{{ tier.min_quantity }} unidades</span>
                                    <span class="font-semibold text-store-fg">{{ formatPrice(unitPriceForQuantity(tier.min_quantity)) }}/un.</span>
                                    <span class="rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300">-{{ Math.round(Number(tier.discount_percentage)) }}%</span>
                                </li>
                            </ul>
                        </div>

                        <div v-if="cheapestShipping" class="mt-5 rounded-2xl border border-store-border bg-store-bg-raised p-4">
                            <div class="flex items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-store-accent-soft text-store-accent-strong"><i class="fas fa-truck-fast"></i></span>
                                <div class="min-w-0 flex-1">
                                    <p v-if="isFreeShipping" class="text-sm font-semibold text-emerald-600 dark:text-emerald-400">Frete grátis para sua casa</p>
                                    <p v-else class="text-sm font-semibold text-store-fg">Chegará em {{ cheapestShipping.estimated_days }} dia{{ cheapestShipping.estimated_days === 1 ? '' : 's' }} por {{ formatPrice(cheapestShipping.price) }}</p>
                                    <button type="button" class="mt-1 text-sm font-semibold text-store-accent hover:underline" @click="showAllShipping = !showAllShipping">Calcular frete e ver entregas</button>
                                </div>
                            </div>
                            <ul v-if="showAllShipping" class="mt-3 flex flex-col gap-2 border-t border-store-border pt-3">
                                <li v-for="method in shippingMethods" :key="method.id" class="flex items-center justify-between gap-4 text-sm text-store-fg-muted">
                                    <span>{{ method.name }} · envio grátis</span>
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-[0.7rem] font-semibold uppercase tracking-wide text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">Grátis</span>
                                </li>
                            </ul>
                            <Link :href="`/produtos/${product.slug}/envio`" class="mt-3 inline-flex text-sm font-semibold text-store-accent hover:underline">Mais formas de envio</Link>
                        </div>

                        <div class="mt-5 rounded-2xl border border-store-border bg-store-bg-raised p-4">
                            <p v-if="isFreeShipping" class="mb-1 flex items-center gap-2 text-sm font-semibold text-emerald-600 dark:text-emerald-400"><i class="fas fa-house"></i> Entrega grátis na sua residência</p>
                            <p v-if="product.stock > 0" class="text-sm font-semibold text-store-fg">Estoque disponível</p>
                            <p v-else class="text-sm font-semibold text-red-600">Produto esgotado</p>

                            <div v-if="product.stock > 0" class="mt-4 flex flex-wrap items-center gap-3">
                                <div class="flex items-center rounded-full border border-store-border-strong bg-store-bg">
                                    <button type="button" class="flex h-11 w-11 items-center justify-center text-store-fg-muted hover:text-store-fg" :disabled="quantity <= 1" @click="quantity = Math.max(1, quantity - 1)"><i class="fas fa-minus text-xs"></i></button>
                                    <span class="w-10 text-center text-sm font-semibold">{{ quantity }}</span>
                                    <button type="button" class="flex h-11 w-11 items-center justify-center text-store-fg-muted hover:text-store-fg" :disabled="quantity >= product.stock" @click="quantity = Math.min(product.stock, quantity + 1)"><i class="fas fa-plus text-xs"></i></button>
                                </div>
                                <p class="text-xs text-store-fg-muted">{{ Math.max(0, product.stock - quantity) }} disponíve{{ product.stock - quantity === 1 ? 'l' : 'is' }}</p>
                            </div>
                            <p v-if="product.stock > 0 && quantityDiscounts.length" class="mt-2 text-sm font-semibold text-store-fg">Total: {{ formatPrice(currentTotal) }}</p>

                            <div class="mt-4 grid gap-2 sm:grid-cols-[1fr_auto]">
                                <button type="button" :disabled="product.stock < 1 || buyingNow" class="flex items-center justify-center gap-2 rounded-full bg-store-accent px-6 py-3 text-sm font-bold text-store-accent-contrast shadow-lg shadow-store-accent/20 transition hover:-translate-y-0.5 hover:opacity-95 disabled:cursor-not-allowed disabled:opacity-40" @click="buyNow">Comprar agora</button>
                                <button type="button" :disabled="product.stock < 1 || addingToCart" class="flex items-center justify-center gap-2 rounded-full border border-store-border-strong bg-store-bg px-5 py-3 text-sm font-bold text-store-fg transition hover:bg-store-bg-sunken disabled:cursor-not-allowed disabled:opacity-40" @click="addToCart"><i class="fas fa-cart-shopping text-sm"></i><span class="sm:hidden lg:inline">Adicionar</span></button>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-3 text-sm text-store-fg-muted sm:grid-cols-2">
                            <Link href="/trocas-e-devolucoes" class="flex items-start gap-3 rounded-2xl border border-store-border bg-store-bg-raised p-4 hover:border-store-border-strong">
                                <i class="fas fa-rotate-left mt-0.5 text-store-accent"></i>
                                <span><strong class="block text-store-fg">Devolução grátis</strong> 30 dias após receber.</span>
                            </Link>
                            <Link href="/trocas-e-devolucoes" class="flex items-start gap-3 rounded-2xl border border-store-border bg-store-bg-raised p-4 hover:border-store-border-strong">
                                <i class="fas fa-shield-halved mt-0.5 text-store-accent"></i>
                                <span><strong class="block text-store-fg">Compra segura</strong> Produto certo ou dinheiro de volta.</span>
                            </Link>
                        </div>

                        <div class="mt-5 border-t border-store-border pt-4 text-sm text-store-fg-muted">
                            <p>Vendido por <span class="font-semibold text-store-fg">{{ COMPANY.nomeFantasia }}</span></p>
                        </div>
                    </aside>
                </div>
            </section>

            <!-- Descrição tipo landing page, centralizada logo abaixo da row principal -->
            <section v-if="product.description" class="mt-10 flex justify-center">
                <div class="relative w-full max-w-5xl overflow-hidden rounded-[2rem] border border-store-border bg-store-bg-raised p-6 shadow-[0_20px_70px_var(--store-shadow)] md:p-10">
                    <div class="absolute inset-x-0 top-0 h-1.5 bg-store-accent"></div>
                    <div class="mx-auto max-w-3xl text-center">
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-store-accent-soft text-store-accent-strong"><i class="fas fa-sparkles"></i></span>
                        <p class="mt-4 font-store-mono text-xs uppercase tracking-[0.22em] text-store-fg-muted">Por que esse produto merece atenção</p>
                        <h2 class="mt-2 font-display text-3xl font-semibold text-store-fg md:text-4xl">Detalhes que fazem diferença no uso diário</h2>
                    </div>

                    <div class="mx-auto mt-8 grid max-w-4xl gap-5 md:grid-cols-2">
                        <div v-for="(block, index) in descriptionBlocks" :key="index" class="rounded-2xl bg-store-bg-sunken/70 p-5">
                            <h3 v-if="block.heading" class="mb-3 flex items-center gap-2.5 font-display text-lg font-semibold" :class="blockStyle(index).heading">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full" :class="blockStyle(index).icon"><i class="fas fa-check text-xs"></i></span>
                                {{ block.heading }}
                            </h3>
                            <ul v-if="block.isList" class="flex flex-col gap-2 text-left">
                                <li v-for="(item, itemIndex) in block.items" :key="itemIndex" class="flex items-start gap-3 text-sm leading-relaxed text-store-fg-muted md:text-base">
                                    <i class="fas fa-circle-check mt-0.5 shrink-0 text-emerald-500"></i><span>{{ item }}</span>
                                </li>
                            </ul>
                            <p v-else class="text-left text-sm leading-relaxed text-store-fg-muted md:text-base">{{ block.text }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Características: tabela completa -->
            <section v-if="fullSpecs.length" id="caracteristicas" class="mt-14 scroll-mt-24">
                <h2 class="mb-6 text-center font-display text-2xl font-semibold text-store-fg md:text-3xl">Características</h2>
                <div class="mx-auto max-w-3xl overflow-hidden rounded-2xl border border-store-border">
                    <div v-for="(spec, index) in fullSpecs" :key="spec.label" class="flex items-center justify-between gap-4 px-5 py-3 text-sm" :class="index % 2 === 0 ? 'bg-store-bg-raised' : 'bg-store-bg-sunken'">
                        <span class="text-store-fg-muted">{{ spec.label }}</span>
                        <span class="font-medium text-store-fg">{{ spec.value }}</span>
                    </div>
                </div>
            </section>

            <!-- Avaliações -->
            <section class="mx-auto mt-16 max-w-3xl">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <h2 class="font-display text-2xl font-semibold text-store-fg">Avaliações {{ reviewsCount ? `(${reviewsCount})` : '' }}</h2>
                    <button v-if="canReview && !hasReviewed" type="button" class="rounded-full bg-store-accent px-4 py-2 text-sm font-semibold text-store-accent-contrast hover:opacity-90" @click="showReviewModal = true">Avaliar produto</button>
                </div>

                <p v-if="!reviews.length" class="mt-6 text-store-fg-muted">Este produto ainda não recebeu avaliações.</p>

                <div v-else class="mt-6 flex flex-col gap-4">
                    <article v-for="review in reviews" :key="review.id" class="flex gap-4 rounded-2xl border border-store-border bg-store-bg-raised p-5 shadow-sm">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-store-accent/10 font-display text-sm font-semibold text-store-accent">{{ review.reviewer_display_name.charAt(0).toUpperCase() }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-medium text-store-fg">{{ review.reviewer_display_name }}</span>
                                <span class="text-xs text-store-fg-faint">{{ formatDate(review.created_at) }}</span>
                            </div>
                            <div class="mt-1 flex items-center gap-0.5">
                                <i v-for="star in 5" :key="star" class="text-xs" :class="star <= review.rating ? 'fas fa-star text-amber-400' : 'far fa-star text-store-fg-faint'"></i>
                            </div>
                            <p v-if="review.comment" class="mt-2 text-sm leading-relaxed text-store-fg-muted">{{ review.comment }}</p>
                            <div v-if="review.images?.length" class="mt-3 flex flex-wrap gap-2">
                                <a v-for="image in review.images" :key="image.id" :href="image.image_url" target="_blank" rel="noopener">
                                    <img :src="image.image_url" alt="" class="h-16 w-16 rounded-lg border border-store-border object-cover">
                                </a>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <hr class="my-16 border-store-border">

            <section v-if="relatedProducts.length">
                <h2 class="mb-6 font-display text-2xl font-semibold text-store-fg md:text-3xl">Você também pode gostar de</h2>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5 lg:gap-6">
                    <ProductCard v-for="related in relatedProducts" :key="related.id" :product="related" :is-favorite="isFavoriteRelated(related.id)" :is-authenticated="isAuthenticated" :can-review="canReviewRelated(related.id)" :has-reviewed="hasReviewedRelated(related.id)" />
                </div>
            </section>
        </div>

        <!-- Barra fixa no rodapé (mobile): preço + ações -->
        <div class="fixed inset-x-0 bottom-0 z-40 flex items-center gap-2 border-t border-store-border bg-store-bg-raised p-3 shadow-[0_-4px_12px_rgba(0,0,0,0.08)] lg:hidden">
            <span class="font-display text-lg font-bold text-store-fg">{{ formatPrice(product.final_price) }}</span>
            <button type="button" :disabled="product.stock < 1 || addingToCart" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-store-border-strong text-store-fg disabled:cursor-not-allowed disabled:opacity-40" aria-label="Adicionar ao carrinho" @click="addToCart"><i class="fas fa-cart-shopping text-sm"></i></button>
            <button type="button" :disabled="product.stock < 1 || buyingNow" class="flex-1 rounded-lg bg-store-accent px-4 py-2.5 text-sm font-semibold text-store-accent-contrast disabled:cursor-not-allowed disabled:opacity-40" @click="buyNow">Comprar agora</button>
        </div>

        <Modal :open="showReviewModal" max-width="max-w-[480px]" @close="showReviewModal = false">
            <h3 class="font-display text-xl font-semibold">Avaliar {{ product.name }}</h3>
            <div class="mt-4 flex gap-1">
                <button v-for="star in 5" :key="star" type="button" @click="reviewForm.rating = star"><i class="text-2xl" :class="star <= reviewForm.rating ? 'fas fa-star text-amber-400' : 'far fa-star text-store-fg-faint'"></i></button>
            </div>
            <textarea v-model="reviewForm.comment" rows="3" placeholder="Conte como foi sua experiência (opcional)" class="mt-4 w-full rounded-lg border border-store-border-strong bg-store-bg px-3 py-2 text-sm"></textarea>
            <p v-if="reviewForm.errors.review" class="mt-2 text-sm text-red-600">{{ reviewForm.errors.review }}</p>
            <button type="button" :disabled="reviewForm.processing" class="mt-4 rounded-lg bg-store-accent px-5 py-2.5 text-sm font-semibold text-store-accent-contrast hover:opacity-90 disabled:opacity-50" @click="submitReview">Enviar avaliação</button>
        </Modal>

        <Modal :open="showPaymentModal" max-width="max-w-[420px]" @close="showPaymentModal = false">
            <h3 class="font-display text-xl font-semibold">Meios de pagamento</h3>
            <ul class="mt-4 flex flex-col gap-3 text-sm text-store-fg-muted">
                <li class="flex items-center gap-3"><i class="fas fa-qrcode w-5 text-store-accent"></i>Pix — aprovação imediata</li>
                <li class="flex items-center gap-3"><i class="fas fa-credit-card w-5 text-store-accent"></i>Cartão de crédito em até 12x sem juros ({{ formatPrice(installmentValue) }}/mês)</li>
            </ul>
        </Modal>

        <Teleport to="body">
            <div v-if="isLightboxOpen" class="fixed inset-0 z-[110] flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/82 backdrop-blur-sm" @click="isLightboxOpen = false"></div>
                <button type="button" class="absolute right-4 top-4 z-10 flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20" aria-label="Fechar" @click="isLightboxOpen = false"><i class="fas fa-xmark text-lg"></i></button>

                <template v-if="mediaItems.length > 1">
                    <button type="button" class="absolute left-3 top-1/2 z-10 flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 md:left-8" aria-label="Mídia anterior" @click="goPrev"><i class="fas fa-chevron-left"></i></button>
                    <button type="button" class="absolute right-3 top-1/2 z-10 flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 md:right-8" aria-label="Próxima mídia" @click="goNext"><i class="fas fa-chevron-right"></i></button>
                </template>

                <div class="relative z-0 flex max-h-[90vh] max-w-[90vw] items-center justify-center" @click.stop @touchstart="onTouchStart" @touchend="onTouchEnd">
                    <div v-if="activeMedia?.type === 'video'" class="relative">
                        <video ref="lightboxVideoEl" :src="activeMedia.src" autoplay class="max-h-[90vh] max-w-[90vw] rounded-2xl" @play="isVideoPlaying = true" @pause="isVideoPlaying = false"></video>
                        <button type="button" class="absolute inset-0 z-0 flex items-center justify-center" @click="toggleVideoPlay"><span v-show="!isVideoPlaying" class="flex h-16 w-16 items-center justify-center rounded-full bg-black/50 text-white"><i class="fas fa-play text-2xl"></i></span></button>
                        <button type="button" class="absolute bottom-4 right-4 z-10 flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20" aria-label="Alternar mudo" @click="toggleVideoMute"><i class="fas text-sm" :class="isVideoMuted ? 'fa-volume-xmark' : 'fa-volume-high'"></i></button>
                    </div>
                    <img v-else-if="activeMedia?.type === 'image'" :src="activeMedia.src" :alt="product.name" class="max-h-[90vh] max-w-[90vw] rounded-2xl object-contain shadow-2xl">
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
