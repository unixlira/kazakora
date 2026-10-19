<script setup>
/**
 * Bloco 5: evolução no período. Colunas = receita (cinza de contexto),
 * linha forte = margem de contribuição (acento), linha fina tracejada =
 * receita no mesmo ponto do período de comparação. Um eixo só, em R$.
 * Cada ponto é ContributionMargin::serie(), a mesma conta do resto.
 */
import ChartCanvas from '@/Shared/Components/ChartCanvas.vue';
import { diaMes, formatCompact, formatPrice, useTemaEscuro } from '@/Shared/graficos';
import { computed, ref } from 'vue';

const props = defineProps({
    serie: { type: Array, default: () => [] },
    agrupar: { type: String, default: 'dia' },
    rotuloComparacao: { type: String, default: null },
});
const emit = defineEmits(['agrupar']);

const { chrome, tooltip, axisStyle, isDark } = useTemaEscuro();
const verTabela = ref(false);

const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
const rotulo = (b) => {
    if (props.agrupar === 'mes') return `${MESES[Number(b.inicio.slice(5, 7)) - 1]}/${b.inicio.slice(2, 4)}`;
    if (props.agrupar === 'semana') return `${diaMes(b.inicio)}`;
    return diaMes(b.inicio);
};
const rotuloLongo = (b) => (b.inicio === b.fim ? diaMes(b.inicio) : `${diaMes(b.inicio)} a ${diaMes(b.fim)}`);
const temComparacao = computed(() => props.serie.some((b) => b.comparacao !== null));
const vazio = computed(() => !props.serie.some((b) => b.receita !== 0 || b.margem !== 0));

const dados = computed(() => ({
    labels: props.serie.map(rotulo),
    datasets: [
        {
            type: 'line', label: 'Margem de contribuição', data: props.serie.map((b) => b.margem), borderColor: chrome.value.accent,
            backgroundColor: chrome.value.accent, borderWidth: 2.5, tension: 0.3, pointRadius: props.serie.length > 40 ? 0 : 2.5, pointHoverRadius: 5, order: 1,
        },
        ...(temComparacao.value ? [{
            type: 'line', label: 'Receita na comparação', data: props.serie.map((b) => b.comparacao), borderColor: isDark.value ? '#a9adb2' : '#5b6066',
            borderWidth: 1.25, borderDash: [5, 4], tension: 0.3, pointRadius: 0, pointHoverRadius: 4, order: 2,
        }] : []),
        {
            type: 'bar', label: 'Receita', data: props.serie.map((b) => b.receita), backgroundColor: chrome.value.context,
            borderRadius: 3, borderSkipped: 'start', maxBarThickness: 28, order: 3,
        },
    ],
}));

const opcoes = computed(() => ({
    animation: { duration: 450 },
    interaction: { mode: 'index', intersect: false },
    scales: {
        x: { ...axisStyle.value, grid: { display: false }, ticks: { ...axisStyle.value.ticks, maxTicksLimit: 12, autoSkip: true, maxRotation: 0 } },
        y: { ...axisStyle.value, ticks: { ...axisStyle.value.ticks, callback: (v) => formatCompact(v) } },
    },
    plugins: {
        legend: { display: false },
        tooltip: {
            ...tooltip.value,
            // Uma linha só por ponto (a da margem); o resto vem no corpo.
            filter: (item) => item.datasetIndex === 0,
            callbacks: {
                title: (items) => rotuloLongo(props.serie[items[0].dataIndex]),
                label: (ctx) => ` Margem: ${formatPrice(ctx.parsed.y)}`,
                afterBody: (items) => {
                    const b = props.serie[items[0].dataIndex];
                    const linhas = [`Receita: ${formatPrice(b.receita)}`, `Lucro: ${formatPrice(b.lucro)}`, `ADS: ${formatPrice(b.ads)}`];
                    if (b.comparacao !== null) linhas.push(`Receita em ${rotuloLongo({ inicio: b.comparacaoInicio, fim: b.comparacaoInicio })}: ${formatPrice(b.comparacao)}`);
                    return linhas;
                },
            },
        },
    },
}));

const AGRUPAMENTOS = [['dia', 'Dia'], ['semana', 'Semana'], ['mes', 'Mês']];
const totalReceita = computed(() => props.serie.reduce((s, b) => s + b.receita, 0));
const totalComparacao = computed(() => props.serie.reduce((s, b) => s + (b.comparacao ?? 0), 0));
</script>

<template>
    <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="fc-evolucao">
        <div class="mb-2 flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 id="fc-evolucao" class="font-semibold">Evolução no período</h2>
                <p class="text-xs text-[var(--texto-suave)]">
                    Receita <span class="font-semibold tabular-nums text-[var(--surface-foreground)]">{{ formatPrice(totalReceita) }}</span>
                    <template v-if="temComparacao"> contra <span class="tabular-nums">{{ formatPrice(totalComparacao) }}</span> no {{ rotuloComparacao }}</template>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <div class="flex overflow-hidden rounded-lg border border-[var(--surface-border)]" role="group" aria-label="Agrupar por">
                    <button v-for="[k, r] in AGRUPAMENTOS" :key="k" type="button" class="px-2.5 py-1 text-xs font-medium"
                        :class="agrupar === k ? 'bg-[var(--surface-foreground)] text-[var(--surface)]' : 'hover:bg-[var(--surface-muted)]'"
                        :aria-pressed="agrupar === k" @click="emit('agrupar', k)">{{ r }}</button>
                </div>
                <button type="button" class="text-xs font-semibold text-[var(--destaque)] hover:underline" :aria-expanded="verTabela" @click="verTabela = !verTabela">
                    {{ verTabela ? 'Ver gráfico' : 'Ver em tabela' }}
                </button>
            </div>
        </div>
        <div class="mb-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-[var(--texto-suave)]">
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" :style="{ background: chrome.context }"></span>Receita</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4" :style="{ background: chrome.accent }"></span>Margem de contribuição</span>
            <span v-if="temComparacao" class="inline-flex items-center gap-1.5"><span class="w-4 border-t-2 border-dashed" :style="{ borderColor: isDark ? '#a9adb2' : '#5b6066' }"></span>Receita na comparação</span>
        </div>
        <p v-if="vazio" class="py-12 text-center text-sm text-[var(--texto-suave)]">Nenhuma venda paga neste período.</p>
        <div v-else-if="verTabela" class="max-h-80 overflow-auto">
            <table class="w-full text-sm tabular-nums">
                <thead class="sticky top-0 bg-[var(--surface)]">
                    <tr class="border-b border-[var(--surface-border)] text-[11px] text-[var(--texto-suave)]">
                        <th scope="col" class="py-1.5 text-left font-semibold">Período</th>
                        <th scope="col" class="px-2 py-1.5 text-right font-semibold">Receita</th>
                        <th scope="col" class="px-2 py-1.5 text-right font-semibold">Margem</th>
                        <th scope="col" class="px-2 py-1.5 text-right font-semibold">Lucro</th>
                        <th scope="col" class="px-2 py-1.5 text-right font-semibold">ADS</th>
                        <th v-if="temComparacao" scope="col" class="px-2 py-1.5 text-right font-semibold">Receita na comparação</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="b in serie" :key="b.inicio" class="border-b border-[var(--surface-muted)]">
                        <th scope="row" class="py-1 text-left font-medium">{{ rotuloLongo(b) }}</th>
                        <td class="px-2 py-1 text-right">{{ formatPrice(b.receita) }}</td>
                        <td class="px-2 py-1 text-right">{{ formatPrice(b.margem) }}</td>
                        <td class="px-2 py-1 text-right" :class="b.lucro < 0 ? 'text-error' : ''">{{ formatPrice(b.lucro) }}</td>
                        <td class="px-2 py-1 text-right">{{ formatPrice(b.ads) }}</td>
                        <td v-if="temComparacao" class="px-2 py-1 text-right">{{ b.comparacao === null ? '—' : formatPrice(b.comparacao) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <ChartCanvas v-else type="bar" :data="dados" :options="opcoes" height-class="h-72" label="Receita e margem de contribuição ao longo do período" />
    </section>
</template>
