<script setup>
// Metadados das campanhas da Meta (pedido do Lira em 2026-10-27): todas as
// campanhas da conta, lidas do nosso banco (gravadas pela rotina
// meta-ads:gravar-dados). Clicar na campanha abre conjuntos e anúncios.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable } from '@/Shared/Components/DataTable';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, h, ref } from 'vue';
import { formatarData, formatarReais } from '../metaAds';
import { ABAS_STATUS, colunaStatus, colunasDeMetricas, filtrarPorAba, roas } from './metricas';

const props = defineProps({
    campanhas: { type: Array, default: () => [] },
    totais: { type: Object, required: true },
    periodo: { type: String, default: 'maximum' },
    periodos: { type: Object, default: () => ({}) },
    intervalo: { type: Object, default: () => ({}) },
    atualizadoEm: { type: String, default: null },
});

const aba = ref('all');
const linhas = computed(() => filtrarPorAba(props.campanhas, aba.value));

const carregando = ref(false);
const trocarPeriodo = (periodo) => router.get('/admin/publicidade/meta-ads/metadados', periodo === 'maximum' ? {} : { periodo }, {
    preserveScroll: true,
    preserveState: true,
    onStart: () => { carregando.value = true; },
    onFinish: () => { carregando.value = false; },
});

const link = (c) => `/admin/publicidade/meta-ads/metadados/${c.meta_id}${props.periodo === 'maximum' ? '' : `?periodo=${props.periodo}`}`;

const atualizado = computed(() => (props.atualizadoEm
    ? new Date(props.atualizadoEm).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Sao_Paulo' })
    : null));

const columns = [
    {
        accessorKey: 'nome',
        header: 'Campanha',
        cell: ({ row }) => h('div', { class: 'min-w-[14rem]' }, [
            h(Link, { href: link(row.original), class: 'font-semibold text-primary hover:underline' }, () => row.original.nome),
            h('div', { class: 'text-xs text-slate-500' }, [row.original.objetivo, row.original.inicio ? `desde ${formatarData(row.original.inicio)}` : null].filter(Boolean).join(' · ')),
        ]),
    },
    colunaStatus(),
    { id: 'estrutura', header: 'Conjuntos · anúncios', accessorFn: (row) => `${row.conjuntos} · ${row.anuncios}`, meta: { cellClass: 'whitespace-nowrap' } },
    { id: 'orcamento', header: 'Orçamento/dia', accessorFn: (row) => row.orcamento_diario ?? -1, cell: ({ row }) => formatarReais(row.original.orcamento_diario), meta: { cellClass: 'whitespace-nowrap text-right' } },
    ...colunasDeMetricas(),
];

const cartoes = computed(() => [
    ['Gasto', formatarReais(props.totais.gasto)],
    ['Compras', String(props.totais.compras)],
    ['Vendas', formatarReais(props.totais.vendas)],
    ['ROAS', roas(props.totais.roas)],
]);
</script>

<template>
    <Head title="Metadados das campanhas Meta" />

    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-3 text-2xl font-bold">
                    <i class="fab fa-meta text-[#0866FF]" aria-hidden="true"></i>
                    Metadados das campanhas
                </h1>
                <p class="text-sm text-slate-500">
                    Todas as campanhas da conta na Meta, gravadas no banco do KazaKora.
                    <template v-if="atualizado">Última atualização: {{ atualizado }}.</template>
                    <template v-else>Ainda sem dados gravados.</template>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-sm">
                    <span class="text-slate-500">Período</span>
                    <select :value="props.periodo" :disabled="carregando"
                        class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-1.5 text-sm"
                        @change="trocarPeriodo($event.target.value)">
                        <option v-for="(rotulo, chave) in props.periodos" :key="chave" :value="chave">{{ rotulo }}</option>
                    </select>
                </label>
                <i v-if="carregando" class="fas fa-circle-notch animate-spin text-primary" aria-hidden="true"></i>
                <Link href="/admin/publicidade/meta-ads/campanhas"
                    class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]">
                    <i class="fab fa-meta text-xs" aria-hidden="true"></i> Campanhas
                </Link>
            </div>
        </div>

        <dl class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
            <div v-for="[rotulo, valor] in cartoes" :key="rotulo" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4">
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ rotulo }}</dt>
                <dd class="mt-1 text-xl font-bold">{{ valor }}</dd>
            </div>
        </dl>

        <DataTable
            dense
            :columns="columns"
            :data="linhas"
            :filter-tabs="ABAS_STATUS"
            row-key="meta_id"
            search-placeholder="Buscar campanha..."
            empty-message="Nenhuma campanha gravada."
            :class="{ 'opacity-60': carregando }"
            @update:active-tab="aba = $event"
        />
    </AdminLayout>
</template>
