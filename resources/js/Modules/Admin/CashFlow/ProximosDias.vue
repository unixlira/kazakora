<script setup>
/**
 * Bloco 6: próximos 30 dias, em ordem de data. Saída em vermelho com "-",
 * entrada em verde. DAS pelo imposto() do mês (estimativa), carteiras e
 * repasses pelo PrevisaoDeCaixa (o mesmo serviço do "Resultado e margem").
 */
import { formatPrice } from '@/Shared/graficos';

defineProps({
    previsao: { type: Object, default: () => ({ itens: [] }) },
    hoje: { type: String, required: true },
});

const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
const dia = (iso) => iso.slice(8, 10);
const mes = (iso) => MESES[Number(iso.slice(5, 7)) - 1];
</script>

<template>
    <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="fc-proximos">
        <h2 id="fc-proximos" class="font-semibold">Próximos 30 dias</h2>
        <p class="text-xs text-[var(--texto-suave)]">O que vence e o que deve entrar. Valores estimados marcados.</p>
        <ul v-if="previsao.itens?.length" class="mt-2">
            <li v-for="(item, i) in previsao.itens" :key="i" class="grid grid-cols-[56px_1fr_auto] items-center gap-3 border-b border-[var(--surface-muted)] py-2.5 last:border-0">
                <span class="rounded-lg bg-[var(--surface-muted)] py-1 text-center leading-tight">
                    <template v-if="item.data === hoje"><b class="text-sm">hoje</b></template>
                    <template v-else-if="item.ate"><b class="text-sm tabular-nums">{{ dia(item.data) }}–{{ dia(item.ate) }}</b><small class="block text-[10px] text-[var(--texto-suave)]">{{ mes(item.ate) }}</small></template>
                    <template v-else><b class="text-sm tabular-nums">{{ dia(item.data) }}</b><small class="block text-[10px] text-[var(--texto-suave)]">{{ mes(item.data) }}</small></template>
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-medium">{{ item.titulo }}<span v-if="item.estimado" class="ml-1 rounded border border-[var(--surface-border)] px-1 text-[10px] font-bold text-[var(--texto-suave)]">estimado</span></span>
                    <span v-if="item.detalhe" class="block truncate text-xs text-[var(--texto-suave)]" :title="item.detalhe">{{ item.detalhe }}</span>
                </span>
                <span class="text-right text-sm font-bold tabular-nums" :class="item.valor < 0 ? 'valor-saida' : 'valor-entrada'">
                    {{ item.valor < 0 ? '- ' : '' }}{{ formatPrice(Math.abs(item.valor)) }}
                </span>
            </li>
        </ul>
        <p v-else class="mt-4 text-sm text-[var(--texto-suave)]">Nada previsto para os próximos 30 dias.</p>
    </section>
</template>

<style scoped>
.valor-saida { color: #b91c1c; }
.valor-entrada { color: #047857; }
:global(.dark) .valor-saida { color: #f87171; }
:global(.dark) .valor-entrada { color: #34d399; }
</style>
