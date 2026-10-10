<script setup>
// Faixa promocional acima da tarja preta (pedido 2026-10-10, no estilo da
// faixa "Black November" que o Lira mandou): fundo laranja da marca com
// formas desfocadas, selo do mês e o maior desconto REAL da loja. O "X"
// esconde até o mês seguinte (selo novo, faixa volta).
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const page = usePage();
const mes = String(new Date().getMonth() + 1).padStart(2, '0');
const SELO = `Ofertas ${mes}.${mes}`;
const CHAVE = `kazakora_faixa_promo_fechada_${new Date().getFullYear()}${mes}`;

const desconto = computed(() => page.props.menuLoja?.maiorDesconto ?? 0);
const visivel = ref(false);

onMounted(() => {
    try {
        visivel.value = window.localStorage.getItem(CHAVE) !== '1';
    } catch {
        visivel.value = true;
    }
});

const fechar = () => {
    visivel.value = false;
    try {
        window.localStorage.setItem(CHAVE, '1');
    } catch {
        // sem armazenamento: fecha só nesta página
    }
};
</script>

<template>
    <div v-if="visivel" class="relative isolate overflow-hidden bg-[#f27a2a] text-white">
        <!-- Formas desfocadas (só CSS) -->
        <span aria-hidden="true" class="pointer-events-none absolute -left-10 -top-14 -z-10 h-40 w-40 rounded-full bg-[#ffb347] opacity-70 blur-2xl"></span>
        <span aria-hidden="true" class="pointer-events-none absolute -bottom-16 right-16 -z-10 h-40 w-44 rounded-full bg-[#e0480f] opacity-70 blur-2xl"></span>
        <span aria-hidden="true" class="pointer-events-none absolute left-1/3 top-0 -z-10 hidden h-16 w-48 rounded-full bg-white/25 blur-2xl md:block"></span>

        <a href="/#ofertas" class="mx-auto flex max-w-[1320px] flex-col items-center justify-center gap-0.5 px-12 py-2 text-center no-underline sm:flex-row sm:gap-3 sm:py-2.5">
            <span class="rounded-full bg-black px-3 py-0.5 text-[0.7rem] font-extrabold uppercase italic tracking-[0.12em] text-white shadow-sm sm:text-xs">
                <i class="fa-solid fa-bolt mr-1 text-[#f6c343]"></i>{{ SELO }}
            </span>
            <p v-if="desconto >= 5" class="text-[0.8rem] font-medium leading-snug sm:text-sm">
                <span class="hidden sm:inline">Aproveite as ofertas do mês! Descontos de até </span>
                <span class="sm:hidden">Garanta até </span>
                <strong class="text-base font-black tracking-tight sm:text-lg">{{ desconto }}% OFF</strong>
            </p>
            <p v-else class="text-[0.8rem] font-medium leading-snug sm:text-sm">
                <strong class="font-black">Frete grátis</strong> em todos os produtos
            </p>
        </a>

        <button type="button" aria-label="Fechar"
            class="absolute right-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full text-white/90 transition hover:bg-white/20 hover:text-white sm:right-4"
            @click="fechar">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>
    </div>
</template>
