<script setup>
// Seção de produtos da home (pedido 2026-10-10): título, link "Ver todos"
// e grade de cards — 2 por linha no celular, 4 no computador.
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
    // 5 colunas no computador (Ofertas do dia têm 5 produtos).
    cinco: { type: Boolean, default: false },
    // Celular: slide com 1 card por tela (Ofertas do dia, pedido 2026-10-10).
    slider: { type: Boolean, default: false },
    // Celular: título, texto e contador centralizados (Oferta Relâmpago, pedido 2026-10-15).
    centro: { type: Boolean, default: false },
});

const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);

const trilho = ref(null);
const pagina = ref(0);
const paginas = ref(1);
// Bolinhas = páginas do carrossel, não uma por produto (pedido 2026-10-15).
const atualizar = () => {
    const el = trilho.value;
    if (!el || !el.clientWidth) return;
    paginas.value = Math.max(1, Math.ceil(el.scrollWidth / el.clientWidth - 0.05));
    pagina.value = Math.min(paginas.value - 1, Math.round(el.scrollLeft / el.clientWidth));
};
const irPara = (indice) => {
    const el = trilho.value;
    if (!el) return;
    const destino = Math.max(0, Math.min(paginas.value - 1, indice));
    el.scrollTo({ left: destino * el.clientWidth, behavior: 'smooth' });
};

onMounted(() => { atualizar(); window.addEventListener('resize', atualizar); });
onBeforeUnmount(() => window.removeEventListener('resize', atualizar));
</script>

<template>
    <section v-if="produtos.length" class="mx-auto max-w-[1320px] px-4 py-10 md:px-6">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-3" :class="{ 'max-md:flex-col max-md:items-center max-md:text-center': centro }">
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
        <!-- Carrossel do celular: arrasta com o dedo, setas e uma bolinha por PÁGINA.
             Ofertas do dia (slider) 1 card por tela; as demais seções 2 por tela. -->
        <div class="relative md:hidden">
            <div ref="trilho" class="no-scrollbar flex snap-x snap-mandatory overflow-x-auto scroll-smooth" @scroll.passive="atualizar">
                <div v-for="product in produtos" :key="product.id" class="shrink-0 snap-start px-1"
                    :class="slider ? 'w-full' : 'w-1/2'">
                    <ProductCard :product="product" :estrelas="slider"
                        :is-favorite="favoriteIds.includes(product.id)" :is-authenticated="isAuthenticated"
                        :can-review="reviewableProductIds.includes(product.id)" :has-reviewed="reviewedProductIds.includes(product.id)" />
                </div>
            </div>
            <!-- Setas laranja (pedido 2026-10-10): fica claro que é ali que passa. -->
            <button v-if="pagina > 0" type="button" aria-label="Anterior"
                class="absolute left-0 top-[35%] flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-[#f27a2a] text-lg text-white shadow-md"
                @click="irPara(pagina - 1)"><i class="fas fa-angle-left"></i></button>
            <button v-if="pagina < paginas - 1" type="button" aria-label="Próxima"
                class="absolute right-0 top-[35%] flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-[#f27a2a] text-lg text-white shadow-md"
                @click="irPara(pagina + 1)"><i class="fas fa-angle-right"></i></button>
            <div v-if="paginas > 1" class="mt-3 flex justify-center gap-2">
                <button v-for="indice in paginas" :key="indice" type="button" :aria-label="`Página ${indice}`"
                    class="h-2 rounded-full transition-all" :class="indice - 1 === pagina ? 'w-6 bg-store-accent' : 'w-2 bg-store-border-strong'"
                    @click="irPara(indice - 1)"></button>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-4 max-md:hidden md:grid-cols-4 lg:gap-6" :class="{ 'lg:grid-cols-5': cinco }">
            <ProductCard v-for="product in produtos" :key="product.id" :product="product"
                :is-favorite="favoriteIds.includes(product.id)" :is-authenticated="isAuthenticated"
                :can-review="reviewableProductIds.includes(product.id)" :has-reviewed="reviewedProductIds.includes(product.id)" />
        </div>
    </section>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }
</style>
