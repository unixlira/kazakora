<script setup>
import { onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    banners: {
        type: Array,
        required: true,
    },
    // Home: os cards de benefícios cobrem a base do banner (pedido
    // 2026-10-10) — título e bolinhas sobem pra não ficarem escondidos.
    reservaBase: {
        type: Boolean,
        default: false,
    },
});

const current = ref(0);
let timer = null;

const start = () => {
    stop();
    if (props.banners.length > 1) {
        timer = setInterval(next, 5000);
    }
};

const stop = () => {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
};

const next = () => {
    current.value = (current.value + 1) % props.banners.length;
};

const prev = () => {
    current.value = (current.value - 1 + props.banners.length) % props.banners.length;
};

const goTo = (index) => {
    current.value = index;
    start();
};

// Pausa só com mouse de verdade (pedido 2026-10-10: no celular o toque
// disparava o "mouseenter", pausava e nunca mais trocava de banner).
const pausarComMouse = (event) => { if (event.pointerType === 'mouse') stop(); };
const retomarComMouse = (event) => { if (event.pointerType === 'mouse') start(); };

// Arrastar com o dedo para o lado troca o banner.
let toqueX = null;
let toqueY = null;
const inicioToque = (event) => {
    toqueX = event.touches[0].clientX;
    toqueY = event.touches[0].clientY;
};
const fimToque = (event) => {
    if (toqueX === null || props.banners.length < 2) return;
    const dx = event.changedTouches[0].clientX - toqueX;
    const dy = event.changedTouches[0].clientY - toqueY;
    toqueX = null;
    if (Math.abs(dx) < 40 || Math.abs(dx) < Math.abs(dy)) return;
    if (dx < 0) next(); else prev();
    arrastou = true;
    start();
};
// Depois de arrastar, o "clique" que o celular solta no fim não abre o link do banner.
let arrastou = false;
const cliqueBanner = (event) => {
    if (arrastou) {
        event.preventDefault();
        arrastou = false;
    }
};

onMounted(start);
onUnmounted(stop);
</script>

<template>
    <section
        class="group relative w-full touch-pan-y select-none overflow-hidden"
        @pointerenter="pausarComMouse"
        @pointerleave="retomarComMouse"
        @touchstart.passive="inicioToque"
        @touchend="fimToque"
    >
        <!-- Celular: a imagem (mobile ou, sem ela, a do computador) entra
             "fluid" — largura toda e altura no formato dela, sem cortar nem
             sobrar espaço (o .img-fluid do Bootstrap). Computador: quadro fixo. -->
        <div class="relative w-full md:aspect-[21/9] lg:aspect-[3/1]">
            <template v-for="(banner, index) in banners" :key="banner.id">
                <component
                    :is="banner.link_url ? 'a' : 'div'"
                    v-show="index === current"
                    :href="banner.link_url || undefined"
                    class="block md:absolute md:inset-0"
                    @click="cliqueBanner"
                >
                    <!-- <picture>: o navegador baixa só a imagem do tamanho de tela
                         certo (antes baixava as duas). O 1º banner vem com
                         prioridade; os outros só quando aparecem. -->
                    <picture class="block h-full">
                        <source media="(min-width: 768px)" :srcset="banner.image_url">
                        <img :src="banner.image_url_mobile || banner.image_url" :alt="banner.title || 'Banner promocional'"
                            class="img-fluid md:object-cover" decoding="async"
                            :loading="index === 0 ? 'eager' : 'lazy'" :fetchpriority="index === 0 ? 'high' : 'auto'">
                    </picture>
                    <div v-if="banner.title" class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/60 to-transparent p-4 sm:p-6" :class="{ 'md:pb-20': reservaBase }">
                        <p class="font-display text-lg font-semibold text-white sm:text-2xl">{{ banner.title }}</p>
                    </div>
                </component>
            </template>
        </div>

        <template v-if="banners.length > 1">
            <button type="button" aria-label="Banner anterior"
                class="absolute left-2 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-white/80 text-store-fg opacity-0 shadow transition-opacity group-hover:opacity-100 sm:left-4"
                @click="prev(); start()">
                <i class="fas fa-chevron-left text-sm"></i>
            </button>
            <button type="button" aria-label="Próximo banner"
                class="absolute right-2 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-white/80 text-store-fg opacity-0 shadow transition-opacity group-hover:opacity-100 sm:right-4"
                @click="next(); start()">
                <i class="fas fa-chevron-right text-sm"></i>
            </button>

            <div class="absolute inset-x-0 flex justify-center gap-2" :class="reservaBase ? 'bottom-3 md:bottom-16' : 'bottom-3'">
                <button v-for="(banner, index) in banners" :key="banner.id" type="button"
                    :aria-label="`Ir para o banner ${index + 1}`"
                    class="h-2 rounded-full bg-store-accent transition-all"
                    :class="index === current ? 'w-6 opacity-100' : 'w-2 opacity-50'"
                    @click="goTo(index)"
                />
            </div>
        </template>
    </section>
</template>
