<script setup>
// Card de crescimento (pedido do Lira 2026-10-27): do dia 1º ao último dia
// do mês, se a campanha principal está subindo ou caindo e o nome dela. Um
// botão por campanha ativa, a mais nova à esquerda; clicar troca o gráfico.
// Dados do nosso banco (MetaAdsCrescimento), não chama a Meta.
import ChartCanvas from '@/Shared/Components/ChartCanvas.vue';
import { useTemaEscuro } from '@/Shared/graficos';
import { computed, ref, watch } from 'vue';
import { formatarNumero, formatarReais } from './metaAds';

const props = defineProps({
    crescimento: { type: Object, default: null },
});

const { chrome, tooltip, axisStyle } = useTemaEscuro();

const ROTULOS = { gasto: 'Gasto', compras: 'Compras', vendas: 'Vendas' };
const formatar = (metrica, v) => (metrica === 'compras' ? formatarNumero(v) : formatarReais(v));
const DIRECAO = {
    subindo: { texto: 'Subindo', icone: 'fa-arrow-trend-up', cor: 'text-success', fundo: 'bg-lightsuccess' },
    caindo: { texto: 'Caindo', icone: 'fa-arrow-trend-down', cor: 'text-error', fundo: 'bg-lighterror' },
    estavel: { texto: 'Parada', icone: 'fa-minus', cor: 'text-slate-600', fundo: 'bg-slate-100 dark:bg-slate-800' },
    cedo: { texto: 'Poucos dias para dizer', icone: 'fa-hourglass-half', cor: 'text-slate-600', fundo: 'bg-slate-100 dark:bg-slate-800' },
};

const campanhas = computed(() => props.crescimento?.campanhas ?? []);
const metrica = computed(() => props.crescimento?.metrica ?? 'vendas');
const escolhida = ref(props.crescimento?.principal ?? null);
watch(() => props.crescimento?.principal, (id) => { if (!campanhas.value.some((c) => c.meta_id === escolhida.value)) escolhida.value = id; });

const campanha = computed(() => campanhas.value.find((c) => c.meta_id === escolhida.value) ?? null);
const ehPrincipal = computed(() => campanha.value?.meta_id === props.crescimento?.principal);
const direcao = computed(() => DIRECAO[campanha.value?.tendencia?.direcao ?? 'cedo']);

const mesRotulo = computed(() => {
    const mes = props.crescimento?.mes;
    return mes ? new Date(`${mes}T12:00:00`).toLocaleDateString('pt-BR', { month: 'long', year: 'numeric', timeZone: 'America/Sao_Paulo' }) : '';
});
const hora = computed(() => {
    const em = props.crescimento?.atualizado_em;
    return em ? new Date(em).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' }) : null;
});

const dados = computed(() => {
    const c = campanha.value;
    const dias = props.crescimento?.dias ?? [];
    if (!c) return { labels: [], datasets: [] };
    const valores = c.serie[metrica.value] ?? [];
    const reta = c.tendencia?.reta;
    const linhaReta = reta
        ? dias.map((_, i) => (i < reta.de || i > reta.ate ? null : reta.inicio + ((reta.fim - reta.inicio) * (i - reta.de)) / Math.max(1, reta.ate - reta.de)))
        : [];
    return {
        labels: dias.map((d) => Number(d.slice(8, 10))),
        datasets: [
            {
                label: ROTULOS[metrica.value],
                data: valores,
                borderColor: chrome.value.accent,
                backgroundColor: chrome.value.accent,
                pointRadius: 2,
                tension: 0.25,
                spanGaps: false,
            },
            ...(reta ? [{
                label: 'Tendência',
                data: linhaReta,
                borderColor: chrome.value.context,
                borderDash: [6, 4],
                pointRadius: 0,
                spanGaps: false,
            }] : []),
        ],
    };
});

const opcoes = computed(() => ({
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { labels: { color: chrome.value.text, boxWidth: 12 } },
        tooltip: {
            ...tooltip.value,
            callbacks: {
                title: (itens) => `Dia ${itens[0].label}`,
                label: (item) => `${item.dataset.label}: ${formatar(metrica.value, item.raw)}`,
            },
        },
    },
    scales: {
        x: { ...axisStyle.value, grid: { display: false } },
        y: { ...axisStyle.value, beginAtZero: true, ticks: { ...axisStyle.value.ticks, callback: (v) => formatar(metrica.value, v) } },
    },
}));
</script>

<template>
    <section v-if="crescimento" class="mb-5 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="crescimento-titulo">
        <div class="mb-3 flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
            <div class="min-w-0">
                <h3 id="crescimento-titulo" class="text-base font-bold">Crescimento em {{ mesRotulo }}</h3>
                <p class="text-xs text-slate-500">
                    {{ ROTULOS[metrica] }} por dia, do dia 1º ao fim do mês. A linha tracejada é a tendência dos dias já fechados.
                    <template v-if="hora"> Dados gravados em {{ hora }}.</template>
                </p>
            </div>
        </div>

        <p v-if="!campanhas.length" class="rounded-lg bg-slate-500/5 p-3 text-sm text-slate-600 dark:text-slate-300" role="status">
            Nenhuma campanha ativa na Meta agora.
        </p>

        <template v-else>
            <div class="mb-3 flex flex-wrap gap-2" role="group" aria-label="Campanha do gráfico">
                <button v-for="c in campanhas" :key="c.meta_id" type="button" :aria-pressed="c.meta_id === escolhida"
                    class="inline-flex max-w-xs items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                    :class="c.meta_id === escolhida ? 'border-primary bg-primary text-white' : 'border-[var(--surface-border)] hover:bg-[var(--surface-muted)]'"
                    :title="c.nome" @click="escolhida = c.meta_id">
                    <i v-if="c.meta_id === crescimento.principal" class="fas fa-star text-[10px]" aria-hidden="true"></i>
                    <span class="truncate">{{ c.nome }}</span>
                    <span v-if="c.meta_id === crescimento.principal" class="sr-only">(principal)</span>
                </button>
            </div>

            <div v-if="campanha" class="mb-3 flex flex-wrap items-center gap-3">
                <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-bold" :class="[direcao.fundo, direcao.cor]">
                    <i class="fas text-xs" :class="direcao.icone" aria-hidden="true"></i> {{ direcao.texto }}
                </span>
                <p class="min-w-0 text-sm">
                    <span v-if="ehPrincipal" class="text-slate-500">Campanha principal: </span>
                    <strong>{{ campanha.nome }}</strong>
                </p>
                <p class="ml-auto text-xs tabular-nums text-slate-500">
                    No mês: {{ formatarReais(campanha.totais.gasto) }} gastos · {{ formatarNumero(campanha.totais.compras) }} compras · {{ formatarReais(campanha.totais.vendas) }} em vendas
                </p>
            </div>

            <ChartCanvas type="line" :data="dados" :options="opcoes" height-class="h-64"
                :label="campanha ? `${ROTULOS[metrica]} por dia de ${campanha.nome} em ${mesRotulo}: ${direcao.texto}` : ''" />
        </template>
    </section>
</template>
