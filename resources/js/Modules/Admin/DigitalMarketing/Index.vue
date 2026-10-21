<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
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
const selectedEvidence = ref('Todos');
const regionFilters = computed(() => ['Todas', ...new Set(props.mappedCreatives.map((item) => item.region).filter(Boolean))]);
const typeFilters = computed(() => ['Todos', ...new Set(props.mappedCreatives.map((item) => item.type).filter(Boolean))]);
const evidenceFilters = ['Todos', 'Score 90+', 'Tem página', 'Tem conteúdo/PDF', 'Tem Meta secundária'];
const hasLinkKind = (item, kinds) => (item.accessLinks || []).some((link) => kinds.includes(link.kind));
const filteredCreatives = computed(() => props.mappedCreatives.filter((item) => {
    const evidenceOk = selectedEvidence.value === 'Todos'
        || (selectedEvidence.value === 'Score 90+' && Number(item.opportunityScore || 0) >= 90)
        || (selectedEvidence.value === 'Tem página' && hasLinkKind(item, ['conversion_page', 'product_page', 'checkout']))
        || (selectedEvidence.value === 'Tem conteúdo/PDF' && hasLinkKind(item, ['sample_pdf', 'content_source', 'product_preview', 'official_source', 'checkout']))
        || (selectedEvidence.value === 'Tem Meta secundária' && hasLinkKind(item, ['ad_archive']));

    return (selectedRegion.value === 'Todas' || item.region === selectedRegion.value)
        && (selectedType.value === 'Todos' || item.type === selectedType.value)
        && evidenceOk;
}));
const refreshResearch = () => router.post('/admin/mkt-digital/atualizar', {}, { preserveScroll: true });
const formatNumber = (value) => Number(value ?? 0).toLocaleString('pt-BR');
</script>

<template>
    <Head title="Radar de criativos" />
    <AdminLayout>
        <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">Radar de criativos</h1>
            <button type="button" class="rounded-lg bg-[var(--primary-solido)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" @click="refreshResearch">
                <i class="fas fa-rotate-right mr-1" aria-hidden="true"></i> Atualizar radar
            </button>
        </div>
        <p class="mb-6 max-w-3xl text-sm text-slate-500 dark:text-slate-400">
            Criativos que vendem lá fora, produtos digitais para modelar e onde testar checkout por região com o dinheiro voltando para o Brasil.
        </p>

        <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
            <CardStats compact stat-subtitle="Criativos mapeados" :stat-title="formatNumber(summary.mappedCreatives)" stat-icon-name="fas fa-image" variant="primary" />
            <CardStats compact stat-subtitle="PDFs acima do corte" :stat-title="formatNumber(summary.pdfOpportunities)" stat-icon-name="fas fa-file-pdf" variant="secondary" />
            <CardStats compact stat-subtitle="Evidências (página/PDF)" :stat-title="formatNumber(summary.verifiedEvidenceLinks)" stat-icon-name="fas fa-link" variant="success" />
            <CardStats compact stat-subtitle="Score mínimo" :stat-title="String(summary.minimumScore || 82)" stat-icon-name="fas fa-filter" variant="warning" />
            <CardStats compact stat-subtitle="Regiões de checkout" :stat-title="formatNumber(summary.paymentRegions)" stat-icon-name="fas fa-earth-americas" variant="info" />
            <CardStats compact stat-subtitle="Atualizado" :stat-title="summary.lastScanAt || '—'" stat-icon-name="fas fa-clock" variant="neutral" />
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 text-sm shadow-sm">
                <h2 class="text-base font-semibold">Regra operacional</h2>
                <ul class="mt-2 list-disc space-y-1.5 pl-4 text-slate-600 dark:text-slate-300">
                    <li v-for="rule in operatingRules" :key="rule">{{ rule }}</li>
                </ul>
            </section>
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 text-sm shadow-sm">
                <h2 class="text-base font-semibold">Status da fonte</h2>
                <p class="mt-2 text-slate-600 dark:text-slate-300"><span class="font-semibold">{{ providerStatus.name }}:</span> {{ providerStatus.status }}</p>
                <p class="mt-1 text-slate-500 dark:text-slate-400">{{ providerStatus.detail }}</p>
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Atualiza sozinho todo dia às 03:20 (digital-marketing:scan).</p>
            </section>
        </div>

        <section class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="flex flex-col gap-3 border-b border-[var(--surface-border)] px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-base font-semibold">Criativos já mapeados</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Modelos para adaptar sem copiar. {{ filteredCreatives.length }} de {{ mappedCreatives.length }}.</p>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <select v-model="selectedRegion" aria-label="Região" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-1.5 text-sm">
                        <option v-for="region in regionFilters" :key="region">{{ region }}</option>
                    </select>
                    <select v-model="selectedType" aria-label="Tipo" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-1.5 text-sm">
                        <option v-for="type in typeFilters" :key="type">{{ type }}</option>
                    </select>
                    <select v-model="selectedEvidence" aria-label="Evidência" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-1.5 text-sm">
                        <option v-for="filter in evidenceFilters" :key="filter">{{ filter }}</option>
                    </select>
                </div>
            </div>

            <p v-if="!filteredCreatives.length" class="px-4 py-6 text-sm text-slate-500 dark:text-slate-400">Nenhum criativo com esses filtros.</p>
            <div v-else class="grid grid-cols-1 gap-4 p-4 xl:grid-cols-2">
                <article v-for="creative in filteredCreatives" :key="creative.id" class="rounded-lg border border-[var(--surface-border)] p-4">
                    <div class="flex flex-wrap items-center gap-1.5 text-xs font-semibold">
                        <span class="rounded-full bg-lightprimary px-2.5 py-0.5 text-primary">#{{ creative.opportunityRank || creative.rank }}</span>
                        <span class="rounded-full bg-lightsuccess px-2.5 py-0.5 text-success">Score {{ creative.opportunityScore || '—' }}</span>
                        <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ creative.type }}</span>
                        <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ creative.region }}</span>
                        <span v-if="creative.activeDays" class="rounded-full bg-lightwarning px-2.5 py-0.5 text-warning">{{ creative.activeDays }} dias ativo</span>
                        <span class="rounded-full bg-lightinfo px-2.5 py-0.5 text-info">{{ creative.evidenceCount }} links úteis</span>
                    </div>
                    <h3 class="mt-3 font-semibold">{{ creative.title }}</h3>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ creative.brand }} · {{ creative.market }} · {{ creative.priceBand }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Entregável: {{ creative.deliverable }}</p>
                    <p class="mt-3 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ creative.hook }}</p>
                    <p class="mt-3 rounded-lg bg-slate-50 p-3 text-sm font-medium dark:bg-slate-800/60">{{ creative.action }}</p>
                    <p v-if="creative.auditNote" class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ creative.auditNote }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a v-for="link in creative.accessLinks" :key="`${creative.id}-${link.url}-${link.label}`" :href="link.url" target="_blank" rel="noopener" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs font-semibold transition hover:border-primary" :class="link.kind === 'ad_archive' ? 'text-slate-500 dark:text-slate-400' : 'text-primary'">{{ link.label }}</a>
                    </div>
                </article>
            </div>
        </section>

        <div class="mb-6 grid grid-cols-1 gap-4 xl:grid-cols-2">
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                <div class="border-b border-[var(--surface-border)] px-4 py-3">
                    <h2 class="text-base font-semibold">Onde testar primeiro</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Regiões e checkout.</p>
                </div>
                <div class="space-y-3 p-4">
                    <article v-for="region in regionPlaybooks" :key="region.region" class="rounded-lg border border-[var(--surface-border)] p-4 text-sm">
                        <h3 class="font-semibold">{{ region.region }}</h3>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ region.countries }}</p>
                        <p class="mt-2 text-slate-600 dark:text-slate-300"><span class="font-semibold">Primeiro teste:</span> {{ region.firstTest }}</p>
                        <p class="mt-1 text-slate-600 dark:text-slate-300"><span class="font-semibold">Checkout:</span> {{ region.checkout }}</p>
                        <p class="mt-1 text-slate-600 dark:text-slate-300"><span class="font-semibold">Reais:</span> {{ region.moneyBackToBrazil }}</p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <span v-for="angle in region.adAngles" :key="angle" class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ angle }}</span>
                        </div>
                    </article>
                </div>
            </section>

            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                <div class="border-b border-[var(--surface-border)] px-4 py-3">
                    <h2 class="text-base font-semibold">Matriz de recebimento</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Gateways e fontes.</p>
                </div>
                <div class="space-y-3 p-4">
                    <article v-for="gateway in paymentGateways" :key="gateway.region" class="rounded-lg border border-[var(--surface-border)] p-4 text-sm text-slate-600 dark:text-slate-300">
                        <h3 class="font-semibold">{{ gateway.region }} · prioridade {{ gateway.priority }}</h3>
                        <p class="mt-2"><span class="font-semibold">Gateway:</span> {{ gateway.gateway }}</p>
                        <p class="mt-1"><span class="font-semibold">Receber em reais:</span> {{ gateway.receivesInBrl }}</p>
                        <p class="mt-1"><span class="font-semibold">Melhor para:</span> {{ gateway.bestFor }}</p>
                        <p class="mt-1"><span class="font-semibold">Atenção:</span> {{ gateway.risk }}</p>
                    </article>
                </div>
            </section>
        </div>

        <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="border-b border-[var(--surface-border)] px-4 py-3">
                <h2 class="text-base font-semibold">Buscas vivas</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Links por país para a varredura manual.</p>
            </div>
            <div class="grid grid-cols-1 gap-3 p-4 md:grid-cols-2 xl:grid-cols-4">
                <a v-for="search in sourceSearches.slice(0, 32)" :key="`${search.countryCode}-${search.keyword}`" :href="search.url" target="_blank" rel="noopener" class="rounded-lg border border-[var(--surface-border)] p-3 text-sm transition hover:border-primary">
                    <span class="font-semibold">{{ search.country }}</span>
                    <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{{ search.keyword }}</span>
                </a>
            </div>
        </section>
    </AdminLayout>
</template>
