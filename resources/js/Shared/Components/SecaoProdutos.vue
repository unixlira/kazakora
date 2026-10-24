<script setup>
// Seção de produtos da home (pedido 2026-10-10): título, link "Ver todos"
// e grade de cards: 2 por linha no celular, 3 no tablet e 4 no computador, a
// mesma grade de todas as vitrines (pedido 2026-10-22: card igual em toda a loja).
import ProductCard from '@/Shared/Components/ProductCard.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    titulo: { type: String, required: true },
    subtitulo: { type: String, default: null },
    link: { type: String, default: null },
    produtos: { type: Array, default: () => [] },
    favoriteIds: { type: Array, default: () => [] },
    reviewableProductIds: { type: Array, default: () => [] },
    reviewedProductIds: { type: Array, default: () => [] },
    // Celular: slide com 2 cards por tela, do tamanho dos outros (Ofertas do dia,
    // pedido 2026-10-10; eram 1 por tela até 2026-10-22) e Utilidades para casa (2026-10-24).
    slider: { type: Boolean, default: false },
});

const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);

// Com `slider`, os cards eram montados duas vezes (slide do celular e grade
// do computador, uma escondida por CSS): 16 cards para 8 ofertas logo abaixo
// do banner (PageSpeed 2026-10-21). Agora só a versão da tela atual monta.
const consultaCelular = typeof window !== 'undefined' ? window.matchMedia('(max-width: 767px)') : null;
const celular = ref(consultaCelular?.matches ?? false);
const aoMudarTela = (e) => { celular.value = e.matches; };
onMounted(() => consultaCelular?.addEventListener('change', aoMudarTela));
onBeforeUnmount(() => consultaCelular?.removeEventListener('change', aoMudarTela));

const trilho = ref(null);
const atual = ref(0);
const paginas = computed(() => Math.ceil(props.produtos.length / 2));
const aoRolar = () => {
    const el = trilho.value;
    if (el) atual.value = Math.round(el.scrollLeft / el.clientWidth);
};
const irPara = (indice) => {
    const el = trilho.value;
    if (!el) return;
    const destino = Math.max(0, Math.min(paginas.value - 1, indice));
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
        <!-- Slide do celular: 2 cards por tela, arrasta com o dedo, setas e bolinhas. -->
        <div v-if="slider && celular" class="relative md:hidden">
            <div ref="trilho" class="no-scrollbar -mx-2 flex snap-x snap-mandatory overflow-x-auto scroll-smooth" @scroll.passive="aoRolar">
                <!-- Sem as estrelas ao lado do FULL: com 2 cards por tela elas ficavam cortadas (2026-10-22). -->
                <div v-for="(product, indice) in produtos" :key="product.id" class="w-1/2 shrink-0 px-2" :class="{ 'snap-start': indice % 2 === 0 }">
                    <ProductCard :product="product"
                        :is-favorite="favoriteIds.includes(product.id)" :is-authenticated="isAuthenticated"
                        :can-review="reviewableProductIds.includes(product.id)" :has-reviewed="reviewedProductIds.includes(product.id)" />
                </div>
            </div>
            <!-- Setas laranja (pedido 2026-10-10): fica claro que é ali que passa. -->
            <button v-if="atual > 0" type="button" aria-label="Produtos anteriores"
                class="absolute left-0 top-[35%] flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-[#f27a2a] text-lg text-white shadow-md"
                @click="irPara(atual - 1)"><i class="fas fa-angle-left"></i></button>
            <button v-if="atual < paginas - 1" type="button" aria-label="Próximos produtos"
                class="absolute right-0 top-[35%] flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-[#f27a2a] text-lg text-white shadow-md"
                @click="irPara(atual + 1)"><i class="fas fa-angle-right"></i></button>
            <div class="mt-2 flex justify-center">
                <button v-for="indice in paginas" :key="indice" type="button" :aria-label="`Produtos ${indice * 2 - 1} e ${indice * 2}`"
                    class="flex h-6 min-w-6 items-center justify-center"
                    @click="irPara(indice - 1)"><span class="h-2 rounded-full transition-all" :class="indice - 1 === atual ? 'w-6 bg-store-accent' : 'w-2 bg-store-border-strong'"></span></button>
            </div>
        </div>
        <div v-if="!slider || !celular" class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:gap-6 xl:grid-cols-4" :class="{ 'max-md:hidden': slider }">
            <ProductCard v-for="product in produtos" :key="product.id" :product="product"
                :is-favorite="favoriteIds.includes(product.id)" :is-authenticated="isAuthenticated"
                :can-review="reviewableProductIds.includes(product.id)" :has-reviewed="reviewedProductIds.includes(product.id)" />
        </div>
    </section>
</template>
