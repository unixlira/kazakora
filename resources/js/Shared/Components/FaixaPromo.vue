<script setup>
// Faixa promocional acima da tarja preta (pedido 2026-10-10): Black
// November, com o maior desconto REAL da loja (MenuDaLoja::maiorDesconto). O "X"
// esconde até o mês seguinte.
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const mes = String(new Date().getMonth() + 1).padStart(2, '0');
const CHAVE = `kazakora_faixa_black_fechada_${new Date().getFullYear()}${mes}`;

const desconto = computed(() => page.props.menuLoja?.maiorDesconto ?? 0);

// Lê o "fechada" já na criação (sem SSR, o window existe): a faixa nasce no
// primeiro desenho, junto com a tarja corrida, e não entra depois empurrando
// a tarja para baixo (pedido 2026-10-14).
const jaFechada = () => {
    try {
        return window.localStorage.getItem(CHAVE) === '1';
    } catch {
        return false;
    }
};
const visivel = ref(!jaFechada());

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

        <!-- Uma linha só, centralizada, em toda tela (pedido 2026-10-24): a
             fonte encolhe com a largura em vez de quebrar; px igual dos dois
             lados para o "X" não tirar o texto do centro. -->
        <a href="/#ofertas" class="mx-auto flex max-w-[1320px] items-center justify-center whitespace-nowrap px-10 py-2 text-center text-[clamp(0.625rem,3.3vw,1rem)] leading-none text-white no-underline sm:px-14 sm:py-2.5">
            <span class="font-black uppercase italic tracking-[0.06em]">
                Black <span class="text-[#f27a2a]">November</span>,
            </span>
            <span class="ml-[0.4em] font-medium">
                <template v-if="desconto >= 5">
                    garanta até <strong class="text-[1.15em] font-black tracking-tight text-[#f6c343]">{{ desconto }}% OFF</strong>
                </template>
                <template v-else>
                    com <strong class="font-black text-[#f6c343]">frete grátis</strong>
                </template>
            </span>
        </a>

        <button type="button" aria-label="Fechar"
            class="absolute right-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full text-white/90 transition hover:bg-white/15 hover:text-white sm:right-4"
            @click="fechar">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>
    </div>
</template>
