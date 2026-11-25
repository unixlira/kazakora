<script setup>
// Ícone de linha (Lucide, ver icones.js): 24x24, traço 2, cor do texto (currentColor).
import { computed } from 'vue';
import { ICONES } from './icones';

const props = defineProps({
    nome: { type: String, required: true },
    tamanho: { type: [Number, String], default: 24 },
    traco: { type: [Number, String], default: 2 },
});
const corpo = computed(() => ICONES[props.nome] ?? '');
</script>

<template>
    <svg class="pb-ico" :width="tamanho" :height="tamanho" viewBox="0 0 24 24" fill="none" stroke="currentColor" :stroke-width="traco"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" v-html="corpo"></svg>
</template>
