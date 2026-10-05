<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    summary: { type: Object, default: () => ({}) },
    opportunities: { type: Array, default: () => [] },
    benchmarks: { type: Array, default: () => [] },
    providerStatus: { type: Object, default: () => ({}) },
    sourceNotice: { type: String, default: '' },
    scoreFormula: { type: String, default: '' },
    developerNote: { type: String, default: '' },
});

const selectedNiche = ref('Todos');
const selectedScore = ref('Todos');
const scoreFilters = ['Todos', 'Prioridade alta', 'Boa aposta', 'Monitorar'];
const nicheFilters = computed(() => ['Todos', ...new Set(props.opportunities.map((item) => item.niche).filter(Boolean))]);
const filteredOpportunities = computed(() => props.opportunities.filter((item) => (selectedNiche.value === 'Todos' || item.niche === selectedNiche.value) && (selectedScore.value === 'Todos' || item.scoreLabel === selectedScore.value)));
const refreshResearch = () => router.post('/admin/pdf-oportunidades/atualizar', {}, { preserveScroll: true });
const scoreClass = (label) => ({
    'Prioridade alta': 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200',
    'Boa aposta': 'bg-amber-100 text-amber-700 ring-1 ring-amber-200',
    Monitorar: 'bg-slate-100 text-slate-600 ring-1 ring-slate-200',
}[label] ?? 'bg-slate-100 text-slate-600 ring-1 ring-slate-200');
const formatNumber = (value) => Number(value ?? 0).toLocaleString('pt-BR');
</script>

<template>
    <Head title="PDF Oportunidades" />
    <AdminLayout>
        <section class="overflow-hidden rounded-3xl bg-[#17110d] text-white shadow-2xl shadow-slate-300/40">
            <div class="relative px-6 py-8 sm:px-8 lg:px-10">
                <div class="absolute right-0 top-0 h-72 w-72 rounded-full bg-purple-300/20 blur-3xl"></div>
                <div class="absolute bottom-0 left-8 h-32 w-32 rounded-full bg-emerald-200/10 blur-2xl"></div>
                <div class="relative flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-3xl">
                        <span class="inline-flex items-center rounded-full bg-white/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.24em] text-purple-100 ring-1 ring-white/15">PDF · anúncios · artigos · oportunidades validadas</span>
                        <h1 class="mt-5 text-3xl font-black tracking-tight sm:text-4xl lg:text-5xl">PDF Oportunidades</h1>
                        <p class="mt-4 max-w-2xl text-sm leading-6 text-purple-50/80 sm:text-base">Pesquisa focada só em produtos digitais em PDF: e-books, planners, apostilas, moldes, checklists e guias com sinais públicos de demanda para criar ofertas próprias e anúncios similares.</p>
                    </div>
                    <button type="button" class="rounded-2xl bg-white px-5 py-3 text-sm font-black uppercase tracking-wide text-[#17110d] shadow-lg transition hover:bg-purple-50" @click="refreshResearch">Atualizar pesquisa</button>
                </div>
            </div>
        </section>

        <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div v-for="card in [
                ['Oportunidades', summary.totalOpportunities, 'nichos de PDF mapeados', 'text-slate-900'],
                ['Prioridade alta', summary.priorityOpportunities, 'score proxy acima de 86', 'text-emerald-600'],
                ['Benchmarks', summary.totalAdBenchmarks, 'anúncios públicos analisados', 'text-purple-700'],
                ['Score médio', summary.averageScore, 'facilidade + sinais de mercado', 'text-amber-600'],
                ['Atualizado', summary.scrapedAt, 'curadoria pública', 'text-slate-900'],
            ]" :key="card[0]" class="rounded-2xl bg-white p-5 shadow-lg shadow-slate-200/80">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ card[0] }}</p>
                <p class="mt-2 text-3xl font-black" :class="card[3]">{{ card[1] }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ card[2] }}</p>
            </div>
        </section>

        <section class="mt-6 grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
            <div class="rounded-3xl border border-purple-100 bg-purple-50 p-5 text-sm leading-6 text-purple-950">
                <p class="font-bold">Como ler esta tela</p>
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
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-400">Radar PDF</p>
                    <h2 class="mt-1 text-2xl font-black text-slate-900">Oportunidades mais vantajosas</h2>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <select v-model="selectedNiche" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700">
                        <option v-for="niche in nicheFilters" :key="niche">{{ niche }}</option>
                    </select>
                    <select v-model="selectedScore" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700">
                        <option v-for="score in scoreFilters" :key="score">{{ score }}</option>
                    </select>
                </div>
            </div>

            <div class="mt-6 grid gap-5 xl:grid-cols-2">
                <article v-for="item in filteredOpportunities" :key="item.fingerprint" class="rounded-3xl border border-slate-100 bg-[#fffdfa] p-5">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-3 py-1 text-xs font-black uppercase" :class="scoreClass(item.scoreLabel)">{{ item.scoreLabel }}</span>
                                <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-500 ring-1 ring-slate-200">{{ item.difficultyLabel }}</span>
                                <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-500 ring-1 ring-slate-200">{{ item.priceBand }}</span>
                            </div>
                            <h3 class="mt-3 text-lg font-black text-slate-900">{{ item.title }}</h3>
                            <p class="mt-1 text-xs font-bold uppercase tracking-wide text-purple-700">{{ item.niche }}</p>
                            <p class="mt-3 text-sm leading-6 text-slate-600">{{ item.promise }}</p>
                        </div>
                        <div class="rounded-2xl bg-white p-4 text-center shadow-sm">
                            <p class="text-3xl font-black text-slate-900">{{ item.score }}</p>
                            <p class="text-xs font-bold uppercase text-slate-400">score</p>
                        </div>
                    </div>

                    <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                        <div class="rounded-2xl bg-white p-3"><dt class="text-xs font-bold uppercase text-slate-400">Formato</dt><dd class="mt-1 font-semibold text-slate-800">{{ item.pdfFormat }}</dd></div>
                        <div class="rounded-2xl bg-white p-3"><dt class="text-xs font-bold uppercase text-slate-400">Público</dt><dd class="mt-1 font-semibold text-slate-800">{{ item.audience }}</dd></div>
                        <div class="rounded-2xl bg-white p-3"><dt class="text-xs font-bold uppercase text-slate-400">Mecanismo</dt><dd class="mt-1 font-semibold text-slate-800">{{ item.conversionMechanism }}</dd></div>
                        <div class="rounded-2xl bg-white p-3"><dt class="text-xs font-bold uppercase text-slate-400">Ângulo de anúncio</dt><dd class="mt-1 font-semibold text-slate-800">{{ item.adAngle }}</dd></div>
                    </dl>

                    <div class="mt-5 rounded-2xl bg-white p-4 text-sm leading-6 text-slate-600">
                        <p class="font-bold text-slate-900">Criativo similar recomendado</p>
                        <p class="mt-1">{{ item.creativeSuggestion }}</p>
                        <p class="mt-3 font-bold text-slate-900">Funil sugerido</p>
                        <p class="mt-1">{{ item.funnelSuggestion }}</p>
                    </div>

                    <div class="mt-5 grid gap-3 lg:grid-cols-2">
                        <div class="rounded-2xl bg-white p-4">
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Benchmarks Meta</p>
                            <div class="mt-3 space-y-3">
                                <div v-for="ad in item.adBenchmarks" :key="ad.creativeId" class="rounded-xl border border-slate-100 p-3 text-xs leading-5 text-slate-600">
                                    <p class="font-bold text-slate-900">{{ ad.advertiser }} · {{ ad.activeDays || 'buscar' }} dias</p>
                                    <p class="mt-1">{{ ad.hook }}</p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <a :href="ad.adLibraryUrl" target="_blank" rel="noopener" class="rounded-lg bg-slate-950 px-3 py-1 font-bold text-white">Meta</a>
                                        <a v-if="ad.landingPageUrl" :href="ad.landingPageUrl" target="_blank" rel="noopener" class="rounded-lg bg-purple-50 px-3 py-1 font-bold text-purple-700">{{ ad.landingPageDomain }}</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-2xl bg-white p-4">
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Artigos/fontes e riscos</p>
                            <ul class="mt-3 space-y-2 text-xs font-semibold text-slate-600">
                                <li v-for="source in item.articleSources" :key="source.url"><a :href="source.url" target="_blank" rel="noopener" class="text-purple-700 hover:text-purple-900">{{ source.label }}</a></li>
                            </ul>
                            <ul class="mt-4 list-disc space-y-1 pl-4 text-xs leading-5 text-slate-500">
                                <li v-for="risk in item.risks" :key="risk">{{ risk }}</li>
                            </ul>
                            <a :href="item.sourceSearchUrl" target="_blank" rel="noopener" class="mt-4 inline-flex rounded-xl bg-slate-100 px-4 py-2 text-xs font-black uppercase tracking-wide text-slate-700 hover:bg-slate-200">Abrir busca Meta</a>
                        </div>
                    </div>
                </article>
            </div>
        </section>
    </AdminLayout>
</template>
