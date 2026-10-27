<script setup>
// Metadados de uma campanha da Meta (pedido do Lira em 2026-10-27): os
// conjuntos, os anúncios de cada um e a performance de cada anúncio, dia
// a dia, lidos do nosso banco.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable } from '@/Shared/Components/DataTable';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, h, nextTick, ref } from 'vue';
import { formatarData, formatarReais, statusMeta } from '../metaAds';
import { colunaStatus, colunasDeMetricas, roas } from './metricas';

const props = defineProps({
    campanha: { type: Object, required: true },
    conjuntos: { type: Array, default: () => [] },
    anuncios: { type: Array, default: () => [] },
    diario: { type: Object, default: () => ({}) },
    periodo: { type: String, default: 'maximum' },
    periodos: { type: Object, default: () => ({}) },
    intervalo: { type: Object, default: () => ({}) },
    atualizadoEm: { type: String, default: null },
});

const c = computed(() => props.campanha);
const voltar = computed(() => `/admin/publicidade/meta-ads/metadados${props.periodo === 'maximum' ? '' : `?periodo=${props.periodo}`}`);

const carregando = ref(false);
const trocarPeriodo = (periodo) => router.get(`/admin/publicidade/meta-ads/metadados/${c.value.meta_id}`, periodo === 'maximum' ? {} : { periodo }, {
    preserveScroll: true,
    preserveState: true,
    onStart: () => { carregando.value = true; },
    onFinish: () => { carregando.value = false; },
});

// Clicar no conjunto mostra só os anúncios dele.
const conjuntoEscolhido = ref('all');
const abasConjuntos = computed(() => [
    { label: 'Todos os conjuntos', value: 'all' },
    ...props.conjuntos.map((s) => ({ label: s.nome, value: s.meta_id })),
]);
const anunciosVisiveis = computed(() => (conjuntoEscolhido.value === 'all'
    ? props.anuncios
    : props.anuncios.filter((a) => a.conjunto_meta_id === conjuntoEscolhido.value)));
const verAnunciosDo = async (s) => {
    conjuntoEscolhido.value = s.meta_id;
    await nextTick();
    document.getElementById('anuncios-titulo')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

// Clicar no anúncio abre a performance dele dia a dia.
const anuncioAberto = ref(null);
const abrirAnuncio = async (a) => {
    anuncioAberto.value = a;
    await nextTick();
    document.getElementById('diario-titulo')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};
const diasDoAnuncio = computed(() => (anuncioAberto.value ? (props.diario[anuncioAberto.value.meta_id] ?? []).map((d) => ({ ...d, metricas: d })) : []));

const colunasConjuntos = [
    {
        accessorKey: 'nome',
        header: 'Conjunto',
        cell: ({ row }) => h('button', {
            type: 'button',
            class: 'min-w-[12rem] text-left font-semibold text-primary hover:underline',
            title: 'Ver os anúncios deste conjunto',
            onClick: () => verAnunciosDo(row.original),
        }, row.original.nome),
    },
    colunaStatus(),
    { accessorKey: 'anuncios', header: 'Anúncios', meta: { cellClass: 'text-right' } },
    { id: 'orcamento', header: 'Orçamento/dia', accessorFn: (row) => row.orcamento_diario ?? -1, cell: ({ row }) => formatarReais(row.original.orcamento_diario), meta: { cellClass: 'whitespace-nowrap text-right' } },
    ...colunasDeMetricas(),
];

const colunasAnuncios = [
    {
        accessorKey: 'nome',
        header: 'Anúncio',
        cell: ({ row }) => h('div', { class: 'flex min-w-[14rem] items-center gap-3' }, [
            row.original.miniatura
                ? h('img', { src: row.original.miniatura, alt: '', loading: 'lazy', class: 'h-10 w-10 shrink-0 rounded object-cover' })
                : h('div', { class: 'h-10 w-10 shrink-0 rounded bg-[var(--surface-muted)]', 'aria-hidden': 'true' }),
            h('div', [
                h('button', {
                    type: 'button',
                    class: 'text-left font-semibold text-primary hover:underline',
                    title: 'Ver a performance dia a dia',
                    onClick: () => abrirAnuncio(row.original),
                }, row.original.nome),
                h('div', { class: 'text-xs text-slate-500' }, row.original.conjunto),
            ]),
        ]),
    },
    colunaStatus(),
    ...colunasDeMetricas(),
];

const colunasDiario = [
    { accessorKey: 'data', header: 'Dia', cell: ({ row }) => formatarData(row.original.data), meta: { cellClass: 'whitespace-nowrap' } },
    ...colunasDeMetricas(),
];

const m = computed(() => c.value.metricas);
const cartoes = computed(() => [
    ['Gasto', formatarReais(m.value.gasto)],
    ['Cliques no link', String(m.value.cliques_link)],
    ['Compras', String(m.value.compras)],
    ['Vendas', formatarReais(m.value.vendas)],
    ['ROAS', roas(m.value.roas)],
]);
const [rotuloStatus, coresStatus] = statusMeta(props.campanha.status);
</script>

<template>
    <Head :title="`Metadados: ${c.nome}`" />

    <AdminLayout>
        <Link :href="voltar" class="mb-3 inline-flex items-center gap-2 text-sm text-primary hover:underline">
            <i class="fas fa-arrow-left text-xs" aria-hidden="true"></i> Metadados das campanhas
        </Link>

        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex flex-wrap items-center gap-3 text-2xl font-bold">
                    <i class="fab fa-meta text-[#0866FF]" aria-hidden="true"></i>
                    {{ c.nome }}
                    <span :class="['rounded-full px-2.5 py-1 text-xs font-bold', coresStatus]">{{ rotuloStatus }}</span>
                </h1>
                <p class="text-sm text-slate-500">
                    {{ [c.objetivo, c.inicio ? `desde ${formatarData(c.inicio)}` : null, c.fim ? `até ${formatarData(c.fim)}` : null,
                        c.orcamento_diario ? `${formatarReais(c.orcamento_diario)}/dia` : null].filter(Boolean).join(' · ') }}
                </p>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <span class="text-slate-500">Período</span>
                <select :value="props.periodo" :disabled="carregando"
                    class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-1.5 text-sm"
                    @change="trocarPeriodo($event.target.value)">
                    <option v-for="(rotulo, chave) in props.periodos" :key="chave" :value="chave">{{ rotulo }}</option>
                </select>
                <i v-if="carregando" class="fas fa-circle-notch animate-spin text-primary" aria-hidden="true"></i>
            </label>
        </div>

        <dl class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-5">
            <div v-for="[rotulo, valor] in cartoes" :key="rotulo" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4">
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ rotulo }}</dt>
                <dd class="mt-1 text-xl font-bold">{{ valor }}</dd>
            </div>
        </dl>

        <div :class="{ 'opacity-60': carregando }">
            <h2 class="mb-2 text-lg font-bold">Conjuntos de anúncios</h2>
            <DataTable dense :columns="colunasConjuntos" :data="props.conjuntos" row-key="meta_id" hide-search
                :show-pagination="props.conjuntos.length > 10" empty-message="Nenhum conjunto gravado." />

            <h2 id="anuncios-titulo" class="mb-2 mt-8 scroll-mt-4 text-lg font-bold">Anúncios</h2>
            <DataTable :key="conjuntoEscolhido" dense :columns="colunasAnuncios" :data="anunciosVisiveis" row-key="meta_id"
                :filter-tabs="props.conjuntos.length > 1 ? abasConjuntos : []" :initial-active-tab="conjuntoEscolhido"
                search-placeholder="Buscar anúncio..." empty-message="Nenhum anúncio gravado."
                @update:active-tab="conjuntoEscolhido = $event" />

            <section v-if="anuncioAberto" class="mt-8" aria-labelledby="diario-titulo">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h2 id="diario-titulo" class="scroll-mt-4 text-lg font-bold">Dia a dia: {{ anuncioAberto.nome }}</h2>
                    <button type="button" class="text-sm text-slate-500 hover:underline" @click="anuncioAberto = null">Fechar</button>
                </div>
                <DataTable dense :columns="colunasDiario" :data="diasDoAnuncio" row-key="data" hide-search
                    empty-message="Sem gasto nem impressão neste período." />
            </section>
        </div>
    </AdminLayout>
</template>
