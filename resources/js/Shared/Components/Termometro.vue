<script setup>
import { computed } from 'vue';

// Termômetro das oportunidades (KazaKora e radar de PDF): o mercúrio sobe com a
// pontuação (0-100) e a cor segue a fase. Os pesos estão em
// app/Modules/Admin/Support/TermometroOportunidade.php.
// `icone`: versão de ~20px para ficar ao lado do título/selo da fase; desenho
// próprio (tubo grosso, mercúrio liso na cor da fase, sem marcas) porque o
// desenho grande encolhido vira um risco e o nível some.
const props = defineProps({
    pontuacao: { type: Number, default: 0 },
    fase: { type: String, default: 'teste' },
    grande: { type: Boolean, default: false },
    icone: { type: Boolean, default: false },
});

const CORES = { teste: '#3b82f6', validando: '#f59e0b', escala: '#ef4444', saturando: '#94a3b8' };
const ROTULOS = { teste: 'Em teste', validando: 'Validando', escala: 'Em escala', saturando: 'Saturando' };
const cor = computed(() => CORES[props.fase] ?? CORES.teste);
const nivel = computed(() => Math.max(0, Math.min(100, Number(props.pontuacao) || 0)));
const rotulo = computed(() => `Termômetro: ${nivel.value.toLocaleString('pt-BR')} de 100 · ${ROTULOS[props.fase] ?? props.fase}`);
// Tubo de y=8 a y=78 (70 px); o mercúrio sai de dentro do bulbo.
const topo = computed(() => 78 - (nivel.value / 100) * 70);
// Ícone: tubo útil de y=2,4 a y=15 (12,6 unidades).
const topoIcone = computed(() => 15 - (nivel.value / 100) * 12.6);
const id = `kzt-${Math.random().toString(36).slice(2, 8)}`;
</script>

<template>
    <svg v-if="icone" viewBox="0 0 12 24" class="inline-block h-5 w-2.5 shrink-0 align-middle" role="img" :aria-label="rotulo">
        <title>{{ rotulo }}</title>
        <!-- Contorno do tubo e do bulbo; o miolo cobre a costura entre os dois. -->
        <rect x="2.5" y="0.8" width="7" height="16" rx="3.5" fill="var(--surface, #fff)" stroke="#64748b" stroke-width="1.3" />
        <circle cx="6" cy="18.6" r="4.7" fill="var(--surface, #fff)" stroke="#64748b" stroke-width="1.3" />
        <rect x="3.15" y="13" width="5.7" height="4" fill="var(--surface, #fff)" />
        <!-- Mercúrio na cor da fase, da base até o nível -->
        <rect x="4.2" :y="topoIcone" width="3.6" :height="19 - topoIcone" rx="1.8" :fill="cor" />
        <circle cx="6" cy="18.6" r="3.3" :fill="cor" />
    </svg>
    <svg v-else viewBox="0 0 32 104" :class="grande ? 'h-40 w-12' : 'h-24 w-8'" role="img" :aria-label="rotulo">
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
