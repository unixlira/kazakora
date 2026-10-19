<script setup>
/**
 * Aba "Acessos ao site" de Indicadores (2026-10-19): o bloco de acessos do
 * kazakora.com do antigo Gerenciador, com o seletor de mês (?mes=). Sem
 * equipe e sem robô (PainelGerencial::visitantes()).
 */
import ChartCanvas from '@/Shared/Components/ChartCanvas.vue';
import { router } from '@inertiajs/vue3';
import { formatInt, hojeSaoPaulo, useTemaEscuro } from '@/Shared/graficos';
import { computed, ref } from 'vue';

const props = defineProps({
    visitantes: {
        type: Object,
        default: () => ({ mes: '', meses: [], mesAtual: true, dias: [], visitantesMes: 0, paginasMes: 0, visitantesHoje: 0, paginasHoje: 0, mediaDia: 0, melhorDia: null }),
    },
});

const { tooltip } = useTemaEscuro();
const baseOptions = computed(() => ({ animation: { duration: 600 }, plugins: { legend: { display: false }, tooltip: tooltip.value } }));

// ---- 5. Acessos ao site (pedido 2026-10-11) ---------------------------
// Ver DashboardController::visitantes(): sem equipe e sem robô.
// Card de acessos com fundo branco (pedido 2026-10-14): barras em verde-claro,
// hoje em verde forte (era amarelo), páginas vistas em laranja.
const visitColors = computed(() => ({ bar: '#a3d9b8', today: '#198754', line: '#f97316' }));
const mesVisitas = ref(props.visitantes?.mes ?? '');
const carregandoVisitas = ref(false);
const trocarMesVisitas = () => {
    router.get('/admin/indicadores', { aba: 'acessos', mes: mesVisitas.value }, {
        only: ['visitantes'],
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onStart: () => { carregandoVisitas.value = true; },
        onFinish: () => { carregandoVisitas.value = false; },
    });
};
const formatMonthLong = (yearMonth) => {
    const texto = new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric' }).format(new Date(`${yearMonth}-01T00:00:00`));
    return texto.charAt(0).toUpperCase() + texto.slice(1);
};
const formatDay = (date) => new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit' }).format(new Date(`${date}T00:00:00`));
const hojeIso = hojeSaoPaulo();

const visitTiles = computed(() => {
    const v = props.visitantes ?? {};
    const tiles = [];
    if (v.mesAtual) {
        tiles.push({ label: 'Visitantes hoje', value: formatInt(v.visitantesHoje), hint: `${formatInt(v.paginasHoje)} páginas vistas hoje`, icon: 'fas fa-user-clock', color: '#b45309' });
    }
    tiles.push(
        { label: v.mesAtual ? 'Visitantes no mês' : `Visitantes em ${formatMonthLong(v.mes)}`, value: formatInt(v.visitantesMes), hint: 'pessoas diferentes no mês', icon: 'fas fa-users', color: '#198754' },
        { label: 'Páginas vistas', value: formatInt(v.paginasMes), hint: v.visitantesMes ? `${(v.paginasMes / v.visitantesMes).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} páginas por visitante` : 'no mês', icon: 'fas fa-eye', color: '#3c4044' },
        { label: 'Média por dia', value: formatInt(Math.round(v.mediaDia ?? 0)), hint: v.melhorDia?.visitantes ? `melhor dia: ${formatDay(v.melhorDia.dia)} (${formatInt(v.melhorDia.visitantes)})` : 'visitantes por dia', icon: 'fas fa-chart-line', color: '#9a3412' },
    );
    return tiles;
});

const visitChartData = computed(() => {
    const dias = props.visitantes?.dias ?? [];
    const cores = visitColors.value;

    return {
        labels: dias.map((d) => d.dia.slice(8, 10)),
        datasets: [
            {
                type: 'bar',
                label: 'Visitantes',
                data: dias.map((d) => d.visitantes),
                backgroundColor: dias.map((d) => (d.dia === hojeIso ? cores.today : cores.bar)),
                borderRadius: 4,
                borderSkipped: 'start',
                maxBarThickness: 22,
                yAxisID: 'y',
                order: 2,
            },
            {
                type: 'line',
                label: 'Páginas vistas',
                data: dias.map((d) => d.paginas),
                borderColor: cores.line,
                backgroundColor: cores.line,
                borderWidth: 2,
                tension: 0.35,
                pointRadius: 0,
                pointHoverRadius: 5,
                yAxisID: 'y1',
                order: 1,
            },
        ],
    };
});

const visitChartOptions = computed(() => ({
    ...baseOptions.value,
    interaction: { mode: 'index', intersect: false },
    scales: {
        x: { grid: { display: false }, border: { display: false }, ticks: { color: '#6b7280', maxTicksLimit: 16 } },
        y: { beginAtZero: true, grid: { color: '#e5e7eb', drawTicks: false }, border: { display: false }, ticks: { color: '#6b7280', padding: 6, precision: 0 }, title: { display: true, text: 'Visitantes', color: '#6b7280' } },
        y1: { beginAtZero: true, position: 'right', grid: { display: false }, border: { display: false }, ticks: { color: '#6b7280', padding: 6, precision: 0 }, title: { display: true, text: 'Páginas', color: '#6b7280' } },
    },
    plugins: {
        ...baseOptions.value.plugins,
        tooltip: {
            ...baseOptions.value.plugins.tooltip,
            callbacks: {
                title: (items) => formatDay((props.visitantes?.dias ?? [])[items[0].dataIndex]?.dia ?? hojeIso),
                label: (ctx) => ` ${ctx.dataset.label}: ${formatInt(ctx.parsed.y)}`,
            },
        },
    },
}));

</script>

<template>
        <!-- Acessos ao site (pedido 2026-10-11), no fim da tela desde
             2026-10-14: o filtro de mês vale só para este bloco. -->
        <section class="card-degrade card-acessos overflow-hidden" aria-labelledby="acessos-titulo">
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 pt-4">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#198754] text-white"><i class="fas fa-globe" aria-hidden="true"></i></span>
                    <h2 id="acessos-titulo" class="text-lg font-semibold">Acessos ao site kazakora.com</h2>
                </div>
                <label for="acessos-mes" class="flex items-center gap-2 text-sm">
                    <i v-if="carregandoVisitas" class="fas fa-circle-notch fa-spin" aria-hidden="true"></i>
                    <span class="text-[#6b7280]">Mês <span class="text-[#9ca3af]">(só dos acessos)</span></span>
                    <select id="acessos-mes" v-model="mesVisitas" @change="trocarMesVisitas"
                        class="visitas-mes rounded-lg border-0 py-1.5 pl-3 pr-8 text-sm font-medium shadow-sm ring-1 ring-[#d1d5db] focus:ring-2 focus:ring-[#198754]/50">
                        <option v-for="m in visitantes.meses" :key="m" :value="m">{{ formatMonthLong(m) }}</option>
                    </select>
                </label>
            </div>
            <div class="grid grid-cols-2 gap-3 p-4 xl:grid-cols-4" :class="{ 'opacity-60': carregandoVisitas }">
                <div v-for="tile in visitTiles" :key="tile.label" class="min-w-0 rounded-lg bg-[#f8faf9] p-3 ring-1 ring-[#e5e7eb]">
                    <div class="flex items-center justify-between gap-2">
                        <p class="truncate text-xs font-semibold text-[#6b7280]">{{ tile.label }}</p>
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-sm shadow-sm" :style="{ color: tile.color }"><i :class="tile.icon" aria-hidden="true"></i></span>
                    </div>
                    <p class="mt-1 truncate text-3xl font-bold">{{ tile.value }}</p>
                    <p class="truncate text-xs text-[#6b7280]">{{ tile.hint }}</p>
                </div>
            </div>
            <div class="px-4 pb-4" :class="{ 'opacity-60': carregandoVisitas }">
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-[#6b7280]">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" :style="{ background: visitColors.bar }"></span> Visitantes</span>
                    <span v-if="visitantes.mesAtual" class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" :style="{ background: visitColors.today }"></span> Hoje</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4 rounded" :style="{ background: visitColors.line }"></span> Páginas vistas</span>
                </div>
                <ChartCanvas v-if="visitantes.visitantesMes > 0" class="mt-2" type="bar" :data="visitChartData" :options="visitChartOptions" />
                <p v-else class="mt-4 text-sm text-[#6b7280]">Nenhum acesso de cliente neste mês.</p>
            </div>
        </section>
</template>

<style scoped>
.card-degrade {
    border-radius: 16px;
    box-shadow: inset 0 8px 12px 0 rgba(255, 255, 255, 0.1), 0 4px 14px rgba(15, 23, 42, 0.12);
}
.card-acessos {
    /* Fundo branco nos dois temas (pedido 2026-10-14). */
    background: #ffffff;
    color: #1f1a1c;
    border: 1px solid #e5e7eb;
}
.visitas-mes,
.visitas-mes option {
    background-color: #ffffff;
    color: #1f1a1c;
}
</style>
