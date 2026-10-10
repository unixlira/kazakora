<script setup>
// "Mais Buscados da Semana" (pedido 2026-10-10, modelo izeshop): faixa em
// degradê azul → preto, texto à esquerda e slider só de fotos à direita
// (3 por vez no computador, 1 no celular), com setas e bolinhas.
import { Link } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

defineProps({
    produtos: { type: Array, default: () => [] },
});

const trilho = ref(null);
const pagina = ref(0);
const paginas = ref(1);

const atualizar = () => {
    const el = trilho.value;
    if (!el) return;
    paginas.value = Math.max(1, Math.ceil(el.scrollWidth / el.clientWidth - 0.05));
    pagina.value = Math.round(el.scrollLeft / el.clientWidth);
};
const rolar = (direcao) => trilho.value?.scrollBy({ left: direcao * trilho.value.clientWidth, behavior: 'smooth' });
const irPara = (indice) => trilho.value?.scrollTo({ left: indice * trilho.value.clientWidth, behavior: 'smooth' });

onMounted(() => { atualizar(); window.addEventListener('resize', atualizar); });
onBeforeUnmount(() => window.removeEventListener('resize', atualizar));

const foto = (product) => {
    const images = product.images ?? [];
    const principal = images.find((image) => image.is_primary) ?? images[0];
    return principal?.thumb_url ?? principal?.url ?? null;
};
</script>

<template>
    <section v-if="produtos.length" class="bg-gradient-to-r from-[#0b2a6b] via-[#0a1a3f] to-black py-12 text-white md:py-16">
        <div class="mx-auto grid max-w-[1320px] items-center gap-8 px-4 md:grid-cols-[1fr_2fr] md:px-6">
            <div>
                <h2 class="text-3xl font-bold leading-tight md:text-4xl">Mais Buscados da Semana</h2>
                <p class="mt-3 text-lg text-white/80">Preços incríveis e Frete GRÁTIS por tempo limitado</p>
            </div>

            <div class="relative">
                <div ref="trilho" class="no-scrollbar flex snap-x snap-mandatory gap-3 overflow-x-auto scroll-smooth" @scroll.passive="atualizar">
                    <Link v-for="product in produtos" :key="product.id" :href="`/produtos/${product.slug}`" :aria-label="product.name"
                        class="block aspect-square w-full shrink-0 snap-start overflow-hidden rounded-xl bg-white md:w-[calc((100%-1.5rem)/3)]">
                        <img v-if="foto(product)" :src="foto(product)" :alt="product.name" loading="lazy" decoding="async"
                            class="h-full w-full object-cover transition duration-500 hover:scale-105">
                    </Link>
                </div>

                <button v-if="pagina > 0" type="button" aria-label="Anterior"
                    class="absolute left-0 top-1/2 flex h-10 w-10 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-white text-black shadow-lg"
                    @click="rolar(-1)"><i class="fas fa-angle-left"></i></button>
                <button v-if="pagina < paginas - 1" type="button" aria-label="Próximo"
                    class="absolute right-0 top-1/2 flex h-10 w-10 -translate-y-1/2 translate-x-1/2 items-center justify-center rounded-full bg-white text-black shadow-lg"
                    @click="rolar(1)"><i class="fas fa-angle-right"></i></button>

                <div v-if="paginas > 1" class="mt-4 flex justify-center gap-2">
                    <button v-for="indice in paginas" :key="indice" type="button" :aria-label="`Ir para ${indice}`"
                        class="h-2 rounded-full transition-all" :class="pagina === indice - 1 ? 'w-6 bg-white' : 'w-2 bg-white/40'"
                        @click="irPara(indice - 1)"></button>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }
</style>
