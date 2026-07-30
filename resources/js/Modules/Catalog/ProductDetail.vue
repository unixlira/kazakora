<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import Modal from '@/Shared/Modal.vue';
import { formatPrice, toggleFavorite } from '@/Shared/productCard';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    product: { type: Object, required: true },
    reviews: { type: Array, default: () => [] },
    shippingMethods: { type: Array, default: () => [] },
    isFavorite: { type: Boolean, default: false },
    canReview: { type: Boolean, default: false },
    hasReviewed: { type: Boolean, default: false },
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
                <!-- Galeria: vídeo (se houver) + imagens, mesmo tamanho -->
                <div>
                    <div class="relative aspect-square overflow-hidden rounded-2xl border border-store-border bg-store-bg-sunken">
                        <template v-if="activeMedia?.type === 'video'">
                            <video :src="activeMedia.src" controls class="h-full w-full object-contain bg-black"></video>
                        </template>
                        <template v-else-if="activeMedia?.type === 'image'">
                            <img :src="activeMedia.src" :alt="product.name" class="h-full w-full object-cover">
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
                            :aria-pressed="isFavorite" @click="toggleFavorite(product.id)">
                            <i class="text-base" :class="isFavorite ? 'fas fa-heart text-store-accent' : 'far fa-heart text-store-fg-muted'"></i>
                        </button>
                        <Link v-else href="/entrar"
                            class="absolute right-3 top-3 flex h-9 w-9 items-center justify-center rounded-full bg-store-bg-raised shadow">
                            <i class="far fa-heart text-base text-store-fg-muted"></i>
                        </Link>
                    </div>

                    <!-- Miniaturas -->
                    <div v-if="mediaItems.length > 1" class="mt-3 flex gap-2 overflow-x-auto pb-1">
                        <button v-for="(item, index) in mediaItems" :key="index" type="button"
                            class="relative aspect-square w-16 shrink-0 overflow-hidden rounded-xl border-2 bg-store-bg-sunken transition-colors"
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

            <!-- Descrição: landing page centralizada -->
            <section v-if="product.description" class="mt-16 flex justify-center">
                <div class="w-full max-w-3xl rounded-2xl border border-store-border bg-store-bg-raised p-8 md:p-12">
                    <h2 class="text-center font-display text-2xl font-semibold text-store-fg md:text-3xl">Sobre o produto</h2>
                    <div class="mt-6 whitespace-pre-line text-center text-base leading-relaxed text-store-fg-muted md:text-lg">{{ product.description }}</div>
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

                <ul v-else class="mt-6 flex flex-col gap-6">
                    <li v-for="review in reviews" :key="review.id" class="border-b border-store-border pb-6 last:border-0">
                        <div class="flex items-center justify-between gap-4">
                            <span class="font-medium text-store-fg">{{ review.user?.name ?? 'Cliente KazaKora' }}</span>
                            <span class="text-xs text-store-fg-faint">{{ formatDate(review.created_at) }}</span>
                        </div>
                        <div class="mt-1 flex items-center gap-0.5">
                            <i v-for="star in 5" :key="star" class="text-xs"
                                :class="star <= review.rating ? 'fas fa-star text-amber-400' : 'far fa-star text-store-fg-faint'"></i>
                        </div>
                        <p v-if="review.comment" class="mt-2 text-sm text-store-fg-muted">{{ review.comment }}</p>
                    </li>
                </ul>
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
    </AppLayout>
</template>
