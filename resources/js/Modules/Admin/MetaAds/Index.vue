<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import { h, ref } from 'vue';
import EspelhoMeta from './EspelhoMeta.vue';
import { formatarData, formatarReais, STATUS_ESTILOS } from './metaAds';

const props = defineProps({
    campanhas: { type: Array, default: () => [] },
    config: { type: Object, required: true },
    periodo: { type: String, default: 'maximum' },
    periodos: { type: Object, default: () => ({}) },
    // Prop adiada: estrutura e métricas lidas ao vivo da Meta.
    espelho: { type: Object, default: null },
});

const carregando = ref(false);
const trocarPeriodo = (periodo) => router.get('/admin/publicidade/meta-ads/campanhas', periodo === 'maximum' ? {} : { periodo }, {
    only: ['espelho', 'periodo'],
    preserveScroll: true,
    preserveState: true,
    onStart: () => { carregando.value = true; },
    onFinish: () => { carregando.value = false; },
});

const { can } = usePermissions();

const periodo = (c) => (c.fim ? `${formatarData(c.inicio)} a ${formatarData(c.fim)}` : `A partir de ${formatarData(c.inicio)}`);

const columns = [
    {
        accessorKey: 'nome',
        header: 'Campanha',
        cell: ({ row }) => h('div', [
            h(Link, { href: `/admin/publicidade/meta-ads/campanhas/${row.original.id}`, class: 'font-semibold text-primary hover:underline' }, () => row.original.nome),
            h('div', { class: 'text-xs text-slate-500' }, row.original.objetivo_rotulo),
        ]),
    },
    {
        id: 'status',
        header: 'Status',
        accessorFn: (row) => row.status_rotulo,
        cell: ({ row }) => h('span', {
            class: `inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold ${STATUS_ESTILOS[row.original.status] ?? ''}`,
            title: row.original.ultimo_erro ?? undefined,
        }, row.original.status_rotulo),
    },
    { accessorKey: 'orcamento_diario', header: 'Orçamento/dia', cell: ({ row }) => formatarReais(row.original.orcamento_diario) },
    { id: 'periodo', header: 'Período', accessorFn: (row) => periodo(row) },
    { id: 'estrutura', header: 'Conjuntos · anúncios', accessorFn: (row) => `${row.conjuntos} · ${row.videos}` },
    { id: 'criada_por', header: 'Criada por', accessorFn: (row) => row.criada_por ?? '-' },
    {
        id: 'acoes',
        header: '',
        enableSorting: false,
        cell: ({ row }) => (can('financeiro.create') && row.original.novo_conjunto
            ? h(Link, {
                href: `/admin/publicidade/meta-ads/campanhas/${row.original.id}/conjuntos/novo`,
                class: 'inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-primary/40 px-2 py-1 text-xs font-medium text-primary hover:bg-primary/10',
            }, () => [h('i', { class: 'fas fa-plus text-[10px]', 'aria-hidden': 'true' }), ' Novo conjunto'])
            : null),
    },
];
</script>

<template>
    <Head title="Campanhas Meta" />

    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-2xl font-bold">
                <span class="flex items-center gap-3">
                    <i class="fab fa-meta text-[#0866FF]" aria-hidden="true"></i>
                    Meta
                </span>
                <span class="text-base font-normal text-slate-500 dark:text-slate-400">campanhas no Facebook e Instagram</span>
            </h1>
            <div class="flex flex-wrap gap-2">
                <Link href="/admin/publicidade/meta-ads"
                    class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]">
                    <i class="fas fa-chart-line text-xs" aria-hidden="true"></i> Gasto e resultados
                </Link>
                <Link v-if="can('financeiro.create')" href="/admin/publicidade/meta-ads/campanhas/nova"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                    <i class="fas fa-plus text-xs" aria-hidden="true"></i> Nova campanha
                </Link>
            </div>
        </div>

        <div v-if="!props.config.configurado" class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="status">
            <strong>Meta não configurada.</strong>
            Dá para montar rascunhos e subir os vídeos, mas o envio fica desligado até preencher no servidor:
            {{ props.config.faltando.join(', ') }}.
        </div>

        <section v-if="props.config.configurado" class="mb-8" aria-labelledby="na-meta-titulo">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="na-meta-titulo" class="text-lg font-bold">Na Meta agora</h2>
                    <p class="text-sm text-slate-500">Campanhas, conjuntos e anúncios como estão no Gerenciador, com os números da própria Meta.</p>
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
            <Deferred data="espelho">
                <template #fallback>
                    <div class="flex items-center gap-2 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 text-sm text-slate-500" role="status" aria-live="polite">
                        <i class="fas fa-circle-notch animate-spin text-primary" aria-hidden="true"></i> Consultando a Meta…
                    </div>
                </template>
                <p v-if="props.espelho?.erro" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
                    Não deu para ler a Meta agora: {{ props.espelho.erro }}
                </p>
                <EspelhoMeta v-else :campanhas="props.espelho?.campanhas ?? []" :acoes="can('financeiro.create')" :class="{ 'opacity-60': carregando }" />
            </Deferred>
        </section>

        <h2 class="mb-1 text-lg font-bold">Montadas no KazaKora</h2>
        <p class="mb-4 text-sm text-slate-500">
            Monte a campanha como no Gerenciador da Meta: vários conjuntos e vários anúncios em cada, de uma vez.
            Tudo vai para a Meta <strong>pausado</strong>; só começa a gastar quando você clicar em Ativar.
        </p>

        <DataTable
            :columns="columns"
            :data="props.campanhas"
            search-placeholder="Buscar campanha..."
            empty-message="Nenhuma campanha ainda."
        />
    </AdminLayout>
</template>
