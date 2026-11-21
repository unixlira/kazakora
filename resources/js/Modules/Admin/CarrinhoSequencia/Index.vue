<script setup>
// Sequência de carrinho abandonado (pedido do Lira 21/11/2026): D+1, D+3, D+5,
// D+7, D+20 e D+30, WhatsApp e e-mail, sem desconto. Só leitura: quem manda é
// o comando loja:carrinho-abandonado-sequencia.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import { maskPhone } from '@/Shared/useMasks';
import { useServerTable } from '@/Shared/useServerTable';
import { Head, Link } from '@inertiajs/vue3';
import { computed, h } from 'vue';

const props = defineProps({
    registros: { type: Object, default: () => ({ data: [] }) },
    resumo: { type: Object, default: () => ({ ativos: 0, compraram: 0, pediram_parar: 0, na_hora: 0 }) },
    sites: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const { visit } = useServerTable('/admin/carrinhos-abandonados/sequencia', {
    search: props.filters.search ?? null,
    status: props.filters.status ?? null,
    site: props.filters.site ?? null,
});

const linhas = computed(() => props.registros?.data ?? []);
const nomeDoSite = (id) => props.sites.find((s) => s.id === id)?.nome ?? id;
const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
const formatDate = (value) => (value
    ? new Date(value).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
    : '—');

const motivos = {
    comprou: { label: 'Comprou', tone: 'green' },
    pediu_parar: { label: 'Pediu para parar', tone: 'red' },
    sem_contato: { label: 'Sem contato', tone: 'gray' },
    sem_promocoes: { label: 'Saiu das promoções', tone: 'gray' },
    fim: { label: 'Sequência completa', tone: 'blue' },
};

const abas = [
    { label: 'Todos', value: 'all' },
    { label: 'Em andamento', value: 'ativo' },
    { label: 'Parados', value: 'parado' },
];

const columns = [
    {
        id: 'cliente',
        header: 'Cliente',
        enableSorting: false,
        cell: ({ row }) => h('div', { class: 'text-sm' }, [
            h('div', { class: 'font-medium' }, row.original.nome ?? 'Sem nome'),
            h('div', { class: 'text-xs text-slate-400' }, row.original.email ?? '—'),
            h('div', { class: 'text-xs text-slate-400' }, row.original.telefone ? maskPhone(row.original.telefone.replace(/^55/, '')) : '—'),
        ]),
    },
    {
        id: 'produto',
        header: 'Produto',
        enableSorting: false,
        cell: ({ row }) => h('div', { class: 'text-xs' }, [
            h('div', { class: 'font-medium' }, row.original.produto ?? '—'),
            h('div', { class: 'text-slate-400' }, `${formatPrice(row.original.subtotal)} · ${nomeDoSite(row.original.site)}`),
        ]),
    },
    {
        id: 'disparos',
        header: 'Disparos',
        enableSorting: false,
        cell: ({ row }) => h('div', { class: 'text-xs' }, [
            h('div', { class: 'font-semibold tabular-nums' }, `${row.original.disparos} de ${row.original.total_disparos}`),
            h('div', { class: 'text-slate-400' }, row.original.ultimo ? `último ${formatDate(row.original.ultimo)}` : 'nenhum ainda'),
            h('ul', { class: 'mt-1 space-y-0.5 text-slate-500' }, (row.original.historico ?? []).filter((l) => l.canal).map((l, i) => h('li', { key: i },
                `${l.numero}º ${l.canal}: ${l.status}${l.erro ? ` (${l.erro.slice(0, 60)})` : ''}`))),
        ]),
    },
    {
        id: 'proximo',
        header: 'Próximo disparo',
        enableSorting: false,
        meta: { cellClass: 'whitespace-nowrap' },
        cell: ({ row }) => h('div', { class: 'text-xs' }, row.original.parado ? '—' : formatDate(row.original.proximo)),
    },
    {
        id: 'status',
        header: 'Situação',
        enableSorting: false,
        cell: ({ row }) => (row.original.parado
            ? h(StatusBadge, { status: row.original.motivo, tone: motivos[row.original.motivo]?.tone ?? 'gray', label: motivos[row.original.motivo]?.label ?? row.original.motivo })
            : h(StatusBadge, { status: 'ativo', tone: 'yellow', label: 'Em andamento' })),
    },
    {
        id: 'quando',
        header: 'Abandonado em',
        enableSorting: false,
        meta: { cellClass: 'whitespace-nowrap' },
        cell: ({ row }) => h('div', { class: 'text-xs' }, [
            h('div', {}, formatDate(row.original.abandonado_em)),
            h('div', { class: 'text-slate-400' }, row.original.origem === 'lead_checkout' ? 'checkout' : 'carrinho'),
        ]),
    },
];
</script>

<template>
    <Head title="Sequência de carrinho abandonado" />

    <AdminLayout>
        <h1 class="mb-2 text-2xl font-bold">Sequência de carrinho abandonado</h1>
        <p class="mb-4 text-sm text-slate-500">
            Disparos por WhatsApp e e-mail em D+1, D+3, D+5, D+7, D+20 e D+30, das 9h às 20h, sem desconto. Para quando o
            cliente compra, pede para parar ou sai das promoções. Só consulta: quem manda é o comando
            <code>loja:carrinho-abandonado-sequencia</code>.
            <Link href="/admin/carrinhos-abandonados" class="text-sky-700 hover:underline">Ver os carrinhos do checkout</Link>.
        </p>

        <div class="mb-4 grid gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Em andamento</div>
                <div class="text-2xl font-bold tabular-nums">{{ resumo.ativos }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Disparo na hora</div>
                <div class="text-2xl font-bold tabular-nums">{{ resumo.na_hora }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Compraram</div>
                <div class="text-2xl font-bold tabular-nums">{{ resumo.compraram }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Pediram para parar</div>
                <div class="text-2xl font-bold tabular-nums">{{ resumo.pediram_parar }}</div>
            </div>
        </div>

        <div class="mb-3 flex flex-wrap items-center gap-2">
            <label class="text-sm text-slate-600" for="filtro-site">Loja</label>
            <select id="filtro-site" class="rounded-md border border-slate-300 px-2 py-1 text-sm" :value="filters.site ?? ''" @change="visit({ site: $event.target.value || null })">
                <option value="">Todas</option>
                <option v-for="s in sites" :key="s.id" :value="s.id">{{ s.nome }}</option>
            </select>
        </div>

        <DataTable
            :columns="columns"
            :data="linhas"
            :paginator="registros"
            :search="filters.search ?? ''"
            :filter-tabs="abas"
            :initial-active-tab="filters.status ?? 'all'"
            dense
            search-placeholder="Buscar por nome, e-mail ou telefone..."
            empty-message="Nenhum carrinho na sequência ainda."
            @update:search="visit({ search: $event })"
            @update:active-tab="visit({ status: $event === 'all' ? null : $event })"
        />
    </AdminLayout>
</template>
