<script setup>
// Cubinho em perspectiva (desenho próprio da KazaKora para a página de brinquedo): topo na cor,
// laterais em tons mais escuros e contorno escuro, como um bloco de montar.
import { computed } from 'vue';

const props = defineProps({
    cor: { type: String, default: 'laranja' },
    tamanho: { type: [Number, String], default: 24 },
});
const TONS = {
    laranja: ['#ff9a5c', '#f37021', '#c9561a'],
    amarelo: ['#ffd95a', '#fcbd10', '#d49c00'],
    verde: ['#5fd68a', '#16a34a', '#0f7a35'],
    azul: ['#7ea6ff', '#2563eb', '#1d4ed8'],
};
const tons = computed(() => TONS[props.cor] ?? TONS.laranja);
</script>

<template>
    <svg class="pb-cubo" :width="tamanho" :height="tamanho" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
        <path d="M16 3 28 9.6 16 16.2 4 9.6z" :fill="tons[0]" stroke="#1b1b2f" stroke-width="1.6" stroke-linejoin="round" />
        <path d="M4 9.6 16 16.2V29L4 22.4z" :fill="tons[1]" stroke="#1b1b2f" stroke-width="1.6" stroke-linejoin="round" />
        <path d="M28 9.6 16 16.2V29l12-6.6z" :fill="tons[2]" stroke="#1b1b2f" stroke-width="1.6" stroke-linejoin="round" />
    </svg>
</template>
