<script setup>
/**
 * Cartão de número do Resumo (bloco 2): valor, linhas de apoio, "?" com a
 * fórmula em linguagem simples e a variação contra o período de comparação
 * (verde quando melhora, vermelho quando piora, sempre com sinal e texto).
 */
import { formatPercent } from '@/Shared/graficos';
import { computed } from 'vue';

const props = defineProps({
    rotulo: { type: String, required: true },
    valor: { type: String, required: true },
    apoio: { type: Array, default: () => [] },
    ajuda: { type: String, default: '' },
    atual: { type: Number, default: null },
    anterior: { type: Number, default: null },
    // 'alto' = subir é bom (lucro, vendas); 'baixo' = subir é ruim (ADS, imposto)
    bom: { type: String, default: 'alto' },
    rotuloComparacao: { type: String, default: null },
    hero: { type: Boolean, default: false },
    negativo: { type: Boolean, default: false },
});

const delta = computed(() => {
    if (props.anterior === null || props.atual === null || !props.rotuloComparacao || props.anterior === 0) return null;
    const v = ((props.atual - props.anterior) / Math.abs(props.anterior)) * 100;
    const melhora = props.bom === 'alto' ? v >= 0 : v <= 0;
    return { texto: `${v > 0 ? '+' : ''}${formatPercent(v)}`, melhora, sobe: v >= 0 };
});
</script>

<template>
    <div class="kpi min-w-0 rounded-xl border p-4 shadow-sm" :class="hero ? 'kpi-hero border-transparent text-white' : 'border-[var(--surface-border)] bg-[var(--surface)]'">
        <div class="flex items-center gap-1.5 text-xs font-semibold" :class="hero ? 'text-white/85' : 'text-[var(--texto-suave)]'">
            <span>{{ rotulo }}</span>
            <details v-if="ajuda" class="ajuda relative">
                <summary class="ajuda-botao" :aria-label="`Como é calculado: ${rotulo}`">?</summary>
                <p class="ajuda-texto" role="note">{{ ajuda }}</p>
            </details>
        </div>
        <p class="mt-1 font-bold tabular-nums whitespace-nowrap" :class="[hero ? 'text-[30px] sm:text-[34px]' : 'text-xl sm:text-2xl', negativo && !hero ? 'text-error' : '']">{{ valor }}</p>
        <p v-for="(linha, i) in apoio" :key="i" class="mt-0.5 text-xs tabular-nums" :class="hero ? 'text-white/85' : 'text-[var(--texto-suave)]'">
            <template v-if="typeof linha === 'string'">{{ linha }}</template>
            <template v-else>{{ linha.texto }}
                <details v-if="linha.ajuda" class="ajuda relative inline-block align-middle">
                    <summary class="ajuda-botao" :aria-label="`Como é calculado: ${linha.rotulo}`">?</summary>
                    <p class="ajuda-texto" role="note">{{ linha.ajuda }}</p>
                </details>
            </template>
        </p>
        <slot />
        <p v-if="delta" class="mt-1.5 text-xs font-semibold tabular-nums"
            :class="hero ? 'text-white' : delta.melhora ? 'text-[var(--cor-sobe)]' : 'text-[var(--cor-desce)]'">
            <i :class="delta.sobe ? 'fas fa-arrow-up' : 'fas fa-arrow-down'" class="text-[10px]" aria-hidden="true"></i>
            {{ delta.texto }} <span class="font-normal" :class="hero ? 'text-white/85' : 'text-[var(--texto-suave)]'">vs {{ rotuloComparacao }}</span>
        </p>
    </div>
</template>

<style scoped>
.kpi { --cor-sobe: #047857; --cor-desce: #b91c1c; }
:global(.dark) .kpi { --cor-sobe: #34d399; --cor-desce: #f87171; }
.kpi-hero { background: linear-gradient(120deg, #6d1f3f, #a3324f); }
.ajuda-botao {
    display: inline-grid;
    place-items: center;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 1px solid currentColor;
    font-size: 10px;
    cursor: help;
    list-style: none;
}
.ajuda-botao::-webkit-details-marker { display: none; }
.ajuda-botao:focus-visible { outline: 2px solid var(--foco, #9a3412); outline-offset: 2px; }
.ajuda-texto {
    position: absolute;
    z-index: 20;
    left: 0;
    top: 22px;
    width: min(260px, 70vw);
    padding: 8px 10px;
    border-radius: 8px;
    background: var(--surface-foreground);
    color: var(--surface);
    font-size: 12px;
    font-weight: 400;
    line-height: 1.4;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18);
}
</style>
