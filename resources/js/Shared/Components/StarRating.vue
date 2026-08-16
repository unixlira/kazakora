<script setup>
import { computed } from 'vue';

// Pedido explícito 2026-08-16: no card de produto, as estrelas devem
// representar o PERCENTUAL real da média (ex.: 4.2/5 preenche 84% da 5ª
// estrela), não arredondar pra estrela cheia/vazia mais próxima como o
// componente antigo fazia (Math.round). Clássico padrão "estrela com
// preenchimento parcial": uma linha de estrelas vazias por baixo, uma
// segunda linha idêntica de estrelas cheias por cima, cortada via
// clip-path/overflow no X% de largura correspondente à média.
const props = defineProps({
    value: {
        type: Number,
        default: 0,
    },
    size: {
        type: String,
        default: 'text-[11px]',
    },
});

const percent = computed(() => Math.max(0, Math.min(100, (Number(props.value) || 0) / 5 * 100)));
</script>

<template>
    <span class="relative inline-block leading-none" :aria-label="`${(Number(value) || 0).toFixed(1)} de 5 estrelas`">
        <span class="flex items-center gap-0.5" :class="size">
            <i v-for="star in 5" :key="star" class="far fa-star text-store-fg-faint"></i>
        </span>
        <span class="absolute inset-0 flex items-center gap-0.5 overflow-hidden" :class="size" :style="{ width: percent + '%' }">
            <i v-for="star in 5" :key="star" class="fas fa-star text-amber-400"></i>
        </span>
    </span>
</template>
