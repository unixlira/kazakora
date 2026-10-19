<script setup>
/**
 * Bloco 7: quanto o número é confiável. Por canal, quanto da taxa já é real
 * (API, relatório, extrato, manual) e quanto ainda é estimada; embaixo, o
 * que falta (itens sem custo, notas de pedido cancelado, vendas sem nota).
 */
import { Link } from '@inertiajs/vue3';
import { formatPrice, useTemaEscuro } from '@/Shared/graficos';
import { computed } from 'vue';

const props = defineProps({
    qualidade: { type: Object, default: () => ({ canais: [], pendencias: {} }) },
    linkSemCusto: { type: String, required: true },
});

const { channelColor } = useTemaEscuro();
const linhas = computed(() => (props.qualidade.canais ?? [])
    .map((c) => {
        const total = c.real + c.estimada;
        return { ...c, total, pctReal: total > 0 ? (c.real / total) * 100 : 100 };
    })
    .sort((a, b) => b.pctReal - a.pctReal || b.total - a.total));
const p = computed(() => props.qualidade.pendencias ?? {});
const inteiro = (v) => Math.round(v).toLocaleString('pt-BR');
</script>

<template>
    <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="fc-confiavel">
        <h2 id="fc-confiavel" class="font-semibold">Quanto o número é confiável</h2>
        <p class="text-xs text-[var(--texto-suave)]">Parte da taxa de cada canal que já é real. O resto é estimativa e o lucro ainda pode mudar.</p>
        <ul v-if="linhas.length" class="mt-3 grid gap-2.5">
            <li v-for="l in linhas" :key="l.canal" class="grid grid-cols-[96px_1fr_auto] items-center gap-2 text-sm">
                <span class="inline-flex items-center gap-1.5 truncate"><i class="inline-block h-2.5 w-2.5 shrink-0 rounded-sm" :style="{ background: channelColor(l.canal) }" aria-hidden="true"></i>{{ l.nome }}</span>
                <span class="flex h-2.5 overflow-hidden rounded-full bg-[var(--surface-muted)]" role="img" :aria-label="`${l.nome}: ${inteiro(l.pctReal)}% real`">
                    <span class="barra-real h-full" :style="{ width: `${l.pctReal}%` }"></span>
                    <span class="barra-estimada h-full" :style="{ width: `${100 - l.pctReal}%` }"></span>
                </span>
                <span class="text-right text-xs tabular-nums whitespace-nowrap">
                    <b>{{ inteiro(l.pctReal) }}% real</b>
                    <span v-if="l.pedidos_estimada" class="block text-[var(--texto-suave)]">{{ l.pedidos_estimada }} estimados · {{ formatPrice(l.estimada) }}</span>
                </span>
            </li>
        </ul>
        <p v-else class="mt-4 text-sm text-[var(--texto-suave)]">Nenhuma taxa registrada neste período.</p>

        <ul class="mt-4 grid gap-1 border-t border-[var(--surface-border)] pt-3 text-sm">
            <li>
                <template v-if="p.semCusto"><Link :href="linkSemCusto" class="font-semibold text-[var(--destaque)] hover:underline">{{ p.semCusto }} {{ p.semCusto === 1 ? 'item vendido sem custo' : 'itens vendidos sem custo' }}</Link></template>
                <template v-else><i class="fas fa-check mr-1 text-success" aria-hidden="true"></i>Todo item vendido tem custo.</template>
            </li>
            <li>
                <template v-if="p.notasCanceladas?.quantidade"><Link href="/admin/notas-fiscais" class="font-semibold text-[var(--destaque)] hover:underline">{{ p.notasCanceladas.quantidade }} {{ p.notasCanceladas.quantidade === 1 ? 'nota autorizada de pedido cancelado' : 'notas autorizadas de pedidos cancelados' }}</Link> ({{ formatPrice(p.notasCanceladas.valor) }})</template>
                <template v-else><i class="fas fa-check mr-1 text-success" aria-hidden="true"></i>Nenhuma nota de pedido cancelado autorizada.</template>
            </li>
            <li>
                <template v-if="p.semNota">{{ p.semNota }} {{ p.semNota === 1 ? 'venda ainda sem nota' : 'vendas ainda sem nota' }} (o imposto delas usa a receita).</template>
                <template v-else><i class="fas fa-check mr-1 text-success" aria-hidden="true"></i>Toda venda tem nota.</template>
            </li>
        </ul>
    </section>
</template>

<style scoped>
.barra-real { background: #047857; }
.barra-estimada { background: #d97706; }
:global(.dark) .barra-real { background: #34d399; }
:global(.dark) .barra-estimada { background: #fbbf24; }
</style>
