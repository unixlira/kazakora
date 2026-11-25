<script setup>
// Cabeçalho de coluna compacto da Leitura das campanhas (2026-11-25): o
// próprio rótulo é o botão da ajuda "o que é · bom é" (passar o mouse ou
// focar com o teclado), sem ícone extra ocupando largura. Fecha com Esc.
import { ref, useId } from 'vue';

defineProps({
    rotulo: { type: String, required: true },
    unidade: { type: String, default: '' },
    ajuda: { type: Array, required: true },
    // Lado em que a caixa abre (colunas da direita abrem para a esquerda).
    lado: { type: String, default: 'esquerda' },
});

const id = `cab-ajuda-${useId()}`;
const aberto = ref(false);
</script>

<template>
    <span class="relative inline-block" @mouseenter="aberto = true" @mouseleave="aberto = false">
        <button type="button" :aria-describedby="id"
            class="cursor-help rounded text-right font-medium leading-tight underline decoration-dotted underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400"
            @focus="aberto = true" @blur="aberto = false" @keydown.esc="aberto = false">
            {{ rotulo }}<span v-if="unidade" class="block text-[10px] font-normal text-slate-500 no-underline">{{ unidade }}</span>
        </button>
        <span :id="id" role="tooltip" v-show="aberto"
            class="absolute top-full z-50 mt-1 w-64 space-y-1 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-2.5 text-left text-xs font-normal normal-case leading-relaxed tracking-normal text-[var(--foreground)] shadow-lg"
            :class="lado === 'direita' ? 'right-0' : 'left-0'">
            <span v-for="(texto, i) in ajuda" :key="i" class="block">{{ texto }}</span>
        </span>
    </span>
</template>
