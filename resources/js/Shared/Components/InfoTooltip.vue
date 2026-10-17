<script setup>
// Ícone "i" com explicação (2026-10-16). Diferente do FieldTooltip (que usa
// só o title do navegador), aparece também com foco de teclado, fecha com
// Esc e é lido pelo leitor de tela (aria-describedby).
import { ref, useId } from 'vue';

defineProps({
    // Um parágrafo por item.
    paragraphs: {
        type: Array,
        required: true,
    },
    label: {
        type: String,
        default: 'Mais informações',
    },
});

const id = `info-tooltip-${useId()}`;
const aberto = ref(false);
</script>

<template>
    <span class="relative inline-flex align-middle"
        @mouseenter="aberto = true" @mouseleave="aberto = false">
        <button type="button" :aria-label="label" :aria-describedby="id"
            class="inline-flex h-5 w-5 cursor-help items-center justify-center rounded-full border border-current text-[10px] font-bold leading-none text-slate-400 hover:text-[var(--foreground)]"
            @focus="aberto = true" @blur="aberto = false" @keydown.esc="aberto = false">
            <i class="fas fa-info" aria-hidden="true"></i>
        </button>
        <span :id="id" role="tooltip" v-show="aberto"
            class="absolute left-0 top-full z-50 mt-2 w-[min(22rem,calc(100vw-2rem))] space-y-1.5 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-3 text-left text-xs font-normal leading-relaxed text-[var(--foreground)] shadow-lg">
            <span v-for="(texto, i) in paragraphs" :key="i" class="block">{{ texto }}</span>
        </span>
    </span>
</template>
