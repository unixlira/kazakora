<script setup>
import Termometro from '@/Shared/Components/Termometro.vue';

// Selo da fase do termômetro (Oportunidades KazaKora e radar de PDF): o
// termometrinho mostra o nível real da pontuação, na cor da fase.
defineProps({
    pontuacao: { type: Number, default: 0 },
    fase: { type: String, default: 'teste' },
    rotulo: { type: String, default: null },
});

const CORES_FASE = { teste: 'bg-blue-50 text-blue-700', validando: 'bg-amber-50 text-amber-700', escala: 'bg-red-50 text-red-700', saturando: 'bg-slate-100 text-slate-600' };
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-full py-0.5 pl-1.5 pr-2 text-xs font-black" :class="CORES_FASE[fase] ?? CORES_FASE.teste">
        <Termometro icone :pontuacao="pontuacao" :fase="fase" />{{ rotulo ?? fase }}
    </span>
</template>
