<script setup>
// Faixa promocional acima da tarja preta (pedido 2026-10-10): Black
// November, com o maior desconto REAL da loja (MenuDaLoja::maiorDesconto). O "X"
// esconde até o mês seguinte.
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const page = usePage();
const mes = String(new Date().getMonth() + 1).padStart(2, '0');
const CHAVE = `kazakora_faixa_black_fechada_${new Date().getFullYear()}${mes}`;

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
    <!-- Estilo "Black November" (pedido 2026-10-10): fundo preto, formas
         desfocadas laranja, selo em letra própria (CSS, sem imagem de outro site). -->
    <div v-if="visivel" class="relative isolate overflow-hidden border-b border-white/10 bg-[#0b0b0b] text-white">
        <span aria-hidden="true" class="pointer-events-none absolute -left-12 -top-16 -z-10 h-40 w-44 rounded-full bg-[#f27a2a] opacity-60 blur-2xl"></span>
        <span aria-hidden="true" class="pointer-events-none absolute -bottom-20 right-10 -z-10 h-40 w-48 rounded-full bg-[#f27a2a] opacity-50 blur-2xl"></span>

        <a href="/#ofertas" class="mx-auto flex max-w-[1320px] flex-col items-center justify-center gap-0.5 px-12 py-2 text-center text-white no-underline sm:flex-row sm:gap-4 sm:py-2.5">
            <span class="whitespace-nowrap text-sm font-black uppercase italic leading-none tracking-[0.08em] sm:text-base">
                Black <span class="text-[#f27a2a]">November</span>
            </span>
            <p class="text-[0.8rem] font-medium leading-snug sm:text-sm">
                <template v-if="desconto >= 5">
                    <span class="hidden sm:inline">Aproveite a maior oferta do ano! Desconto de até </span>
                    <span class="sm:hidden">Garanta até </span>
                    <strong class="text-base font-black tracking-tight text-[#f6c343] sm:text-lg">{{ desconto }}% OFF</strong>
                </template>
                <template v-else>
                    Aproveite a maior oferta do ano com <strong class="font-black text-[#f6c343]">frete grátis</strong>
                </template>
            </p>
        </a>

        <button type="button" aria-label="Fechar"
            class="absolute right-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full text-white/90 transition hover:bg-white/15 hover:text-white sm:right-4"
            @click="fechar">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>
    </div>
</template>
