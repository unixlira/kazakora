<script setup>
/**
 * Aba "Hoje e mês" de Indicadores (2026-10-19): o topo do antigo
 * Gerenciador, sem mudar a conta. Cartões de hoje e do mês, desempenho do
 * mês, ritmo, faturamento por categoria, mês a mês por canal e a faixa de
 * faturamento por canal. O cartão de lucro só vem do servidor para quem tem
 * financeiro.view (ver PainelGerencial::hojeEMes()).
 */
import CardStats from '@/Shared/Components/CardStats.vue';
import ChartCanvas from '@/Shared/Components/ChartCanvas.vue';
import { Link } from '@inertiajs/vue3';
import { usePermissions } from '@/Shared/usePermissions';
import {
    CHANNEL_ORDER, channelName, formatCompact, formatInt, formatPercent, formatPrice, hojeSaoPaulo, sortChannels, useTemaEscuro,
} from '@/Shared/graficos';
import { computed } from 'vue';

const props = defineProps({
    hojeMes: { type: Object, required: true },
});

const { can } = usePermissions();
const { isDark, chrome, channelColor, tooltip, axisStyle } = useTemaEscuro();

const stats = computed(() => props.hojeMes?.stats ?? {});
const analytics = computed(() => props.hojeMes?.analytics ?? { kpis: {}, ritmo: { dia: 0, atual: [], anterior: [] }, mensal: [], categorias: [] });
const revenueByChannel = computed(() => props.hojeMes?.revenueByChannel ?? []);
const temLucro = computed(() => stats.value.netProfitMonth !== undefined && stats.value.netProfitMonth !== null);

const formatMonth = (yearMonth) =>
    new Intl.DateTimeFormat('pt-BR', { month: 'short', year: '2-digit' }).format(new Date(`${yearMonth}-01T12:00:00`)).replace('.', '');

const kpis = computed(() => analytics.value.kpis ?? {});
const delta = (agora, antes) => (antes > 0 ? Math.round(((agora - antes) / antes) * 1000) / 10 : null);

const kpiTiles = computed(() => [
    { label: 'Ticket médio', value: formatPrice(kpis.value.ticketMedio), delta: delta(kpis.value.ticketMedio, kpis.value.ticketMedioAnterior), hint: 'vs mesmos dias do mês passado', icon: 'fas fa-receipt', color: 'var(--primary-solido)' },
    { label: 'Projeção do mês', value: formatPrice(kpis.value.projecaoMes), delta: delta(kpis.value.projecaoMes, kpis.value.receitaMesAnterior), hint: 'no ritmo atual, vs mês passado fechado', icon: 'fas fa-bullseye', color: 'var(--color-info)' },
    { label: 'Itens por pedido', value: (kpis.value.itensPorPedido ?? 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 }), delta: delta(kpis.value.itensPorPedido, kpis.value.itensPorPedidoAnterior), hint: 'vs mesmos dias do mês passado', icon: 'fas fa-boxes-stacked', color: 'var(--color-secondary)' },
]);

const crescimentoNota = computed(() => {
    const c = kpis.value.crescimento;
    if (c === null || c === undefined) return 'sem base no mês passado';
    return `${c > 0 ? '+' : ''}${formatPercent(c)} vs mesmos dias do mês passado`;
});

const tileStyle = (color) => ({
    borderTop: '3px solid var(--faixa-card)',
    background: `linear-gradient(135deg, color-mix(in oklab, ${color} 10%, var(--surface)) 0%, var(--surface) 70%)`,
});

const baseOptions = computed(() => ({ animation: { duration: 600 }, plugins: { legend: { display: false }, tooltip: tooltip.value } }));

// ---- Faturamento por canal (faixa vinho) ------------------------------
const channelStrip = computed(() => {
    const total = revenueByChannel.value.reduce((sum, row) => sum + row.total, 0);
    const extras = revenueByChannel.value.map((row) => row.origin).filter((o) => !CHANNEL_ORDER.includes(o));

    return [...CHANNEL_ORDER, ...extras].map((origin) => {
        const valor = revenueByChannel.value.find((row) => row.origin === origin)?.total ?? 0;
        return { origin, total: valor, share: total > 0 ? (valor / total) * 100 : 0 };
    });
});

// ---- Faturamento mês a mês por canal ----------------------------------
const currentMonth = hojeSaoPaulo().slice(0, 7);
const mensal = computed(() => analytics.value.mensal ?? []);
const monthlyChannels = computed(() => sortChannels([...new Set(mensal.value.flatMap((m) => Object.keys(m.canais)))])
    .filter((channel) => mensal.value.some((m) => (m.canais[channel] ?? 0) > 0)));
const monthlyChartData = computed(() => ({
    labels: mensal.value.map((m, i, todos) => (i === todos.length - 1 && m.mes === currentMonth ? `${formatMonth(m.mes)} (parcial)` : formatMonth(m.mes))),
    datasets: monthlyChannels.value.map((channel, index) => ({
        label: channelName(channel),
        data: mensal.value.map((m) => m.canais[channel] ?? 0),
        backgroundColor: channelColor(channel),
        borderColor: chrome.value.surface,
        borderWidth: { top: 2 },
        borderSkipped: 'start',
        borderRadius: index === monthlyChannels.value.length - 1 ? { topLeft: 4, topRight: 4 } : 0,
        maxBarThickness: 24,
    })),
}));
const monthlyChartOptions = computed(() => ({
    ...baseOptions.value,
    interaction: { mode: 'index', intersect: false },
    scales: {
        x: { ...axisStyle.value, stacked: true, grid: { display: false } },
        y: { ...axisStyle.value, stacked: true, ticks: { ...axisStyle.value.ticks, callback: (v) => formatCompact(v) } },
    },
    plugins: {
        ...baseOptions.value.plugins,
        tooltip: {
            ...tooltip.value,
            callbacks: {
                label: (ctx) => ` ${ctx.dataset.label}: ${formatPrice(ctx.parsed.y)}`,
                footer: (items) => `Total: ${formatPrice(items.reduce((sum, item) => sum + item.parsed.y, 0))}`,
            },
        },
    },
}));

// ---- Ritmo do mês ------------------------------------------------------
const ritmo = computed(() => analytics.value.ritmo ?? { dia: 0, atual: [], anterior: [] });
const paceChartData = computed(() => {
    const dias = Math.max(ritmo.value.anterior.length, ritmo.value.atual.length);
    return {
        labels: Array.from({ length: dias }, (_, i) => `${i + 1}`),
        datasets: [
            {
                label: 'Este mês', data: ritmo.value.atual, borderColor: chrome.value.accent, backgroundColor: `${chrome.value.accent}1a`, fill: true, borderWidth: 2, tension: 0.3,
                pointRadius: (ctx) => (ctx.dataIndex === ritmo.value.atual.length - 1 ? 5 : 0), pointBackgroundColor: chrome.value.accent, pointBorderColor: chrome.value.surface, pointBorderWidth: 2, pointHoverRadius: 6,
            },
            { label: 'Mês passado', data: ritmo.value.anterior, borderColor: chrome.value.context, borderWidth: 2, tension: 0.3, pointRadius: 0, pointHoverRadius: 5, fill: false },
        ],
    };
});
const paceChartOptions = computed(() => ({
    ...baseOptions.value,
    interaction: { mode: 'index', intersect: false },
    scales: {
        x: { ...axisStyle.value, grid: { display: false }, ticks: { ...axisStyle.value.ticks, maxTicksLimit: 8 } },
        y: { ...axisStyle.value, ticks: { ...axisStyle.value.ticks, callback: (v) => formatCompact(v) } },
    },
    plugins: {
        ...baseOptions.value.plugins,
        tooltip: { ...tooltip.value, callbacks: { title: (items) => `Dia ${items[0].label}`, label: (ctx) => ` ${ctx.dataset.label}: ${formatPrice(ctx.parsed.y)}` } },
    },
}));
const paceComparison = computed(() => {
    const hoje = ritmo.value.atual.at(-1) ?? 0;
    const antes = ritmo.value.anterior[ritmo.value.atual.length - 1] ?? 0;
    return { hoje, antes, delta: delta(hoje, antes) };
});

// ---- Faturamento por categoria ----------------------------------------
const categories = computed(() => analytics.value.categorias ?? []);
const categoriesTotal = computed(() => categories.value.reduce((sum, c) => sum + c.receita, 0));
const categoryChartData = computed(() => ({
    labels: categories.value.map((c) => c.nome),
    datasets: [{ label: 'Faturamento', data: categories.value.map((c) => c.receita), backgroundColor: chrome.value.accent, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 18 }],
}));
const categoryChartOptions = computed(() => ({
    ...baseOptions.value,
    indexAxis: 'y',
    scales: {
        x: { ...axisStyle.value, ticks: { ...axisStyle.value.ticks, callback: (v) => formatCompact(v), maxTicksLimit: 4, autoSkipPadding: 24, maxRotation: 0 } },
        y: { ...axisStyle.value, grid: { display: false }, ticks: { ...axisStyle.value.ticks, color: isDark.value ? '#f1eced' : '#1f1a1c' } },
    },
    plugins: {
        ...baseOptions.value.plugins,
        tooltip: { ...tooltip.value, callbacks: { label: (ctx) => ` ${formatPrice(ctx.parsed.x)} (${formatPercent((ctx.parsed.x / categoriesTotal.value) * 100)})` } },
    },
}));
</script>

<template>
    <div>
        <h2 class="sr-only">Hoje e mês</h2>
        <p class="mb-4 text-sm text-[var(--texto-suave)]">Como a loja está hoje e no mês até agora, comparado com os mesmos dias do mês passado.</p>

        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <component :is="can('pedidos.view') ? Link : 'div'" v-bind="can('pedidos.view') ? { href: '/admin/pedidos' } : {}" class="block">
                <CardStats stat-subtitle="Faturamento hoje" :stat-title="formatPrice(stats.revenueToday)"
                    :stat-note="`${stats.ordersToday} ${stats.ordersToday === 1 ? 'pedido' : 'pedidos'}`"
                    stat-icon-name="fas fa-money-bill-wave" variant="info" vivid compact />
            </component>
            <component :is="can('pedidos.view') ? Link : 'div'" v-bind="can('pedidos.view') ? { href: '/admin/pedidos' } : {}" class="block">
                <CardStats stat-subtitle="Faturamento do mês" :stat-title="formatPrice(stats.revenue)" :stat-note="crescimentoNota"
                    stat-icon-name="fas fa-calendar-days" variant="primary" vivid compact />
            </component>
            <Link v-if="temLucro" href="/admin/fluxo-de-caixa" class="block">
                <CardStats stat-subtitle="Lucro líquido do mês" :stat-title="formatPrice(stats.netProfitMonth)"
                    :stat-note="kpis.margemPercentual === null || kpis.margemPercentual === undefined ? '' : `${formatPercent(kpis.margemPercentual)} do faturamento · já tirado o imposto de ${formatPrice(stats.taxMonth)}`"
                    stat-icon-name="fas fa-wallet" :variant="stats.netProfitMonth >= 0 ? 'success' : 'error'" vivid compact />
            </Link>
            <CardStats stat-subtitle="Faturamento desde a abertura" :stat-title="formatPrice(stats.revenueTotal)"
                stat-icon-name="fas fa-sack-dollar" variant="neutral" vivid compact />
        </div>

        <div class="mt-3 grid grid-cols-2 gap-3 sm:mt-4 sm:gap-4 xl:grid-cols-4">
            <CardStats stat-subtitle="Pedidos hoje" :stat-title="String(stats.ordersToday ?? 0)" stat-icon-name="fas fa-cart-shopping" variant="primary" vivid compact />
            <CardStats stat-subtitle="Pedidos no mês" :stat-title="String(stats.ordersMonth ?? 0)" stat-icon-name="fas fa-calendar-check" variant="secondary" vivid compact />
            <CardStats stat-subtitle="Produtos devolvidos no mês" :stat-title="String(stats.returnsMonth ?? 0)"
                stat-icon-name="fas fa-rotate-left" :variant="stats.returnsMonth > 0 ? 'warning' : 'neutral'" vivid compact />
            <CardStats stat-subtitle="Pedidos desde a abertura" :stat-title="formatInt(stats.ordersCount)" stat-icon-name="fas fa-receipt" variant="neutral" vivid compact />
        </div>

        <h3 class="mt-8 mb-3 flex items-center gap-2 text-lg font-bold">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-sm text-white" aria-hidden="true"><i class="fas fa-gauge-high"></i></span>
            Desempenho do mês
        </h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div v-for="tile in kpiTiles" :key="tile.label" class="flex min-w-0 flex-col rounded-xl border border-[var(--surface-border)] p-4 shadow-sm" :style="tileStyle(tile.color)">
                <div class="flex items-center justify-between gap-2">
                    <p class="truncate text-sm text-[var(--texto-suave)]">{{ tile.label }}</p>
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm text-white" :style="{ background: tile.color }" aria-hidden="true"><i :class="tile.icon"></i></span>
                </div>
                <p class="mt-1 truncate text-2xl font-bold tabular-nums">{{ tile.value }}</p>
                <p class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-[var(--texto-suave)]">
                    <span v-if="tile.delta !== null" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold tabular-nums"
                        :class="tile.delta >= 0 ? 'bg-lightsuccess text-success' : 'bg-lighterror text-error'">
                        <i :class="tile.delta >= 0 ? 'fas fa-arrow-up' : 'fas fa-arrow-down'" class="text-[10px]" aria-hidden="true"></i>
                        {{ tile.delta > 0 ? '+' : '' }}{{ formatPercent(tile.delta) }}
                    </span>
                    <span>{{ tile.hint }}</span>
                </p>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-5">
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm xl:col-span-3" aria-labelledby="hm-ritmo">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h3 id="hm-ritmo" class="text-base font-semibold">Ritmo do mês</h3>
                        <p class="text-xs text-[var(--texto-suave)]">Faturamento acumulado dia a dia</p>
                    </div>
                    <span v-if="paceComparison.delta !== null" class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold tabular-nums"
                        :class="paceComparison.delta >= 0 ? 'bg-lightsuccess text-success' : 'bg-lighterror text-error'">
                        <i :class="paceComparison.delta >= 0 ? 'fas fa-arrow-up' : 'fas fa-arrow-down'" class="text-[10px]" aria-hidden="true"></i>
                        {{ paceComparison.delta > 0 ? '+' : '' }}{{ formatPercent(paceComparison.delta) }} vs mês passado no dia {{ ritmo.dia }}
                    </span>
                </div>
                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4 rounded" :style="{ background: chrome.accent }"></span> Este mês · <span class="tabular-nums">{{ formatPrice(paceComparison.hoje) }}</span></span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4 rounded" :style="{ background: chrome.context }"></span> Mês passado · <span class="tabular-nums">{{ formatPrice(paceComparison.antes) }}</span> no mesmo dia</span>
                </div>
                <ChartCanvas v-if="paceComparison.hoje > 0 || ritmo.anterior.some((v) => v > 0)" class="mt-2" type="line" :data="paceChartData" :options="paceChartOptions" />
                <p v-else class="mt-6 text-sm text-[var(--texto-suave)]">Ainda sem vendas este mês.</p>
            </section>
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm xl:col-span-2" aria-labelledby="hm-categoria">
                <h3 id="hm-categoria" class="text-base font-semibold">Faturamento por categoria</h3>
                <p class="text-xs text-[var(--texto-suave)]">Últimos 90 dias</p>
                <ChartCanvas v-if="categories.length" class="mt-3" type="bar" :data="categoryChartData" :options="categoryChartOptions" />
                <p v-else class="mt-6 text-sm text-[var(--texto-suave)]">Sem vendas nos últimos 90 dias.</p>
            </section>
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm xl:col-span-5" aria-labelledby="hm-mensal">
                <h3 id="hm-mensal" class="text-base font-semibold">Faturamento mês a mês por canal</h3>
                <p class="text-xs text-[var(--texto-suave)]">Últimos 12 meses · passe o mouse para ver cada canal</p>
                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    <span v-for="channel in monthlyChannels" :key="channel" class="inline-flex items-center gap-1.5">
                        <span class="h-2.5 w-2.5 rounded-sm" :style="{ background: channelColor(channel) }"></span>
                        {{ channelName(channel) }}
                    </span>
                </div>
                <ChartCanvas v-if="mensal.length" class="mt-2" type="bar" :data="monthlyChartData" :options="monthlyChartOptions" />
                <p v-else class="mt-6 text-sm text-[var(--texto-suave)]">Sem vendas nos últimos 12 meses.</p>
            </section>
        </div>

        <!-- Faixa vinho (cores dos cards "Adapta", 2026-10-11). -->
        <section class="card-degrade card-degrade--vinho mt-8 p-4 text-white" aria-labelledby="hm-canal">
            <div class="flex items-center gap-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white/20 text-sm ring-1 ring-white/30" aria-hidden="true"><i class="fas fa-store"></i></span>
                <h3 id="hm-canal" class="text-base font-semibold">Faturamento por canal</h3>
                <span class="text-xs text-white/80">· mês até hoje</span>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
                <div v-for="row in channelStrip" :key="row.origin" class="min-w-0 rounded-lg bg-white/12 px-3 py-2.5 ring-1 ring-white/20">
                    <p class="flex items-center gap-1.5 truncate text-xs font-semibold text-white/90">
                        <span class="h-2 w-2 shrink-0 rounded-full" :style="{ background: channelColor(row.origin) }"></span>
                        {{ channelName(row.origin) }}
                    </p>
                    <p class="truncate text-lg font-bold tabular-nums" :class="row.total > 0 ? 'text-white' : 'text-white/70'">{{ formatPrice(row.total) }}</p>
                    <div class="mt-1 h-1 rounded-full bg-white/20">
                        <div class="h-1 rounded-full bg-white" :style="{ width: `${row.share}%` }"></div>
                    </div>
                    <p class="mt-1 text-[11px] text-white/80">{{ row.total > 0 ? `${formatPercent(row.share)} do mês` : 'sem vendas no mês' }}</p>
                </div>
            </div>
        </section>
    </div>
</template>

<style scoped>
.card-degrade {
    border-radius: 16px;
    box-shadow: inset 0 8px 12px 0 rgba(255, 255, 255, 0.1), 0 4px 14px rgba(15, 23, 42, 0.12);
}
.card-degrade--vinho {
    background: linear-gradient(87deg, #290c15 0%, #78233d 40%, #9b2743 74.52%, #db586e 100%);
}
</style>
