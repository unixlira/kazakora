<script setup>
/**
 * Gráfico pequeno da evolução do saldo de um canal (fotos de hora em hora).
 * Eixo X é o tempo de verdade: hora sem coleta vira buraco na linha, não
 * some. Disponível na cor do canal; a receber tracejado em cinza.
 */
import { formatPrice } from '@/Shared/graficos';
import { computed } from 'vue';

const props = defineProps({
    serie: { type: Array, default: () => [] },
    cor: { type: String, required: true },
    dias: { type: Number, default: 7 },
    agora: { type: String, required: true },
    nome: { type: String, required: true },
});

const L = 300;
const A = 64;
const PAD = 3;
const HORA = 3600 * 1000;

const fim = computed(() => new Date(props.agora).getTime());
const inicio = computed(() => fim.value - props.dias * 24 * HORA);

const pontos = computed(() => props.serie.map((p) => ({ t: new Date(p.t).getTime(), d: p.disponivel, r: p.a_receber })));

const escala = computed(() => {
    const valores = pontos.value.flatMap((p) => [p.d, p.r]).filter((v) => v !== null && v !== undefined);
    const max = Math.max(...valores, 1);
    const min = Math.min(...valores, 0);
    return { min, max: max === min ? min + 1 : max };
});

const x = (t) => ((t - inicio.value) / (fim.value - inicio.value)) * L;
const y = (v) => A - PAD - ((v - escala.value.min) / (escala.value.max - escala.value.min)) * (A - PAD * 2);

// Quebra a linha quando falta valor ou quando passam mais de 3 h entre fotos.
const caminho = (campo) => {
    let d = '';
    let anterior = null;
    for (const p of pontos.value) {
        const v = p[campo];
        if (v === null || v === undefined) { anterior = null; continue; }
        const continua = anterior !== null && p.t - anterior <= 3 * HORA;
        d += `${continua ? 'L' : 'M'}${x(p.t).toFixed(1)},${y(v).toFixed(1)}`;
        anterior = p.t;
    }
    return d;
};

const linhaDisponivel = computed(() => caminho('d'));
const linhaReceber = computed(() => caminho('r'));

const resumo = (campo) => {
    const vals = pontos.value.map((p) => p[campo]).filter((v) => v !== null && v !== undefined);
    if (!vals.length) return null;
    return { de: vals[0], ate: vals[vals.length - 1] };
};
const descricao = computed(() => {
    const d = resumo('d');
    const r = resumo('r');
    const partes = [];
    if (d) partes.push(`disponível de ${formatPrice(d.de)} para ${formatPrice(d.ate)}`);
    if (r) partes.push(`a receber de ${formatPrice(r.de)} para ${formatPrice(r.ate)}`);
    return `${props.nome}, últimos ${props.dias} dias: ${partes.join('; ') || 'sem dados'}.`;
});

const temGrafico = computed(() => pontos.value.length >= 2);
</script>

<template>
    <figure class="m-0">
        <svg v-if="temGrafico" :viewBox="`0 0 ${L} ${A}`" preserveAspectRatio="none" class="block h-16 w-full" role="img" :aria-label="descricao">
            <line x1="0" :y1="A - 0.5" :x2="L" :y2="A - 0.5" stroke="var(--surface-border)" stroke-width="1" vector-effect="non-scaling-stroke" />
            <path :d="linhaReceber" fill="none" stroke="var(--texto-suave)" stroke-width="1.5" stroke-dasharray="4 3" vector-effect="non-scaling-stroke" stroke-linejoin="round" />
            <path :d="linhaDisponivel" fill="none" :stroke="cor" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" />
        </svg>
        <p v-else class="flex h-16 items-center justify-center rounded-lg bg-[var(--surface-muted)] px-3 text-center text-xs text-[var(--texto-suave)]">
            O gráfico aparece a partir da segunda coleta.
        </p>
        <figcaption class="mt-1 flex flex-wrap justify-between gap-x-3 text-[11px] text-[var(--texto-suave)]">
            <span class="flex gap-3">
                <span class="inline-flex items-center gap-1"><i class="inline-block h-0.5 w-3 rounded" :style="{ background: cor }" aria-hidden="true"></i>disponível</span>
                <span class="inline-flex items-center gap-1"><i class="inline-block w-3 border-t-[1.5px] border-dashed border-[var(--texto-suave)]" aria-hidden="true"></i>a receber</span>
            </span>
            <span>{{ dias }} dias</span>
        </figcaption>
    </figure>
</template>
