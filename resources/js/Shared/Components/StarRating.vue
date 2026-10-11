<script setup>
import { computed } from 'vue';

// Pedido explícito 2026-08-16: no card de produto, as estrelas devem
// representar o PERCENTUAL real da média (ex.: 4.2/5 preenche 84% da 5ª
// estrela), não arredondar pra estrela cheia/vazia mais próxima.
//
// CORRIGIDO 2026-08-16 (reporte real: "estrelas quebradas"): a 1ª versão
// cortava a LINHA INTEIRA de 5 estrelas num único percentual da largura
// total — como a linha tem gap entre os ícones, o corte quase nunca cai
// numa borda de estrela; ele cai no meio de um glifo (produzindo uma
// forma estranha) ou dentro do espaço vazio de gap (parecendo quebrado/
// cortado errado). Corrigido pro padrão certo desse componente: cada
// estrela tem seu PRÓPRIO par empty/filled empilhado e seu próprio
// percentual de preenchimento (0/50/100% normalmente, fração exata só na
// estrela onde a média realmente "quebra") — o gap entre estrelas fica
// fora da conta de cada uma, nunca corta no vazio.
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

const clampedValue = computed(() => Math.max(0, Math.min(5, Number(props.value) || 0)));

const starFill = (star) => Math.max(0, Math.min(100, (clampedValue.value - (star - 1)) * 100));
</script>

<template>
    <span class="inline-flex items-center gap-0.5" :class="size" :aria-label="`${clampedValue.toFixed(1)} de 5 estrelas`">
        <span v-for="star in 5" :key="star" class="relative inline-block">
            <i class="far fa-star text-store-fg-faint"></i>
            <span class="absolute left-0 top-0 h-full overflow-hidden" :style="{ width: starFill(star) + '%' }">
                <i class="fas fa-star text-amber-400"></i>
            </span>
        </span>
    </span>
</template>
