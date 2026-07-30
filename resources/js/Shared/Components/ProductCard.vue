<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Modal from '@/Shared/Modal.vue';
import { addToCart, formatPrice, primaryImage, specLine, toggleFavorite } from '@/Shared/productCard';

const props = defineProps({
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
    canReview: {
        type: Boolean,
        default: false,
    },
    hasReviewed: {
        type: Boolean,
        default: false,
    },
});

const ratingAvg = computed(() => Number(props.product.reviews_avg_rating ?? 0));
const ratingCount = computed(() => props.product.reviews_count ?? 0);
const filledStars = computed(() => Math.round(ratingAvg.value));

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

            <!-- Adicionar ao carrinho: encostado na lateral direita, sobre a linha imagem/descrição -->
            <button type="button" :disabled="product.stock < 1"
                class="absolute bottom-0 right-[5px] z-10 flex h-10 w-10 translate-y-1/2 items-center justify-center rounded-full border border-store-border-strong bg-store-bg-raised shadow-md transition-colors hover:bg-store-accent hover:text-store-accent-contrast disabled:cursor-not-allowed disabled:opacity-40"
                aria-label="Adicionar ao carrinho" @click="addToCart(product.id)">
                <i class="fas fa-bag-shopping text-sm"></i>
            </button>
        </div>

        <div class="flex flex-1 flex-col gap-1 px-4 pb-4 pt-6">
            <h4 class="text-sm font-medium leading-snug">{{ product.name }}</h4>
            <p v-if="specLine(product)" class="font-store-mono text-[11px] text-store-fg-muted">{{ specLine(product) }}</p>
            <div class="mt-auto flex items-center justify-between pt-2">
                <div>
                    <span v-if="product.has_discount" class="block text-xs text-store-fg-faint line-through decoration-1">{{ formatPrice(product.price) }}</span>
                    <span class="text-sm font-semibold" :class="product.has_discount ? 'text-store-accent' : ''">{{ formatPrice(product.final_price) }}</span>
                    <span v-if="product.stock <= 0" class="mt-0.5 block text-[11px] text-red-600">Esgotado</span>
                </div>
                <div class="flex items-center gap-1">
                    <i v-for="star in 5" :key="star" class="text-[11px]"
                        :class="star <= filledStars ? 'fas fa-star text-amber-400' : 'far fa-star text-store-fg-faint'"></i>
                    <span class="text-[11px] text-store-fg-faint">({{ ratingCount }})</span>
                </div>
            </div>
            <button v-if="canReview && !hasReviewed" type="button"
                class="mt-1 self-start text-[11px] font-medium text-store-accent hover:underline"
                @click="showReviewModal = true">
                Avaliar produto
            </button>
        </div>
    </article>

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
</template>
