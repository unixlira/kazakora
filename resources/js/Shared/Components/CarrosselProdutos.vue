<script setup>
// "Você também pode gostar de" na página do produto (pedido 2026-10-20):
// carrossel de cards que passa para o lado com o dedo, setas e bolinhas.
// 2 por vez no celular, 3 no tablet e 4 no computador: o mesmo tamanho de card
// das outras vitrines (pedido 2026-10-22; eram 5 e o card ficava menor).
import ProductCard from '@/Shared/Components/ProductCard.vue';
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    produtos: { type: Array, default: () => [] },
    favoriteIds: { type: Array, default: () => [] },
    reviewableProductIds: { type: Array, default: () => [] },
    reviewedProductIds: { type: Array, default: () => [] },
});

const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);

const trilho = ref(null);
const pagina = ref(0);
const paginas = ref(1);

const atualizar = () => {
    const el = trilho.value;
    if (!el) return;
    paginas.value = Math.max(1, Math.ceil(el.scrollWidth / el.clientWidth - 0.05));
    pagina.value = Math.min(paginas.value - 1, Math.round(el.scrollLeft / el.clientWidth));
};
const rolar = (direcao) => trilho.value?.scrollBy({ left: direcao * trilho.value.clientWidth, behavior: 'smooth' });
const irPara = (indice) => trilho.value?.scrollTo({ left: indice * trilho.value.clientWidth, behavior: 'smooth' });

onMounted(() => { atualizar(); window.addEventListener('resize', atualizar); });
onBeforeUnmount(() => window.removeEventListener('resize', atualizar));
// Inertia reaproveita a página ao clicar num relacionado: volta para o início.
watch(() => props.produtos, () => {
    trilho.value?.scrollTo({ left: 0 });
    requestAnimationFrame(atualizar);
});
</script>

<template>
    <div class="relative">
        <div ref="trilho" class="no-scrollbar -mx-2 flex snap-x snap-mandatory overflow-x-auto scroll-smooth py-1" @scroll.passive="atualizar">
            <div v-for="product in produtos" :key="product.id"
                class="w-1/2 shrink-0 snap-start px-2 md:w-1/3 lg:px-3 xl:w-1/4">
                <ProductCard :product="product"
                    :is-favorite="favoriteIds.includes(product.id)" :is-authenticated="isAuthenticated"
                    :can-review="reviewableProductIds.includes(product.id)" :has-reviewed="reviewedProductIds.includes(product.id)" />
            </div>
        </div>

        <button v-if="pagina > 0" type="button" aria-label="Produtos anteriores"
            class="absolute left-0 top-[35%] z-10 flex h-10 w-10 -translate-x-1/3 -translate-y-1/2 items-center justify-center rounded-full bg-[#f27a2a] text-lg text-white shadow-md md:-translate-x-1/2"
            @click="rolar(-1)"><i class="fas fa-angle-left"></i></button>
        <button v-if="pagina < paginas - 1" type="button" aria-label="Próximos produtos"
            class="absolute right-0 top-[35%] z-10 flex h-10 w-10 -translate-y-1/2 translate-x-1/3 items-center justify-center rounded-full bg-[#f27a2a] text-lg text-white shadow-md md:translate-x-1/2"
            @click="rolar(1)"><i class="fas fa-angle-right"></i></button>

        <div v-if="paginas > 1" class="mt-4 flex justify-center">
            <button v-for="indice in paginas" :key="indice" type="button" :aria-label="`Ir para ${indice}`"
                class="flex h-6 min-w-6 items-center justify-center"
                @click="irPara(indice - 1)"><span class="h-2 rounded-full transition-all" :class="pagina === indice - 1 ? 'w-6 bg-store-accent' : 'w-2 bg-store-border-strong'"></span></button>
        </div>
    </div>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }
</style>
