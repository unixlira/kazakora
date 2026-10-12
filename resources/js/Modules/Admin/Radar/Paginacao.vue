<script setup>
import { computed } from 'vue';

// Números de página das tabelas do Radar, com reticências: 1 … 4 5 6 … 25.
// Cores vêm do .fonte-* do card (--th-fundo e --th-texto).
const props = defineProps({
    pagina: { type: Number, required: true },
    total: { type: Number, required: true },
    rotulo: { type: String, required: true },
});
const emit = defineEmits(['ir']);

const numeros = computed(() => {
    const lista = [];
    for (let n = 1; n <= props.total; n++) {
        if (n === 1 || n === props.total || Math.abs(n - props.pagina) <= 1) lista.push(n);
        else if (lista[lista.length - 1] !== '…') lista.push('…');
    }
    return lista;
});
</script>

<template>
    <nav v-if="total > 1" class="paginacao" :aria-label="rotulo">
        <button type="button" :disabled="pagina === 1" aria-label="Página anterior" @click="emit('ir', pagina - 1)">
            <i class="fas fa-chevron-left" aria-hidden="true"></i>
        </button>
        <template v-for="(n, i) in numeros" :key="`${n}-${i}`">
            <span v-if="n === '…'" class="reticencias" aria-hidden="true">…</span>
            <button v-else type="button" :class="{ atual: n === pagina }" :aria-current="n === pagina ? 'page' : null"
                :aria-label="`Página ${n}`" @click="emit('ir', n)">{{ n }}</button>
        </template>
        <button type="button" :disabled="pagina === total" aria-label="Próxima página" @click="emit('ir', pagina + 1)">
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
        </button>
    </nav>
</template>

<style scoped>
.paginacao {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 0.25rem;
    border-top: 1px solid var(--surface-border);
    padding: 0.75rem 1rem;
}
.paginacao button {
    min-width: 2rem;
    height: 2rem;
    border: 1px solid var(--surface-border);
    border-radius: 8px;
    background: var(--surface);
    padding: 0 0.5rem;
    font-size: 0.8125rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}
.paginacao button:hover:not(:disabled):not(.atual) { background: var(--surface-muted); }
.paginacao button:disabled { opacity: 0.4; cursor: not-allowed; }
.paginacao button.atual { border-color: var(--th-fundo); background: var(--th-fundo); color: var(--th-texto); }
.dark .paginacao button.atual { border-color: var(--th-texto); }
.paginacao .reticencias { padding: 0 0.25rem; color: var(--texto-suave); }
</style>
