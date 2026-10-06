<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import ChartCanvas from '@/Shared/Components/ChartCanvas.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
    topProducts: {
        type: Array,
        default: () => [],
    },
    abcCurve: {
        type: Object,
        default: () => ({ total: 0, a: [], b: [] }),
    },
    revenueByChannel: {
        type: Array,
        default: () => [],
    },
    analytics: {
        type: Object,
        default: () => ({ kpis: {}, ritmo: { dia: 0, atual: [], anterior: [] }, mensal: [], categorias: [] }),
    },
});

// Curva ABC (pedido do usuário 2026-10-06): A = produtos que somam até 80%
// do faturamento, B = os seguintes até 95%. Ver DashboardController::curvaAbc().
const abcCards = computed(() => [
    { key: 'a', description: 'Somam 80% do faturamento', icon: 'fas fa-star', color: 'var(--color-success)', items: props.abcCurve?.a ?? [] },
    { key: 'b', description: 'Os próximos 15% do faturamento', icon: 'fas fa-layer-group', color: 'var(--color-info)', items: props.abcCurve?.b ?? [] },
]);

const CHANNEL_LABELS = {
    loja: 'Site',
    mercado_livre: 'Mercado Livre',
    shopee: 'Shopee',
    tiktok_shop: 'TikTok Shop',
    amazon: 'Amazon',
};

// Cor fixa por canal (nunca pela posição no ranking), na ordem da paleta
// categórica validada pra daltonismo nos dois modos (validate_palette.js,
// 2026-10-06, contra --surface #fffaff e #20162c). No claro, verde-água,
// amarelo e magenta ficam abaixo de 3:1 no fundo — por isso todo gráfico
// de canal leva legenda com o valor escrito ao lado da cor.
const CHANNEL_ORDER = ['tiktok_shop', 'shopee', 'mercado_livre', 'amazon', 'loja'];
const CHANNEL_COLORS = {
    light: { tiktok_shop: '#2a78d6', shopee: '#eb6834', mercado_livre: '#1baf7a', amazon: '#eda100', loja: '#e87ba4' },
    dark: { tiktok_shop: '#3987e5', shopee: '#d95926', mercado_livre: '#199e70', amazon: '#c98500', loja: '#d55181' },
};
const CHROME = {
    light: { grid: '#ece5f2', text: '#6b5f78', surface: '#fffaff', accent: '#6d28d9', context: '#b9adc4' },
    dark: { grid: '#3a2a4a', text: '#b9a8c8', surface: '#20162c', accent: '#a78bfa', context: '#6f6080' },
};

// O tema muda na hora (botão do layout põe/tira .dark no <html>) — os
// gráficos repintam junto.
const isDark = ref(false);
let themeObserver = null;
onMounted(() => {
    const html = document.documentElement;
    isDark.value = html.classList.contains('dark');
    themeObserver = new MutationObserver(() => { isDark.value = html.classList.contains('dark'); });
    themeObserver.observe(html, { attributes: true, attributeFilter: ['class'] });
});
onBeforeUnmount(() => themeObserver?.disconnect());

const chrome = computed(() => CHROME[isDark.value ? 'dark' : 'light']);
const channelColor = (channel) => CHANNEL_COLORS[isDark.value ? 'dark' : 'light'][channel] ?? chrome.value.context;
const channelName = (channel) => CHANNEL_LABELS[channel] ?? channel;
const sortChannels = (channels) => [...channels].sort((a, b) => (CHANNEL_ORDER.indexOf(a) + 99) % 99 - (CHANNEL_ORDER.indexOf(b) + 99) % 99);

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
const formatCompact = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', notation: 'compact', maximumFractionDigits: 1 }).format(value ?? 0);
const formatPercent = (value) => `${(value ?? 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`;
const formatMonth = (yearMonth) =>
    new Intl.DateTimeFormat('pt-BR', { month: 'short', year: '2-digit' }).format(new Date(`${yearMonth}-01T00:00:00`)).replace('.', '');

// ---- KPIs --------------------------------------------------------------
const kpis = computed(() => props.analytics?.kpis ?? {});
const delta = (agora, antes) => (antes > 0 ? Math.round(((agora - antes) / antes) * 1000) / 10 : null);

const kpiTiles = computed(() => [
    {
        label: 'Ticket médio',
        value: formatPrice(kpis.value.ticketMedio),
        delta: delta(kpis.value.ticketMedio, kpis.value.ticketMedioAnterior),
        hint: 'vs mesmos dias do mês passado',
        icon: 'fas fa-receipt',
        color: 'var(--color-primary)',
    },
    {
        label: 'Crescimento do mês',
        value: kpis.value.crescimento === null || kpis.value.crescimento === undefined ? '—' : `${kpis.value.crescimento > 0 ? '+' : ''}${formatPercent(kpis.value.crescimento)}`,
        delta: null,
        hint: `${formatPrice(kpis.value.receitaPeriodoAnterior)} nos mesmos dias do mês passado`,
        icon: 'fas fa-arrow-trend-up',
        color: (kpis.value.crescimento ?? 0) >= 0 ? 'var(--color-success)' : 'var(--color-error)',
    },
    {
        label: 'Projeção do mês',
        value: formatPrice(kpis.value.projecaoMes),
        delta: delta(kpis.value.projecaoMes, kpis.value.receitaMesAnterior),
        hint: 'no ritmo atual, vs mês passado fechado',
        icon: 'fas fa-bullseye',
        color: 'var(--color-info)',
    },
    {
        label: 'Margem de contribuição',
        value: kpis.value.margemPercentual === null || kpis.value.margemPercentual === undefined ? '—' : formatPercent(kpis.value.margemPercentual),
        delta: null,
        hint: 'do faturamento do mês, depois de custo, taxas, frete e ADS',
        icon: 'fas fa-percent',
        color: 'var(--color-warning)',
    },
    {
        label: 'Itens por pedido',
        value: (kpis.value.itensPorPedido ?? 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 }),
        delta: delta(kpis.value.itensPorPedido, kpis.value.itensPorPedidoAnterior),
        hint: 'vs mesmos dias do mês passado',
        icon: 'fas fa-boxes-stacked',
        color: 'var(--color-secondary)',
    },
]);

const tileStyle = (color) => ({
    borderTop: `3px solid ${color}`,
    background: `linear-gradient(135deg, color-mix(in oklab, ${color} 10%, var(--surface)) 0%, var(--surface) 70%)`,
});

// ---- Opções comuns dos gráficos ---------------------------------------
const baseOptions = computed(() => ({
    animation: { duration: 600 },
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: isDark.value ? '#f4ecfa' : '#251830',
            titleColor: isDark.value ? '#251830' : '#ffffff',
            bodyColor: isDark.value ? '#251830' : '#ffffff',
            padding: 10,
            cornerRadius: 8,
            boxPadding: 4,
        },
    },
}));

const axisStyle = computed(() => ({
    grid: { color: chrome.value.grid, drawTicks: false },
    border: { display: false },
    ticks: { color: chrome.value.text, padding: 6 },
}));

// ---- 1. Participação por canal (rosca) --------------------------------
const channelShare = computed(() => {
    const rows = sortChannels(props.revenueByChannel.map((row) => row.origin))
        .map((origin) => props.revenueByChannel.find((row) => row.origin === origin))
        .filter((row) => row && row.total > 0);
    const total = rows.reduce((sum, row) => sum + row.total, 0);

    return { rows, total };
});

const shareChartData = computed(() => ({
    labels: channelShare.value.rows.map((row) => channelName(row.origin)),
    datasets: [{
        data: channelShare.value.rows.map((row) => row.total),
        backgroundColor: channelShare.value.rows.map((row) => channelColor(row.origin)),
        borderColor: chrome.value.surface,
        borderWidth: 2,
        hoverOffset: 6,
    }],
}));

const shareChartOptions = computed(() => ({
    ...baseOptions.value,
    cutout: '68%',
    plugins: {
        ...baseOptions.value.plugins,
        tooltip: {
            ...baseOptions.value.plugins.tooltip,
            callbacks: {
                label: (ctx) => ` ${ctx.label}: ${formatPrice(ctx.parsed)} (${formatPercent((ctx.parsed / channelShare.value.total) * 100)})`,
            },
        },
    },
}));

// ---- 2. Faturamento mensal por canal (colunas empilhadas) -------------
const currentMonth = new Date().toISOString().slice(0, 7);
const monthlyChannels = computed(() => sortChannels([...new Set((props.analytics?.mensal ?? []).flatMap((m) => Object.keys(m.canais)))])
    .filter((channel) => (props.analytics?.mensal ?? []).some((m) => (m.canais[channel] ?? 0) > 0)));

const monthlyChartData = computed(() => ({
    // O mês corrente está pela metade — sem o aviso, a última coluna parece queda.
    labels: (props.analytics?.mensal ?? []).map((m, i, todos) => (i === todos.length - 1 && m.mes === currentMonth ? `${formatMonth(m.mes)} (parcial)` : formatMonth(m.mes))),
    datasets: monthlyChannels.value.map((channel, index) => ({
        label: channelName(channel),
        data: (props.analytics?.mensal ?? []).map((m) => m.canais[channel] ?? 0),
        backgroundColor: channelColor(channel),
        // 2px na cor do fundo separa os segmentos empilhados.
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
            ...baseOptions.value.plugins.tooltip,
            callbacks: {
                label: (ctx) => ` ${ctx.dataset.label}: ${formatPrice(ctx.parsed.y)}`,
                footer: (items) => `Total: ${formatPrice(items.reduce((sum, item) => sum + item.parsed.y, 0))}`,
            },
        },
    },
}));

const monthlyTotals = computed(() => (props.analytics?.mensal ?? []).map((m) => ({
    mes: m.mes,
    total: Object.values(m.canais).reduce((sum, v) => sum + v, 0),
})));

// ---- 3. Ritmo do mês (acumulado diário, atual x anterior) -------------
const ritmo = computed(() => props.analytics?.ritmo ?? { dia: 0, atual: [], anterior: [] });

const paceChartData = computed(() => {
    const dias = Math.max(ritmo.value.anterior.length, ritmo.value.atual.length);

    return {
        labels: Array.from({ length: dias }, (_, i) => `${i + 1}`),
        datasets: [
            {
                label: 'Este mês',
                data: ritmo.value.atual,
                borderColor: chrome.value.accent,
                backgroundColor: `${chrome.value.accent}1a`,
                fill: true,
                borderWidth: 2,
                tension: 0.3,
                pointRadius: (ctx) => (ctx.dataIndex === ritmo.value.atual.length - 1 ? 5 : 0),
                pointBackgroundColor: chrome.value.accent,
                pointBorderColor: chrome.value.surface,
                pointBorderWidth: 2,
                pointHoverRadius: 6,
            },
            {
                label: 'Mês passado',
                data: ritmo.value.anterior,
                borderColor: chrome.value.context,
                borderWidth: 2,
                tension: 0.3,
                pointRadius: 0,
                pointHoverRadius: 5,
                fill: false,
            },
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
        tooltip: {
            ...baseOptions.value.plugins.tooltip,
            callbacks: {
                title: (items) => `Dia ${items[0].label}`,
                label: (ctx) => ` ${ctx.dataset.label}: ${formatPrice(ctx.parsed.y)}`,
            },
        },
    },
}));

const paceComparison = computed(() => {
    const hoje = ritmo.value.atual.at(-1) ?? 0;
    const antes = ritmo.value.anterior[ritmo.value.atual.length - 1] ?? 0;

    return { hoje, antes, delta: delta(hoje, antes) };
});

// ---- 4. Faturamento por categoria (barras horizontais) ----------------
const categories = computed(() => props.analytics?.categorias ?? []);
const categoriesTotal = computed(() => categories.value.reduce((sum, c) => sum + c.receita, 0));

const categoryChartData = computed(() => ({
    labels: categories.value.map((c) => c.nome),
    datasets: [{
        label: 'Faturamento',
        data: categories.value.map((c) => c.receita),
        backgroundColor: chrome.value.accent,
        borderRadius: 4,
        borderSkipped: 'start',
        maxBarThickness: 18,
    }],
}));

const categoryChartOptions = computed(() => ({
    ...baseOptions.value,
    indexAxis: 'y',
    scales: {
        x: { ...axisStyle.value, ticks: { ...axisStyle.value.ticks, callback: (v) => formatCompact(v), maxTicksLimit: 5 } },
        y: { ...axisStyle.value, grid: { display: false }, ticks: { ...axisStyle.value.ticks, color: isDark.value ? '#f4ecfa' : '#251830' } },
    },
    plugins: {
        ...baseOptions.value.plugins,
        tooltip: {
            ...baseOptions.value.plugins.tooltip,
            callbacks: {
                label: (ctx) => ` ${formatPrice(ctx.parsed.x)} (${formatPercent((ctx.parsed.x / categoriesTotal.value) * 100)})`,
            },
        },
    },
}));

// ---- Cards de produto --------------------------------------------------
const topMax = computed(() => Math.max(1, ...props.topProducts.map((p) => p.quantity)));
</script>

<template>
    <Head title="Dashboard" />

    <AdminLayout>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <CardStats stat-subtitle="FATURAMENTO TOTAL" :stat-title="formatPrice(stats.revenueTotal)"
                stat-icon-name="fas fa-sack-dollar" variant="success" vivid />
            <CardStats stat-subtitle="FATURAMENTO DO MÊS" :stat-title="formatPrice(stats.revenue)"
                stat-icon-name="fas fa-calendar-days" variant="primary" vivid />
            <CardStats stat-subtitle="FATURAMENTO HOJE" :stat-title="formatPrice(stats.revenueToday)"
                stat-icon-name="fas fa-money-bill-wave" variant="info" vivid />
            <CardStats stat-subtitle="MARGEM DE CONTRIBUIÇÃO DO MÊS" :stat-title="formatPrice(stats.contributionMarginMonth)"
                stat-icon-name="fas fa-wallet" variant="warning" vivid />
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <CardStats stat-subtitle="PEDIDOS" :stat-title="String(stats.ordersCount)"
                stat-icon-name="fas fa-receipt" variant="primary" vivid />
            <CardStats stat-subtitle="PEDIDOS NO MÊS" :stat-title="String(stats.ordersMonth)"
                stat-icon-name="fas fa-calendar-check" variant="secondary" vivid />
            <CardStats stat-subtitle="PEDIDOS HOJE" :stat-title="String(stats.ordersToday)"
                stat-icon-name="fas fa-cart-shopping" variant="primary" vivid />
            <CardStats stat-subtitle="DEVOLUÇÕES NO MÊS" :stat-title="String(stats.returnsMonth)"
                stat-icon-name="fas fa-rotate-left" variant="warning" vivid />
        </div>

        <div v-if="revenueByChannel.length" class="mt-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-500 dark:text-slate-400">Faturamento por canal</h3>
            <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                <span v-for="row in channelShare.rows" :key="row.origin" class="inline-flex items-center gap-2">
                    <span class="h-2.5 w-2.5 rounded-full" :style="{ background: channelColor(row.origin) }"></span>
                    <span class="text-slate-500 dark:text-slate-400">{{ channelName(row.origin) }}</span>
                    <span class="font-semibold">{{ formatPrice(row.total) }}</span>
                </span>
            </div>
        </div>

        <!-- Indicadores do mês (pedido do usuário 2026-10-06): o mês até
             hoje sempre contra os MESMOS dias do mês passado. -->
        <h2 class="mt-8 mb-3 text-lg font-bold">Desempenho do mês</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <div v-for="tile in kpiTiles" :key="tile.label"
                class="flex min-w-0 flex-col rounded-xl border border-[var(--surface-border)] p-4 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-md"
                :style="tileStyle(tile.color)">
                <div class="flex items-center justify-between gap-2">
                    <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ tile.label }}</p>
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm text-white" :style="{ background: tile.color }">
                        <i :class="tile.icon"></i>
                    </span>
                </div>
                <p class="mt-1 truncate text-2xl font-bold">{{ tile.value }}</p>
                <p class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-slate-500 dark:text-slate-400">
                    <span v-if="tile.delta !== null" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold"
                        :class="tile.delta >= 0 ? 'bg-lightsuccess text-success' : 'bg-lighterror text-error'">
                        <i :class="tile.delta >= 0 ? 'fas fa-arrow-up' : 'fas fa-arrow-down'" class="text-[10px]"></i>
                        {{ tile.delta > 0 ? '+' : '' }}{{ formatPercent(tile.delta) }}
                    </span>
                    <span>{{ tile.hint }}</span>
                </p>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-5">
            <!-- 1. Participação por canal -->
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm xl:col-span-2">
                <h3 class="text-base font-semibold">Participação por canal</h3>
                <p class="text-xs text-slate-400">Faturamento do mês até hoje</p>
                <div v-if="channelShare.total > 0" class="mt-3 grid grid-cols-1 items-center gap-4 sm:grid-cols-2">
                    <div class="relative">
                        <ChartCanvas type="doughnut" :data="shareChartData" :options="shareChartOptions" />
                        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-xs text-slate-400">Total</span>
                            <span class="text-lg font-bold">{{ formatCompact(channelShare.total) }}</span>
                        </div>
                    </div>
                    <ul class="space-y-2 text-sm">
                        <li v-for="row in channelShare.rows" :key="row.origin" class="flex items-center justify-between gap-3">
                            <span class="flex min-w-0 items-center gap-2">
                                <span class="h-3 w-3 shrink-0 rounded-full" :style="{ background: channelColor(row.origin) }"></span>
                                <span class="truncate">{{ channelName(row.origin) }}</span>
                            </span>
                            <span class="shrink-0 text-right">
                                <span class="block font-semibold">{{ formatPrice(row.total) }}</span>
                                <span class="block text-xs text-slate-400">{{ formatPercent((row.total / channelShare.total) * 100) }}</span>
                            </span>
                        </li>
                    </ul>
                </div>
                <p v-else class="mt-6 text-sm text-slate-500">Nenhuma venda no mês ainda.</p>
            </section>

            <!-- 2. Crescimento: faturamento mensal por canal -->
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm xl:col-span-3">
                <h3 class="text-base font-semibold">Faturamento mensal por canal</h3>
                <p class="text-xs text-slate-400">Crescimento da loja mês a mês · passe o mouse para ver cada canal</p>
                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    <span v-for="channel in monthlyChannels" :key="channel" class="inline-flex items-center gap-1.5">
                        <span class="h-2.5 w-2.5 rounded-sm" :style="{ background: channelColor(channel) }"></span>
                        {{ channelName(channel) }}
                    </span>
                </div>
                <ChartCanvas v-if="monthlyTotals.length" class="mt-2" type="bar" :data="monthlyChartData" :options="monthlyChartOptions" />
                <p v-else class="mt-6 text-sm text-slate-500">Sem vendas nos últimos 12 meses.</p>
            </section>

            <!-- 3. Ritmo do mês -->
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm xl:col-span-3">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h3 class="text-base font-semibold">Ritmo do mês</h3>
                        <p class="text-xs text-slate-400">Faturamento acumulado dia a dia</p>
                    </div>
                    <span v-if="paceComparison.delta !== null" class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold"
                        :class="paceComparison.delta >= 0 ? 'bg-lightsuccess text-success' : 'bg-lighterror text-error'">
                        <i :class="paceComparison.delta >= 0 ? 'fas fa-arrow-up' : 'fas fa-arrow-down'" class="text-[10px]"></i>
                        {{ paceComparison.delta > 0 ? '+' : '' }}{{ formatPercent(paceComparison.delta) }} vs mês passado no dia {{ ritmo.dia }}
                    </span>
                </div>
                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4 rounded" :style="{ background: chrome.accent }"></span> Este mês · {{ formatPrice(paceComparison.hoje) }}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4 rounded" :style="{ background: chrome.context }"></span> Mês passado · {{ formatPrice(paceComparison.antes) }} no mesmo dia</span>
                </div>
                <ChartCanvas class="mt-2" type="line" :data="paceChartData" :options="paceChartOptions" />
            </section>

            <!-- 4. Mix de produtos por categoria -->
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm xl:col-span-2">
                <h3 class="text-base font-semibold">Faturamento por categoria</h3>
                <p class="text-xs text-slate-400">Mix de produtos · últimos 90 dias</p>
                <ChartCanvas v-if="categories.length" class="mt-3" type="bar" :data="categoryChartData" :options="categoryChartOptions" />
                <p v-else class="mt-6 text-sm text-slate-500">Sem vendas nos últimos 90 dias.</p>
            </section>
        </div>

        <!-- Produtos: cada card com a sua cor, ranking e a barra de peso. -->
        <div class="mt-8 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <section class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm transition duration-200 hover:shadow-md"
                style="border-top: 3px solid var(--color-primary)">
                <div class="flex items-center gap-3 border-b border-[var(--surface-border)] px-4 py-4"
                    style="background: linear-gradient(135deg, color-mix(in oklab, var(--color-primary) 10%, var(--surface)) 0%, var(--surface) 80%)">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-white" style="background: var(--color-primary)"><i class="fas fa-fire"></i></span>
                    <div>
                        <h3 class="text-base font-semibold">Produtos mais vendidos</h3>
                        <p class="text-xs text-slate-400">Unidades vendidas nos últimos 30 dias</p>
                    </div>
                </div>
                <div class="p-4">
                    <p v-if="topProducts.length === 0" class="text-sm text-slate-500">Nenhuma venda nos últimos 30 dias.</p>
                    <ol v-else class="space-y-3 text-sm">
                        <li v-for="(product, index) in topProducts" :key="`${product.id}-${product.name}`">
                            <div class="flex items-center justify-between gap-3">
                                <span class="flex min-w-0 items-center gap-2">
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                                        :class="index < 3 ? 'text-white' : 'bg-lightprimary text-primary'"
                                        :style="index < 3 ? { background: 'var(--color-primary)' } : {}">{{ index + 1 }}</span>
                                    <span class="min-w-0 truncate">
                                        <Link v-if="product.id" :href="`/admin/produtos/${product.id}/editar`" class="hover:text-primary hover:underline">{{ product.name }}</Link>
                                        <span v-else>{{ product.name }}</span>
                                        <span v-if="product.color" class="block text-xs font-semibold uppercase text-slate-500">{{ product.color }}</span>
                                    </span>
                                </span>
                                <span class="shrink-0 text-right">
                                    <span class="block font-semibold">{{ product.quantity }} un.</span>
                                    <span class="block text-xs text-slate-400">{{ formatPrice(product.revenue) }}</span>
                                </span>
                            </div>
                            <div class="mt-1.5 ml-8 h-1.5 rounded-full bg-lightprimary">
                                <div class="h-1.5 rounded-full" style="background: var(--color-primary)" :style="{ width: `${(product.quantity / topMax) * 100}%` }"></div>
                            </div>
                        </li>
                    </ol>
                </div>
            </section>

            <section v-for="curva in abcCards" :key="curva.key"
                class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm transition duration-200 hover:shadow-md"
                :style="{ borderTop: `3px solid ${curva.color}` }">
                <div class="flex items-center gap-3 border-b border-[var(--surface-border)] px-4 py-4"
                    :style="{ background: `linear-gradient(135deg, color-mix(in oklab, ${curva.color} 10%, var(--surface)) 0%, var(--surface) 80%)` }">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-white" :style="{ background: curva.color }"><i :class="curva.icon"></i></span>
                    <div>
                        <h3 class="text-base font-semibold">Produtos curva {{ curva.key.toUpperCase() }}</h3>
                        <p class="text-xs text-slate-400">{{ curva.description }} · últimos 90 dias</p>
                    </div>
                </div>
                <div class="p-4">
                    <p v-if="curva.items.length === 0" class="text-sm text-slate-500">Nenhum produto nesta curva.</p>
                    <ul v-else class="space-y-3 text-sm">
                        <li v-for="product in curva.items.slice(0, 10)" :key="`${product.id}-${product.name}`">
                            <div class="flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate">
                                    <Link v-if="product.id" :href="`/admin/produtos/${product.id}/editar`" class="hover:text-primary hover:underline">{{ product.name }}</Link>
                                    <span v-else>{{ product.name }}</span>
                                    <span v-if="product.color" class="block text-xs font-semibold uppercase text-slate-500">{{ product.color }}</span>
                                </span>
                                <span class="shrink-0 text-right">
                                    <span class="block font-semibold">{{ formatPrice(product.revenue) }}</span>
                                    <span class="block text-xs text-slate-400">{{ formatPercent(product.share) }} do faturamento</span>
                                </span>
                            </div>
                            <div class="mt-1.5 h-1.5 rounded-full" :style="{ background: `color-mix(in oklab, ${curva.color} 15%, transparent)` }">
                                <div class="h-1.5 rounded-full" :style="{ width: `${(product.share / (curva.items[0]?.share || 1)) * 100}%`, background: curva.color }"></div>
                            </div>
                        </li>
                    </ul>
                    <p v-if="curva.items.length > 10" class="mt-3 text-xs text-slate-400">+ {{ curva.items.length - 10 }} produto(s) nesta curva</p>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
