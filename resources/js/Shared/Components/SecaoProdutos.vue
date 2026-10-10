<script setup>
// Seção de produtos da home (pedido 2026-10-10): título, link "Ver todos"
// e grade de cards — 2 por linha no celular, 4 no computador.
import ProductCard from '@/Shared/Components/ProductCard.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

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
    // Celular: slide com 1 card por tela (Ofertas do dia, pedido 2026-10-10).
    slider: { type: Boolean, default: false },
});

const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);

const trilho = ref(null);
const atual = ref(0);
const aoRolar = () => {
    const el = trilho.value;
    if (el) atual.value = Math.round(el.scrollLeft / el.clientWidth);
};
const irPara = (indice) => {
    const el = trilho.value;
    if (!el) return;
    const destino = Math.max(0, Math.min(props.produtos.length - 1, indice));
    el.scrollTo({ left: destino * el.clientWidth, behavior: 'smooth' });
};
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
        <!-- Slide do celular: 1 card por tela, arrasta com o dedo, setas e bolinhas. -->
        <div v-if="slider" class="relative md:hidden">
            <div ref="trilho" class="no-scrollbar flex snap-x snap-mandatory overflow-x-auto scroll-smooth" @scroll.passive="aoRolar">
                <div v-for="product in produtos" :key="product.id" class="w-full shrink-0 snap-center px-1">
                    <ProductCard :product="product"
                        :is-favorite="favoriteIds.includes(product.id)" :is-authenticated="isAuthenticated"
                        :can-review="reviewableProductIds.includes(product.id)" :has-reviewed="reviewedProductIds.includes(product.id)" />
                </div>
            </div>
            <button v-if="atual > 0" type="button" aria-label="Oferta anterior"
                class="absolute left-0 top-[35%] flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-lg text-slate-500 shadow"
                @click="irPara(atual - 1)"><i class="fas fa-angle-left"></i></button>
            <button v-if="atual < produtos.length - 1" type="button" aria-label="Próxima oferta"
                class="absolute right-0 top-[35%] flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-lg text-slate-500 shadow"
                @click="irPara(atual + 1)"><i class="fas fa-angle-right"></i></button>
            <div class="mt-3 flex justify-center gap-2">
                <button v-for="(product, indice) in produtos" :key="product.id" type="button" :aria-label="`Oferta ${indice + 1}`"
                    class="h-2 rounded-full transition-all" :class="indice === atual ? 'w-6 bg-store-accent' : 'w-2 bg-store-border-strong'"
                    @click="irPara(indice)"></button>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4 lg:gap-6" :class="{ 'lg:grid-cols-5': cinco, 'max-md:hidden': slider }">
            <ProductCard v-for="product in produtos" :key="product.id" :product="product"
                :is-favorite="favoriteIds.includes(product.id)" :is-authenticated="isAuthenticated"
                :can-review="reviewableProductIds.includes(product.id)" :has-reviewed="reviewedProductIds.includes(product.id)" />
        </div>
    </section>
</template>
