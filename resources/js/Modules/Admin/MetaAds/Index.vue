<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link, router } from '@inertiajs/vue3';
import { h } from 'vue';
import { formatarData, formatarReais, STATUS_ESTILOS } from './metaAds';

const props = defineProps({
    campanhas: { type: Array, default: () => [] },
    config: { type: Object, required: true },
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
