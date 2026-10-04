<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    summary: { type: Object, default: () => ({}) },
    mappedCreatives: { type: Array, default: () => [] },
    regionPlaybooks: { type: Array, default: () => [] },
    paymentGateways: { type: Array, default: () => [] },
    sourceSearches: { type: Array, default: () => [] },
    operatingRules: { type: Array, default: () => [] },
    providerStatus: { type: Object, default: () => ({}) },
});

const selectedRegion = ref('Todas');
const selectedType = ref('Todos');
const regionFilters = computed(() => ['Todas', ...new Set(props.mappedCreatives.map((item) => item.region).filter(Boolean))]);
const typeFilters = computed(() => ['Todos', ...new Set(props.mappedCreatives.map((item) => item.type).filter(Boolean))]);
const filteredCreatives = computed(() => props.mappedCreatives.filter((item) => (selectedRegion.value === 'Todas' || item.region === selectedRegion.value) && (selectedType.value === 'Todos' || item.type === selectedType.value)));
const refreshResearch = () => router.post('/admin/mkt-digital/atualizar', {}, { preserveScroll: true });
const formatNumber = (value) => Number(value ?? 0).toLocaleString('pt-BR');
</script>

<template>
    <Head title="MKT Digital" />
    <AdminLayout>
        <section class="overflow-hidden rounded-3xl bg-slate-950 text-white shadow-2xl shadow-slate-300/40">
            <div class="relative px-6 py-8 sm:px-8 lg:px-10">
                <div class="absolute right-0 top-0 h-72 w-72 rounded-full bg-fuchsia-400/20 blur-3xl"></div>
                <div class="absolute bottom-0 left-8 h-44 w-44 rounded-full bg-amber-300/10 blur-2xl"></div>
                <div class="relative flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-3xl">
                        <span class="inline-flex items-center rounded-full bg-white/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.24em] text-fuchsia-100 ring-1 ring-white/15">Radar global · PDFs · criativos · checkout</span>
                        <h1 class="mt-5 text-3xl font-black tracking-tight sm:text-4xl lg:text-5xl">MKT Digital</h1>
                        <p class="mt-4 max-w-2xl text-sm leading-6 text-slate-300 sm:text-base">Central para mapear criativos que vendem, modelar produtos digitais autorais e decidir onde testar checkout por região com possibilidade de trazer o dinheiro para o Brasil.</p>
                    </div>
                    <button type="button" class="rounded-2xl bg-white px-5 py-3 text-sm font-black uppercase tracking-wide text-slate-950 shadow-lg transition hover:bg-fuchsia-50" @click="refreshResearch">Atualizar radar</button>
                </div>
            </div>
        </section>

        <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div v-for="card in [
                ['Criativos', formatNumber(summary.mappedCreatives), 'benchmarks mapeados', 'text-slate-900'],
                ['PDFs', formatNumber(summary.pdfOpportunities), 'oportunidades autorais', 'text-fuchsia-700'],
                ['Regiões', formatNumber(summary.paymentRegions), 'matriz de checkout', 'text-amber-600'],
                ['Prioridade', formatNumber(summary.priorityRegions), 'regiões para começar', 'text-emerald-600'],
                ['Atualizado', summary.lastScanAt, 'cache/cron do radar', 'text-slate-900'],
            ]" :key="card[0]" class="rounded-2xl bg-white p-5 shadow-lg shadow-slate-200/80">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ card[0] }}</p>
                <p class="mt-2 text-3xl font-black" :class="card[3]">{{ card[1] }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ card[2] }}</p>
            </div>
        </section>

        <section class="mt-6 grid gap-4 lg:grid-cols-[1.1fr_0.9fr]">
            <div class="rounded-3xl border border-fuchsia-100 bg-fuchsia-50 p-5 text-sm leading-6 text-fuchsia-950">
                <p class="font-bold">Regra operacional</p>
                <ul class="mt-3 list-disc space-y-2 pl-4">
                    <li v-for="rule in operatingRules" :key="rule">{{ rule }}</li>
                </ul>
            </div>
            <div class="rounded-3xl border border-slate-200 bg-white p-5 text-sm leading-6 text-slate-600 shadow-lg shadow-slate-200/70">
                <p class="font-bold text-slate-900">Status da fonte</p>
                <p class="mt-2"><span class="font-semibold">{{ providerStatus.name }}:</span> {{ providerStatus.status }}</p>
                <p class="mt-1">{{ providerStatus.detail }}</p>
                <p class="mt-3 rounded-2xl bg-slate-50 p-3 text-xs font-semibold text-slate-500">Cron agendada: digital-marketing:scan todo dia 03:20.</p>
            </div>
        </section>

        <section class="mt-6 rounded-3xl bg-white p-5 shadow-xl shadow-slate-200/80">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-400">Criativos já mapeados</p>
                    <h2 class="mt-1 text-2xl font-black text-slate-900">Modelos para adaptar sem copiar</h2>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <select v-model="selectedRegion" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700">
                        <option v-for="region in regionFilters" :key="region">{{ region }}</option>
                    </select>
                    <select v-model="selectedType" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700">
                        <option v-for="type in typeFilters" :key="type">{{ type }}</option>
                    </select>
                </div>
            </div>

            <div class="mt-6 grid gap-4 xl:grid-cols-2">
                <article v-for="creative in filteredCreatives" :key="creative.id" class="rounded-3xl border border-slate-100 bg-[#fffdfa] p-5">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-slate-950 px-3 py-1 text-xs font-black uppercase text-white">{{ creative.type }}</span>
                        <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-500 ring-1 ring-slate-200">{{ creative.region }}</span>
                        <span v-if="creative.activeDays" class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">{{ creative.activeDays }} dias ativo</span>
                    </div>
                    <h3 class="mt-4 text-lg font-black text-slate-900">{{ creative.title }}</h3>
                    <p class="mt-1 text-xs font-bold uppercase tracking-wide text-fuchsia-700">{{ creative.brand }} · {{ creative.market }}</p>
                    <p class="mt-3 text-sm leading-6 text-slate-600 whitespace-pre-line">{{ creative.hook }}</p>
                    <p class="mt-4 rounded-2xl bg-white p-3 text-sm font-semibold leading-6 text-slate-700">{{ creative.action }}</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a v-if="creative.adLibraryUrl" :href="creative.adLibraryUrl" target="_blank" rel="noopener" class="rounded-xl bg-slate-950 px-4 py-2 text-xs font-black uppercase tracking-wide text-white">Meta</a>
                        <a v-if="creative.landingPageUrl" :href="creative.landingPageUrl" target="_blank" rel="noopener" class="rounded-xl bg-fuchsia-50 px-4 py-2 text-xs font-black uppercase tracking-wide text-fuchsia-700">{{ creative.landingPageDomain || 'Landing' }}</a>
                    </div>
                </article>
            </div>
        </section>

        <section class="mt-6 grid gap-5 xl:grid-cols-[1fr_1fr]">
            <div class="rounded-3xl bg-white p-5 shadow-xl shadow-slate-200/80">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-400">Regiões e checkout</p>
                <h2 class="mt-1 text-2xl font-black text-slate-900">Onde testar primeiro</h2>
                <div class="mt-5 space-y-4">
                    <article v-for="region in regionPlaybooks" :key="region.region" class="rounded-2xl border border-slate-100 p-4">
                        <h3 class="font-black text-slate-900">{{ region.region }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ region.countries }}</p>
                        <p class="mt-3 text-sm leading-6 text-slate-700"><span class="font-bold">Primeiro teste:</span> {{ region.firstTest }}</p>
                        <p class="mt-2 text-sm leading-6 text-slate-700"><span class="font-bold">Checkout:</span> {{ region.checkout }}</p>
                        <p class="mt-2 text-sm leading-6 text-slate-700"><span class="font-bold">Reais:</span> {{ region.moneyBackToBrazil }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span v-for="angle in region.adAngles" :key="angle" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{{ angle }}</span>
                        </div>
                    </article>
                </div>
            </div>

            <div class="rounded-3xl bg-white p-5 shadow-xl shadow-slate-200/80">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-400">Gateways e fontes</p>
                <h2 class="mt-1 text-2xl font-black text-slate-900">Matriz de recebimento</h2>
                <div class="mt-5 space-y-4">
                    <article v-for="gateway in paymentGateways" :key="gateway.region" class="rounded-2xl border border-slate-100 p-4 text-sm leading-6 text-slate-600">
                        <h3 class="font-black text-slate-900">{{ gateway.region }} · prioridade {{ gateway.priority }}</h3>
                        <p class="mt-2"><span class="font-bold text-slate-800">Gateway:</span> {{ gateway.gateway }}</p>
                        <p class="mt-1"><span class="font-bold text-slate-800">Receber em reais:</span> {{ gateway.receivesInBrl }}</p>
                        <p class="mt-1"><span class="font-bold text-slate-800">Melhor para:</span> {{ gateway.bestFor }}</p>
                        <p class="mt-1"><span class="font-bold text-slate-800">Atenção:</span> {{ gateway.risk }}</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="mt-6 rounded-3xl bg-white p-5 shadow-xl shadow-slate-200/80">
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-400">Buscas vivas</p>
            <h2 class="mt-1 text-2xl font-black text-slate-900">Links por país para a varredura manual</h2>
            <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <a v-for="search in sourceSearches.slice(0, 32)" :key="`${search.countryCode}-${search.keyword}`" :href="search.url" target="_blank" rel="noopener" class="rounded-2xl border border-slate-100 p-4 text-sm hover:border-fuchsia-200 hover:bg-fuchsia-50">
                    <span class="font-black text-slate-900">{{ search.country }}</span>
                    <span class="mt-1 block text-xs font-semibold text-slate-500">{{ search.keyword }}</span>
                </a>
            </div>
        </section>
    </AdminLayout>
</template>
