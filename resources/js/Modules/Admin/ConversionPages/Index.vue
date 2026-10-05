<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    summary: { type: Object, default: () => ({}) },
    creativeInsights: { type: Array, default: () => [] },
    keywords: { type: Array, default: () => [] },
    providerStatus: { type: Object, default: () => ({}) },
    sourceNotice: { type: String, default: '' },
    scoreFormula: { type: String, default: '' },
    developerNote: { type: String, default: '' },
});

const selectedScore = ref('Todos');
const selectedKeyword = ref('Todas');
const scoreFilters = ['Todos', 'Prioridade alta', 'Boa hipótese', 'Monitorar'];
const keywordFilters = computed(() => ['Todas', ...new Set(props.creativeInsights.map((item) => item.keyword).filter(Boolean))]);
const filteredInsights = computed(() => props.creativeInsights.filter((item) => (selectedScore.value === 'Todos' || item.scoreLabel === selectedScore.value) && (selectedKeyword.value === 'Todas' || item.keyword === selectedKeyword.value)));
const refreshResearch = () => router.post('/admin/paginas-de-conversao/atualizar', {}, { preserveScroll: true });
const scoreClass = (label) => ({ 'Prioridade alta': 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200', 'Boa hipótese': 'bg-amber-100 text-amber-700 ring-1 ring-amber-200', Monitorar: 'bg-slate-100 text-slate-600 ring-1 ring-slate-200' }[label] ?? 'bg-slate-100 text-slate-600 ring-1 ring-slate-200');
const formatNumber = (value) => Number(value ?? 0).toLocaleString('pt-BR');
</script>

<template>
    <Head title="Páginas de Conversão" />
    <AdminLayout>
        <section class="overflow-hidden rounded-3xl bg-slate-950 text-white shadow-2xl shadow-slate-300/40">
            <div class="relative px-6 py-8 sm:px-8 lg:px-10">
                <div class="absolute right-0 top-0 h-72 w-72 rounded-full bg-rose-400/20 blur-3xl"></div>
                <div class="relative flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-3xl">
                        <span class="inline-flex items-center rounded-full bg-white/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.24em] text-rose-100 ring-1 ring-white/15">Meta Ads Library · benchmarks reais · modelagem comercial</span>
                        <h1 class="mt-5 text-3xl font-black tracking-tight sm:text-4xl lg:text-5xl">Páginas de Conversão</h1>
                        <p class="mt-4 max-w-2xl text-sm leading-6 text-slate-300 sm:text-base">Pesquisa pronta de criativos reais da Meta Ads Library que dão sinais públicos de validação: muito tempo rodando, variações reaproveitadas, destino público e gancho replicável para criar similares.</p>
                    </div>
                    <button type="button" class="rounded-2xl bg-white px-5 py-3 text-sm font-black uppercase tracking-wide text-slate-950 shadow-lg transition hover:bg-rose-50" @click="refreshResearch">Atualizar pesquisa</button>
                </div>
            </div>
        </section>

        <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div v-for="card in [
                ['Criativos', summary.totalCreatives, 'cards prontos para análise', 'text-slate-900'],
                ['Prioridade alta', summary.priorityCreatives, 'score proxy acima de 80', 'text-emerald-600'],
                ['Com landing', summary.withLandingPage, 'URL de destino detectada', 'text-rose-600'],
                ['Tempo médio', `${formatNumber(summary.averageActiveDays)}d`, 'longevidade como proxy', 'text-amber-600'],
                ['Atualizado', summary.searchedAt, 'cache de 90 minutos', 'text-slate-900'],
            ]" :key="card[0]" class="rounded-2xl bg-white p-5 shadow-lg shadow-slate-200/80">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ card[0] }}</p>
                <p class="mt-2 text-3xl font-black" :class="card[3]">{{ card[1] }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ card[2] }}</p>
            </div>
        </section>

        <section class="mt-6 grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
            <div class="rounded-3xl border border-amber-100 bg-amber-50 p-5 text-sm leading-6 text-amber-900">
                <p class="font-bold">Leitura correta dos dados</p>
                <p class="mt-2">{{ sourceNotice }}</p>
                <p class="mt-2 font-semibold">{{ scoreFormula }}</p>
            </div>
            <div class="rounded-3xl border border-slate-200 bg-white p-5 text-sm leading-6 text-slate-600 shadow-lg shadow-slate-200/70">
                <p class="font-bold text-slate-900">Status da fonte</p>
                <p class="mt-2"><span class="font-semibold">{{ providerStatus.name }}:</span> {{ providerStatus.status }}</p>
                <p class="mt-1">{{ providerStatus.detail }}</p>
                <p class="mt-3 rounded-2xl bg-slate-50 p-3 text-xs font-semibold text-slate-500">{{ developerNote }}</p>
            </div>
        </section>

        <section class="mt-6 rounded-3xl bg-white p-5 shadow-xl shadow-slate-200/80">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div><p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-400">Filtros</p><h2 class="mt-1 text-2xl font-black text-slate-900">Criativos encontrados</h2></div>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <select v-model="selectedScore" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700"><option v-for="score in scoreFilters" :key="score">{{ score }}</option></select>
                    <select v-model="selectedKeyword" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700"><option v-for="keyword in keywordFilters" :key="keyword">{{ keyword }}</option></select>
                </div>
            </div>
            <div class="mt-6 grid gap-4 xl:grid-cols-2">
                <article v-for="item in filteredInsights" :key="item.fingerprint" class="rounded-3xl border border-slate-100 bg-slate-50/70 p-5">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div><div class="flex flex-wrap items-center gap-2"><span class="rounded-full px-3 py-1 text-xs font-black uppercase" :class="scoreClass(item.scoreLabel)">{{ item.scoreLabel }}</span><span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-500 ring-1 ring-slate-200">{{ item.longevityLabel }}</span></div><h3 class="mt-3 text-lg font-black text-slate-900">{{ item.title }}</h3><p class="mt-2 text-sm leading-6 text-slate-600">{{ item.hook }}</p></div>
                        <div class="rounded-2xl bg-white p-4 text-center shadow-sm"><p class="text-3xl font-black text-slate-900">{{ item.conversionProxyScore }}</p><p class="text-xs font-bold uppercase text-slate-400">score</p></div>
                    </div>
                    <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                        <div class="rounded-2xl bg-white p-3"><dt class="text-xs font-bold uppercase text-slate-400">Anunciante</dt><dd class="mt-1 font-semibold text-slate-800">{{ item.brand }}</dd></div>
                        <div class="rounded-2xl bg-white p-3"><dt class="text-xs font-bold uppercase text-slate-400">Palavra-chave</dt><dd class="mt-1 font-semibold text-slate-800">{{ item.keyword }}</dd></div>
                        <div class="rounded-2xl bg-white p-3"><dt class="text-xs font-bold uppercase text-slate-400">Dias ativo</dt><dd class="mt-1 font-semibold text-slate-800">{{ item.activeDays }} dias</dd></div>
                        <div class="rounded-2xl bg-white p-3"><dt class="text-xs font-bold uppercase text-slate-400">Posicionamentos</dt><dd class="mt-1 font-semibold text-slate-800">{{ item.platforms.join(', ') }}</dd></div>
                    </dl>
                    <div class="mt-5 space-y-2 text-sm">
                        <a :href="item.adLibraryUrl" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-xl bg-slate-950 px-4 py-2 font-bold text-white hover:bg-rose-700"><i class="fas fa-arrow-up-right-from-square"></i>Abrir na Meta Ads Library</a>
                        <a v-if="item.landingPageUrl" :href="item.landingPageUrl" target="_blank" rel="noopener" class="ml-0 inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2 font-bold text-slate-700 ring-1 ring-slate-200 hover:text-rose-700 sm:ml-2">Landing page: {{ item.landingPageDomain }}</a>
                        <p v-else class="text-xs font-semibold text-slate-500">Landing page não exposta no HTML público. Abrir o anúncio para validar o destino manualmente.</p>
                    </div>
                    <div class="mt-5 rounded-2xl bg-white p-4 text-xs leading-5 text-slate-500">
                        <p class="font-bold uppercase tracking-wide text-slate-400">Dados públicos e limites</p>
                        <p class="mt-1">Conversões: {{ item.publicMetrics.conversions ?? 'não público' }} · Gasto: {{ item.publicMetrics.spend ?? 'não público' }} · Impressões: {{ item.publicMetrics.impressions ?? 'não público' }}</p>
                        <p class="mt-1">{{ item.publicMetrics.reason }}</p>
                        <ul class="mt-2 list-disc space-y-1 pl-4"><li v-for="evidence in item.evidence" :key="evidence">{{ evidence }}</li></ul>
                    </div>
                </article>
            </div>
        </section>
    </AdminLayout>
</template>
