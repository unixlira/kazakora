<script setup>
/**
 * Bloco 1: até 3 alertas à vista, o resto em "Ver todos os alertas (N)".
 * Prioridade escrita no rótulo (não só na cor). Regras e textos em
 * App\Modules\Financeiro\Support\AlertasFinanceiros.
 */
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    alertas: { type: Array, default: () => [] },
});

const todos = ref(false);
const visiveis = computed(() => (todos.value ? props.alertas : props.alertas.slice(0, 3)));
const cor = { urgente: 'var(--color-error)', aviso: 'var(--color-warning)', info: 'var(--color-info)' };
</script>

<template>
    <section v-if="alertas.length" class="mb-4" aria-label="Alertas" aria-live="polite">
        <ul class="grid gap-2">
            <li v-for="a in visiveis" :key="a.chave"
                class="alerta grid items-center gap-x-3 gap-y-1 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-4 py-2.5 shadow-sm"
                :style="{ borderLeft: `4px solid ${cor[a.nivel] ?? cor.aviso}` }">
                <span class="text-[11px] font-bold uppercase tracking-wide whitespace-nowrap" :style="{ color: cor[a.nivel] ?? cor.aviso }">{{ a.rotulo }}</span>
                <p class="min-w-0 text-sm text-pretty">{{ a.texto }}</p>
                <span v-if="a.links?.length" class="flex flex-wrap gap-x-3 gap-y-1">
                    <Link v-for="l in a.links" :key="l.href" :href="l.href" class="text-sm font-semibold whitespace-nowrap text-[var(--destaque)] hover:underline">{{ l.rotulo }}</Link>
                </span>
            </li>
        </ul>
        <button v-if="alertas.length > 3" type="button" class="mt-2 text-sm font-semibold text-[var(--destaque)] hover:underline" :aria-expanded="todos" @click="todos = !todos">
            {{ todos ? 'Mostrar menos' : `Ver todos os alertas (${alertas.length})` }}
        </button>
    </section>
</template>

<style scoped>
.alerta { grid-template-columns: auto 1fr auto; }
@media (max-width: 640px) {
    .alerta { grid-template-columns: 1fr; }
}
</style>
