<script setup>
import { computed } from 'vue';

// Termômetro de Oportunidades › KazaKora: o mercúrio sobe com a pontuação
// (0-100) e a cor segue a fase. Os pesos estão em TermometroOportunidade.php.
const props = defineProps({
    pontuacao: { type: Number, default: 0 },
    fase: { type: String, default: 'teste' },
    grande: { type: Boolean, default: false },
});

const CORES = { teste: '#3b82f6', validando: '#f59e0b', escala: '#ef4444', saturando: '#94a3b8' };
const cor = computed(() => CORES[props.fase] ?? CORES.teste);
const nivel = computed(() => Math.max(0, Math.min(100, props.pontuacao)));
// Tubo de y=8 a y=78 (70 px); o mercúrio sai de dentro do bulbo.
const topo = computed(() => 78 - (nivel.value / 100) * 70);
const id = `kzt-${Math.random().toString(36).slice(2, 8)}`;
</script>

<template>
    <svg :viewBox="'0 0 32 104'" :class="grande ? 'h-40 w-12' : 'h-24 w-8'" role="img" :aria-label="`Termômetro: ${nivel} de 100`">
        <defs>
            <linearGradient :id="id" x1="0" y1="1" x2="0" y2="0">
                <stop offset="0%" stop-color="#3b82f6" />
                <stop offset="45%" stop-color="#f59e0b" />
                <stop offset="100%" stop-color="#ef4444" />
            </linearGradient>
        </defs>
        <!-- Tubo e bulbo vazios -->
        <rect x="10" y="4" width="12" height="80" rx="6" fill="var(--surface-muted)" stroke="var(--surface-border)" stroke-width="1.5" />
        <circle cx="16" cy="88" r="11" fill="var(--surface-muted)" stroke="var(--surface-border)" stroke-width="1.5" />
        <!-- Marcas de 25 em 25 -->
        <g stroke="var(--surface-border)" stroke-width="1.2">
            <line v-for="m in [0, 25, 50, 75, 100]" :key="m" x1="23" x2="27" :y1="78 - m * 0.7" :y2="78 - m * 0.7" />
        </g>
        <!-- Mercúrio -->
        <rect x="13" :y="topo" width="6" :height="90 - topo" rx="3" :fill="`url(#${id})`" />
        <circle cx="16" cy="88" r="8" :fill="cor" />
    </svg>
</template>
