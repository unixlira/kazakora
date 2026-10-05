<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import ChartCanvas from '@/Shared/Components/ChartCanvas.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';

const props = defineProps({
    rangeDays: { type: Number, default: 30 },
    period: { type: Object, required: true },
    summary: { type: Object, required: true },
    channels: { type: Array, default: () => [] },
    spendSeries: { type: Array, default: () => [] },
    campaignMetrics: { type: Array, default: () => [] },
    alerts: { type: Array, default: () => [] },
    dataFreshness: { type: Object, default: () => ({}) },
    autoRefreshSeconds: { type: Number, default: 60 },
});

const CHANNEL_STYLES = {
    shopee: { label: 'Shopee', color: '#EE4D2D', bg: 'rgba(238,77,45,0.12)' },
    mercado_livre: { label: 'Mercado Livre', color: '#2968C8', bg: 'rgba(41,104,200,0.12)' },
    tiktok_shop: { label: 'TikTok Shop', color: '#25F4EE', bg: 'rgba(37,244,238,0.12)' },
    amazon: { label: 'Amazon', color: '#FF9900', bg: 'rgba(255,153,0,0.14)' },
    loja: { label: 'Loja própria', color: '#7c3aed', bg: 'rgba(124,58,237,0.12)' },
};

let refreshTimer = null;
const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value ?? 0));
const formatNumber = (value) => new Intl.NumberFormat('pt-BR').format(Number(value ?? 0));
const formatPercent = (value) => `${Number(value ?? 0).toFixed(2)}%`;
const formatShortDate = (date) => new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit' }).format(new Date(`${date}T00:00:00`));
const formatDateTime = (value) => value ? new Date(value.replace(' ', 'T')).toLocaleString('pt-BR') : 'Sem sincronização ainda';
const changeRange = (event) => router.get('/admin/metricas-marketplace', { dias: Number(event.target.value) }, { preserveScroll: true, preserveState: true });
const refresh = () => router.reload({ only: ['summary', 'channels', 'spendSeries', 'campaignMetrics', 'alerts', 'dataFreshness'], preserveScroll: true });
onMounted(() => { refreshTimer = window.setInterval(refresh, props.autoRefreshSeconds * 1000); });
onBeforeUnmount(() => window.clearInterval(refreshTimer));

const chartData = computed(() => ({
    labels: props.spendSeries.map((item) => formatShortDate(item.date)),
    datasets: [
        { label: 'Shopee Ads', data: props.spendSeries.map((item) => item.shopee), borderColor: CHANNEL_STYLES.shopee.color, backgroundColor: CHANNEL_STYLES.shopee.bg, tension: 0.35, fill: true },
        { label: 'Mercado Ads', data: props.spendSeries.map((item) => item.mercado_livre), borderColor: CHANNEL_STYLES.mercado_livre.color, backgroundColor: CHANNEL_STYLES.mercado_livre.bg, tension: 0.35, fill: true },
        { label: 'TikTok Shop', data: props.spendSeries.map((item) => item.tiktok_shop), borderColor: CHANNEL_STYLES.tiktok_shop.color, backgroundColor: CHANNEL_STYLES.tiktok_shop.bg, tension: 0.35, fill: true },
        { label: 'Amazon', data: props.spendSeries.map((item) => item.amazon), borderColor: CHANNEL_STYLES.amazon.color, backgroundColor: CHANNEL_STYLES.amazon.bg, tension: 0.35, fill: true },
    ],
}));
const channelBarData = computed(() => ({
    labels: props.channels.map((channel) => channel.label),
    datasets: [
        { label: 'Gasto Ads', data: props.channels.map((channel) => channel.spend), backgroundColor: '#ef4444' },
        { label: 'GMV atribuído', data: props.channels.map((channel) => channel.attributedGmv), backgroundColor: '#7c3aed' },
        { label: 'Receita real', data: props.channels.map((channel) => channel.grossRevenue), backgroundColor: '#2968C8' },
    ],
}));
const contributionVariant = computed(() => props.summary.estimatedContribution >= 0 ? 'success' : 'error');
const channelStyle = (channel) => CHANNEL_STYLES[channel] ?? { label: channel, color: '#64748B', bg: 'rgba(100,116,139,0.12)' };
const alertClass = (variant) => ({
    success: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-900/20 dark:text-emerald-300',
    warning: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-900/20 dark:text-amber-300',
    error: 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-900/20 dark:text-red-300',
})[variant] ?? 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-800 dark:bg-slate-900/30 dark:text-slate-300';
</script>

<template>
    <Head title="Métricas Marketplace" />
    <AdminLayout>
        <div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.25em] text-primary">Tempo real operacional</p>
                <h1 class="mt-1 text-2xl font-bold">Métricas Marketplace</h1>
                <p class="mt-1 text-sm text-slate-400">Leitura dinâmica para a Juliana analisar campanhas, gasto, conversão, pedidos e anúncios com erro.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <select class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm" :value="rangeDays" @change="changeRange">
                    <option :value="7">Últimos 7 dias</option><option :value="30">Últimos 30 dias</option><option :value="90">Últimos 90 dias</option>
                </select>
                <button type="button" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:opacity-90" @click="refresh"><i class="fas fa-rotate mr-2"></i>Atualizar agora</button>
            </div>
        </div>

        <div class="mb-5 rounded-2xl border border-[var(--surface-border)] bg-gradient-to-br from-slate-950 via-slate-900 to-emerald-950 p-5 text-white shadow-sm">
            <div class="grid gap-4 lg:grid-cols-[1.3fr_0.7fr] lg:items-center">
                <div><p class="text-sm text-slate-300">Período: {{ formatShortDate(period.start) }} a {{ formatShortDate(period.end) }}</p><h2 class="mt-2 text-3xl font-black">{{ formatPrice(summary.estimatedContribution) }}</h2><p class="mt-1 text-sm text-slate-300">Contribuição estimada: receita real menos taxas, custo de produto e gasto com anúncio.</p></div>
                <div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200"><div class="flex items-center justify-between py-1"><span>Última sincronização Ads</span><span class="font-semibold">{{ formatDateTime(dataFreshness.adSpendUpdatedAt) }}</span></div><div class="flex items-center justify-between py-1"><span>Campanhas granulares</span><span class="font-semibold">{{ formatDateTime(dataFreshness.campaignMetricsUpdatedAt) }}</span></div><div class="flex items-center justify-between py-1"><span>Atualização automática</span><span class="font-semibold">{{ autoRefreshSeconds }}s</span></div></div>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <CardStats stat-subtitle="GASTO COM ADS" :stat-title="formatPrice(summary.spend)" stat-icon-name="fas fa-bullhorn" variant="error" />
            <CardStats stat-subtitle="GMV ATRIBUÍDO" :stat-title="formatPrice(summary.attributedGmv)" stat-icon-name="fas fa-cart-shopping" variant="success" />
            <CardStats stat-subtitle="ROAS GERAL" :stat-title="`${summary.roas.toFixed(2)}x`" stat-icon-name="fas fa-arrow-trend-up" variant="primary" />
            <CardStats stat-subtitle="CPA MÉDIO" :stat-title="formatPrice(summary.cpa)" stat-icon-name="fas fa-bullseye" variant="warning" />
            <CardStats stat-subtitle="CTR GERAL" :stat-title="formatPercent(summary.ctr)" stat-icon-name="fas fa-computer-mouse" variant="info" />
            <CardStats stat-subtitle="CLIQUES" :stat-title="formatNumber(summary.clicks)" stat-icon-name="fas fa-hand-pointer" variant="secondary" />
            <CardStats stat-subtitle="PEDIDOS REAIS" :stat-title="formatNumber(summary.ordersCount)" stat-icon-name="fas fa-receipt" variant="success" />
            <CardStats stat-subtitle="RESULTADO ESTIMADO" :stat-title="formatPrice(summary.estimatedContribution)" stat-icon-name="fas fa-scale-balanced" :variant="contributionVariant" />
        </div>

        <div class="mb-6 grid gap-4 xl:grid-cols-[1.25fr_0.75fr]"><div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm"><div class="border-b border-[var(--surface-border)] px-4 py-4"><h3 class="text-base font-semibold">Gasto por dia e canal</h3><p class="text-xs text-slate-400">Atualiza automaticamente a cada {{ autoRefreshSeconds }} segundos.</p></div><div class="p-4"><ChartCanvas type="line" :data="chartData" /></div></div><div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm"><div class="border-b border-[var(--surface-border)] px-4 py-4"><h3 class="text-base font-semibold">Alertas para ajuste</h3><p class="text-xs text-slate-400">Fila objetiva para análise da Juliana.</p></div><div class="space-y-3 p-4"><div v-for="alert in alerts" :key="`${alert.title}-${alert.description}`" class="rounded-lg border p-3 text-sm" :class="alertClass(alert.variant)"><p class="font-semibold">{{ alert.title }}</p><p class="mt-1 text-xs opacity-90">{{ alert.description }}</p></div></div></div></div>

        <div class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm"><div class="border-b border-[var(--surface-border)] px-4 py-4"><h3 class="text-base font-semibold">Comparativo por canal</h3><p class="text-xs text-slate-400">Cruza mídia paga, pedidos reais, taxa, custo e status dos anúncios.</p></div><div class="overflow-x-auto"><table class="w-full min-w-[980px] text-left text-sm"><thead class="border-b border-[var(--surface-border)] text-xs uppercase tracking-wide text-slate-400"><tr><th class="px-4 py-3">Canal</th><th class="px-4 py-3">Gasto</th><th class="px-4 py-3">CTR</th><th class="px-4 py-3">CPC</th><th class="px-4 py-3">ROAS</th><th class="px-4 py-3">CPA</th><th class="px-4 py-3">Pedidos</th><th class="px-4 py-3">Receita real</th><th class="px-4 py-3">Resultado</th><th class="px-4 py-3">Anúncios</th></tr></thead><tbody class="divide-y divide-[var(--surface-border)]"><tr v-for="channel in channels" :key="channel.channel" class="hover:bg-[var(--surface-muted)]/50"><td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-bold" :style="{ color: channelStyle(channel.channel).color, background: channelStyle(channel.channel).bg }">{{ channel.label }}</span></td><td class="px-4 py-3 font-semibold">{{ formatPrice(channel.spend) }}</td><td class="px-4 py-3">{{ formatPercent(channel.ctr) }}</td><td class="px-4 py-3">{{ formatPrice(channel.cpc) }}</td><td class="px-4 py-3 font-semibold" :class="channel.roas >= 2 ? 'text-success' : channel.roas > 0 ? 'text-warning' : 'text-slate-400'">{{ channel.roas.toFixed(2) }}x</td><td class="px-4 py-3">{{ formatPrice(channel.cpa) }}</td><td class="px-4 py-3">{{ formatNumber(channel.ordersCount) }}</td><td class="px-4 py-3">{{ formatPrice(channel.grossRevenue) }}</td><td class="px-4 py-3 font-semibold" :class="channel.estimatedContribution >= 0 ? 'text-success' : 'text-error'">{{ formatPrice(channel.estimatedContribution) }}</td><td class="px-4 py-3 text-xs text-slate-400">{{ channel.publishedListings }} publicados · {{ channel.errorListings }} erro · {{ channel.pendingListings }} pendente/rascunho</td></tr></tbody></table></div></div>

        <div class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm"><div class="border-b border-[var(--surface-border)] px-4 py-4"><h3 class="text-base font-semibold">Gasto x GMV x Receita real por canal</h3><p class="text-xs text-slate-400">Ajuda a separar campanha que gera clique de campanha que vira pedido de verdade.</p></div><div class="p-4"><ChartCanvas type="bar" :data="channelBarData" /></div></div>

        <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm"><div class="border-b border-[var(--surface-border)] px-4 py-4"><h3 class="text-base font-semibold">Campanhas granulares</h3><p class="text-xs text-slate-400">Tabela pronta para receber dados por campanha quando o conector sincronizar esse nível de detalhe.</p></div><div v-if="campaignMetrics.length" class="overflow-x-auto"><table class="w-full min-w-[900px] text-left text-sm"><thead class="border-b border-[var(--surface-border)] text-xs uppercase tracking-wide text-slate-400"><tr><th class="px-4 py-3">Campanha</th><th class="px-4 py-3">Canal</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Gasto</th><th class="px-4 py-3">Cliques</th><th class="px-4 py-3">CTR</th><th class="px-4 py-3">Pedidos</th><th class="px-4 py-3">ROAS</th><th class="px-4 py-3">CPA</th></tr></thead><tbody class="divide-y divide-[var(--surface-border)]"><tr v-for="campaign in campaignMetrics" :key="`${campaign.channel}-${campaign.campaignKey}`" class="hover:bg-[var(--surface-muted)]/50"><td class="px-4 py-3 font-medium">{{ campaign.campaignName }}</td><td class="px-4 py-3">{{ campaign.channelLabel }}</td><td class="px-4 py-3">{{ campaign.status }}</td><td class="px-4 py-3">{{ formatPrice(campaign.spend) }}</td><td class="px-4 py-3">{{ formatNumber(campaign.clicks) }}</td><td class="px-4 py-3">{{ formatPercent(campaign.ctr) }}</td><td class="px-4 py-3">{{ formatNumber(campaign.attributedOrders) }}</td><td class="px-4 py-3">{{ campaign.roas.toFixed(2) }}x</td><td class="px-4 py-3">{{ formatPrice(campaign.cpa) }}</td></tr></tbody></table></div><div v-else class="p-5 text-sm text-slate-400">Ainda não há métrica granular por campanha nesta tabela. O painel já mostra o consolidado real por canal usando os dados existentes; a próxima etapa é ligar o sincronizador de campanha da Shopee/Mercado Livre para popular esta área automaticamente.</div></div>
    </AdminLayout>
</template>
