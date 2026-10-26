<script setup>
/**
 * Ícone (?) ao lado de cada número da Análise Profunda: abre por clique ou
 * teclado, fecha com Esc ou clique fora. Texto vem do glossarioShopee.js.
 */
import { computed, onBeforeUnmount, ref, useId } from 'vue';
import { GLOSSARIO } from './glossarioShopee.js';

const props = defineProps({
    chave: { type: String, required: true },
    // Número da loja que entra no lugar de {loja} ("A loja está em 1,75%").
    loja: { type: String, default: null },
});

const aberto = ref(false);
const raiz = ref(null);
const id = `ajuda-${useId()}`;
const v = computed(() => GLOSSARIO[props.chave]);
const trocar = (t) => (props.loja ? t.replace('{loja}', props.loja) : t.replace(/ ?A loja está em \{loja\}%\./, ''));

const fora = (e) => {
    if (raiz.value && !raiz.value.contains(e.target)) fechar();
};
const fechar = () => {
    aberto.value = false;
    document.removeEventListener('click', fora);
};
const alternar = () => {
    aberto.value = !aberto.value;
    if (aberto.value) setTimeout(() => document.addEventListener('click', fora));
    else document.removeEventListener('click', fora);
};
onBeforeUnmount(() => document.removeEventListener('click', fora));
</script>

<template>
    <span v-if="v" ref="raiz" class="relative inline-block align-middle" @keydown.esc="fechar">
        <button
            type="button"
            class="ml-1 inline-flex h-5 w-5 items-center justify-center rounded-full text-slate-400 hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
            :aria-expanded="aberto"
            :aria-controls="id"
            :aria-label="`O que é ${v.nome}`"
            @click.stop="alternar"
        ><i class="fas fa-circle-question text-xs" aria-hidden="true"></i></button>
        <span
            v-show="aberto"
            :id="id"
            role="dialog"
            :aria-label="v.nome"
            class="absolute left-0 top-6 z-30 block w-72 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-3 text-left text-xs font-normal normal-case leading-relaxed tracking-normal text-slate-700 shadow-lg dark:text-slate-200"
        >
            <span class="mb-1 block text-sm font-semibold">{{ v.nome }} <span class="text-xs font-normal text-slate-500">({{ v.fonte }})</span></span>
            <span class="block"><b>O que é:</b> {{ trocar(v.oque) }}</span>
            <span class="block"><b>Bom:</b> {{ trocar(v.bom) }}</span>
            <span class="block"><b>Ruim:</b> {{ trocar(v.ruim) }}</span>
            <span class="block"><b>O que fazer:</b> {{ trocar(v.fazer) }}</span>
        </span>
    </span>
</template>
